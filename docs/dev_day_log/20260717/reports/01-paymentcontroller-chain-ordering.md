# How the PaymentController class-extension chain order is resolved

**Date:** 2026-07-17
**Scope:** `oe_payments_mollie`, `oe_payments_stripe_wallet`, `oe_payments_paypal`,
`oe_onepage_checkout` all active together, plus `oxid-esales/oxideshop-ce` core module chaining.

## TL;DR

- OXID builds a **single linear class-extension chain per core class** (e.g. `PaymentController`).
  Multiple modules that `extend` the same core class are stitched into one inheritance line via
  generated `…_parent` class aliases.
- The **order** of that line comes from the merged active-module configuration, is persisted in
  `var/configuration/shops/1/class_extension_chain.yaml`, and is **regenerated on every**
  `oe:module:activate/deactivate`. It is *not* reliably controllable and does not follow simple
  activation order.
- Loading a chain **member by its concrete class name** (as the OPC AJAX route did) re-enters the
  chain generator mid-build and **fatals when ≥2 members sit after that member**. This is the root
  of the "`…PaymentController_parent` not found" 500s.
- **Resolution:** we removed the dependency on order entirely. The `OeOpcPayment` route now uses a
  controller that extends the **unified** class (not a chain member), so the chain builds
  non-re-entrantly regardless of order or how many PSPs are active. Order no longer affects
  correctness.

---

## 1. What the chain is

OXID modules extend core classes through metadata `extend` maps, e.g.:

```php
// one-page-checkout/metadata.php
'extend' => [ oxPaymentController::class => OnePageCheckout\…\PaymentController::class ],
// mollie/metadata.php
'extend' => [ …\PaymentController::class => Mollie\…\PaymentController::class ],
// stripe, paypal … likewise
```

When several modules extend the **same** core class, OXID does not merge them arbitrarily — it
arranges them into **one inheritance line**:

```
core PaymentController  ←  Mollie  ←  Stripe  ←  PayPal  ←  OnePageCheckout
        (base)                                              (top / "last")
```

Each module class is written as `class PaymentController extends PaymentController_parent`, where
`…_parent` is a **runtime-generated alias** to the previous link in the line.

## 2. Where the order is stored

The resolved order lives in the project configuration:

`source/var/configuration/shops/1/class_extension_chain.yaml`

```yaml
OxidEsales\Eshop\Application\Controller\PaymentController:
  - OxidEsales\Payments\Mollie\Controller\PaymentController
  - OxidEsales\Payments\Stripe\Controller\PaymentController
  - OxidEsales\Payments\PayPal\Controller\PaymentController
  - OxidEsales\OnePageCheckout\Application\Controller\PaymentController
```

The list is **top-down**: first entry chains onto core, each subsequent entry chains onto the one
above it, and the **last entry is what the shop actually instantiates** for `cl=payment`.

This file is **regenerated on every module activate/deactivate**. Observed behaviour:

- It does **not** follow activation order (Stripe was activated before Mollie, yet Mollie precedes
  Stripe in the chain).
- Re-activating a single module can move it, but not predictably to the end (activating PayPal
  appended PayPal *after* OPC; re-activating OPC afterwards did not reliably return it to last).

**Conclusion: the order is OXID-determined and not a stable, controllable knob.** Any fix that
depends on a specific order is fragile.

## 3. How the chain is built at runtime

Trigger → `OxidEsales\Eshop\Core\UtilsObject::getClassName($shopKey)` →
`ModuleChainsGenerator::createClassChain()` → `createClassExtensions()`.

`createClassExtensions()` walks the chain top-to-bottom, and for each member calls
`createClassExtension($parent, $member)`, whose core step is:

```php
$alias = $member . '_parent';
if (!class_exists($alias, false)) {
    class_alias($parent, $alias);   // define <Member>_parent = previous link
}
```

`class_alias($parent, …)` must **resolve `$parent`** (autoload it). So the chain is materialised
link by link: core is defined → `Mollie_parent = core` → load Mollie → `Stripe_parent = Mollie` →
load Stripe → … → the last member becomes the class returned by `getClassName()`.

Verified resolution of the "top" per chain (all four modules active):

| shop key       | resolves to (top of chain)                         |
|----------------|----------------------------------------------------|
| `payment`      | `OnePageCheckout\…\PaymentController`              |
| `order`        | `Stripe\…\StripeOrderController`                  |
| `oxviewconfig` | `PayPal\…\ViewConfig`                             |

Crucially, when the **trigger is `getClassName()`** (the normal `oxNew('oxpayment')` path), no
member is "mid-declaration" while the line is being stitched, so every `…_parent` alias is created
before the member that needs it. The build is clean for **any order and any number of members**.

## 4. Why order used to matter — the re-entrancy fault

The break happened only when a chain **member was loaded by its own concrete class name**:

```php
// one-page-checkout/metadata.php  (BEFORE)
'controllers' => [ 'OeOpcPayment' => OnePageCheckout\…\PaymentController::class ],
```

The OPC storefront hits `?cl=OeOpcPayment&fnc=getPaymentListJson`; `ShopControl` serves a named
controller with `oxNew(<concrete class>)`. That instantiates the OPC `PaymentController` **by its
concrete name**, so PHP starts loading it, reaches `class … extends PaymentController_parent`, and
— to resolve `_parent` — triggers `getClassName('payment')` → `createClassExtensions()` **while the
OPC class is still mid-declaration**.

Now the generator tries to alias the mid-loading OPC class as the next member's `_parent`. Because
OPC isn't a defined class yet, `class_alias(OPC, <next>_parent)` silently fails, that member's
`_parent` never gets created, and the **following** member's forced load hits
`extends <missing>_parent` → fatal:

```
Class "OxidEsales\Payments\Mollie\Controller\PaymentController_parent" not found
```

Exact rule (derived from `ModuleChainsGenerator` and confirmed empirically):

> The chain crashes **iff there are ≥ 2 members positioned after the member that triggered the
> load**. Zero or one member after the trigger survives (the last member is only ever *aliased to*,
> never *force-loaded*, during the build).

That is why the fault appeared only once a **second** PSP extension was added under OPC
(`OPC → Mollie → Stripe`), and why the earlier stop-gap — forcing OPC to be **last** in the chain —
"worked": with OPC last there were **0** members after it. But since the order is regenerated and
not controllable (§2), that stop-gap was fragile.

## 5. How it is resolved (order-independent)

The durable fix decouples the two roles OPC's class was playing (a chain **extension** *and* a
named **controller**). A new route controller is instantiated by `cl=OeOpcPayment`, and it extends
the **unified** shop class instead of being a chain member:

```php
// one-page-checkout/src/Application/Controller/OeOpcPaymentController.php  (NEW)
use OxidEsales\Eshop\Application\Controller\PaymentController as EshopPaymentController;

class OeOpcPaymentController extends EshopPaymentController   // NOT in any `extend` map
{
    use LogsViaOpcLogger;
    use PaymentJsonEndpointsTrait;   // getPaymentListJson / getPaymentCountJson
}
```

```php
// metadata.php  (AFTER)
'controllers' => [ 'OeOpcPayment' => OeOpcPaymentController::class ],
```

Because `OeOpcPaymentController` is **not itself a member** of the `PaymentController` chain,
instantiating it does not put a chain member "mid-declaration." Loading it triggers the chain build
with `getClassName()` as the trigger (the clean path of §3), so every `_parent` alias is created in
order and the concrete instantiation resolves — **for any chain order and any number of active
PSPs**.

The JSON endpoint methods were extracted into `PaymentJsonEndpointsTrait` and are shared by both the
new route controller and the original chain extension (which keeps only the `render()` takeover), so
there is no duplication.

**Validation:** with the chain deliberately set to the worst case
(`OnePageCheckout → Mollie → Stripe → PayPal`, i.e. OPC first with three members after it),
`?cl=OeOpcPayment&fnc=getPaymentListJson` returns **HTTP 200** and lists Mollie + Stripe + PayPal,
with zero `…_parent` errors in the shop log. The `class_extension_chain.yaml` ordering hack is no
longer required.

## 6. Related resolutions that fall out of the same mechanism

The re-entrancy fault applies to **any** flow that loads a chain member by concrete name:

- **Unit-test fixtures** that `extend` a concrete chain member (`TestableMollieViewConfig`,
  `TestableMolliePaymentController`, `TestableMollieOrderController`, OPC's `OrderControllerTest`
  mock) hit the same fatal in the multi-module dev shop. Resolved by **pre-building the chain** in
  `setUpBeforeClass()` via `Registry::getUtilsObject()->getClassName($key)` (trigger = `getClassName`,
  the clean path). Shared through the `…\Tests\Unit\Support\PreloadsModuleClassChain` trait; a no-op
  in the isolated CI job where only one module extends the class. Mollie unit suite 375/375; OPC 260
  green.

- **Order placement** with 2+ PSPs failed for a *different* reason — the shared
  `PaymentBase\TokenServiceInterface` is single-valued in the merged container and resolved to the
  wrong provider's token service. Fixed separately by having each PSP inject its **own concrete**
  `ContractTokenService`. (See `multi-psp-shared-tokenservice-collision` notes.)

## 7. Operational notes

- The chain order remains OXID-controlled and will keep changing across activations — that is now
  **cosmetic**, not correctness-affecting.
- A `services.yaml` change only takes effect once the compiled container in
  `var/cache/container/` is genuinely rebuilt (delete it — as root if a uid-1000 glob misses it —
  then reload, or re-activate the module). Loading the *unified namespace shim* does **not** build
  the module chain; only `getClassName()`/`oxNew()` does.
