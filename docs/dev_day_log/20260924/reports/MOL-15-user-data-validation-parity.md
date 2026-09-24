# MOL-15 — User-data validation in Mollie, exactly as in Stripe: report

**Date:** 2026-09-24
**Ticket:** MOL-15
**Branch:** mollie-payment `b-7.4.x-MOL-15-user-data-validation-parity` (payment-base untouched, Stripe untouched)
**Plan:** `../sprints/MOL-15-user-data-validation-parity.md` (defaults taken: `postalCode`, address step on
failure, Stripe's admin free-text rules)

## What "exactly as in Stripe" turned out to mean

A read-only comparison of the two modules against payment-base's `docs/validation-system.md` showed Mollie
already had the framework's *shape* (rules file, factory-built validator, tagged formatter, payment-step
gate) but not its *coverage*. The gaps, all closed in this sprint:

| Gap | Now |
|---|---|
| Billing address only | Billing **and** selected delivery address (`hasDeliveryAddress()` / `readDeliveryField()`, `addressKind` on every failure) |
| Fields `zip`, no phone trio | `postalCode`, `cellPhone`, `personalPhone`, `fax` (Stripe's set) + Mollie's `email`; `captureReason` / `refundDescription` for admin |
| Describer buried in the formatter | `AllowedSymbolsDescriber` built by `ValidationRulesProvider::createDescriber()`, shared by formatter and OPC footer |
| **No `MOLLIE_VALIDATION_*` translation at all** — every message rendered as a raw key | EN/DE storefront + admin keys for template, labels, class words, review notice; `MOLLIE_CHECKOUT_UNAVAILABLE` too |
| Gate at the payment step only, one generic key; `execute()` dispatched unvalidated | `CheckoutUserDataGate` asked at the payment step **and** at the order step right before Mollie is called; per-field messages + review notice; order step returns to the address step |
| OPC footer posted `processCheckout` unvalidated | Footer exposes `validationUrl` / `pluginModuleId` / `fieldAllowed`; inline `mollie-user-data-validator` POSTs the live fields to `cl=oepaymentvalidationapi`, marks `.is-invalid` + message, emits `oe:payment:error`, fails open on non-OK |
| Admin capture reason / refund description unvalidated | `validateFieldMap([...], 'admin')` → `AdminValidationFeedback::rejectWithMessage()` with the tagged formatter's sentence |

Stripe-specific pieces deliberately **not** copied: the AJAX `createCheckoutSession` 422 flow (Mollie posts a
plain form, so its gate is server-side rendering), `stripe-*` DOM ids/keys, OPC-192 remount logic, the unused
legacy `*_GENERIC` keys.

## Red → green

| Proof | Red | Green |
|---|---|---|
| `UserDataValidatorTest` delivery / addressKind / postalCode+phones / admin kind | 4 failures (no delivery methods, no `addressKind`, fields unknown) | Story 2 |
| `OxidUserFieldReaderTest` (new) | undefined `hasDeliveryAddress()`, `oxidColumn('postalCode')` null | Story 2 |
| `ValidationRulesProviderTest` expects Stripe's set | `Missing validation rule for field 'postalCode'` | Story 2 |
| `ModuleIndependenceTest` (new) | `PaymentMethodDescriptor.php contains OxidEsales\Payments\Stripe` (docblock `@see`) | reworded |
| `MollieOrderControllerTest` user-data gate (3 tests) | dispatch happened / no messages | Story 4 |
| e2e `UserDataValidationBlocksCheckout` payment step | raw key `MOLLIE_VALIDATION_INVALID_USER_DATA` in the body | Story 3+4: "Das Feld Straße ist ungültig. Erlaubte Zeichen: Buchstaben, Ziffern, Leerzeichen, ' - . , /" |
| e2e `UserDataValidationBlocksCheckout` order step | left for Mollie with the bad address | Story 4: address step rendered, no order, no Mollie; repaired → pays |
| e2e `MollieOpc/UserDataValidation` (OPC flag flipped for the run, restored) | n/a (new) | Story 5: street `.is-invalid` with the server message, endpoint asked, no `processCheckout` |
| `MolliePaymentPanelProviderTest` invalid description / reason | n/a (new) | Story 6 |

## Gates

Unit 677 tests green (`tests/phpunit.xml`, Unit suite). PHPCS clean, PHPStan "No errors", PHPMD exit 0.
`mollie-standard` regression after all six stories: 16 passed, 2 skipped (Klarna specs, env-gated, pre-existing) in 6.0 min — the 13 existing specs, the 3 MOL-18 tests and the 2 new MOL-15 tests.

## Deviations from the plan

- **Return leg extracted into `HandlesMollieCheckoutReturn` (trait), not `OrderSubmissionGuards`.** Adding
  the gate seams pushed `MollieOrderController` to 26 methods / complexity > 50. The submission guards
  are Registry-backed seams the testable subclass overrides, so extracting *them* would have broken the
  test pattern; the post-checkout return leg (`checkoutReturn()` + 7 helpers) is the other responsibility
  in that class and moved as a whole. `resolveCheckoutReturnResponder` needed an `insteadof` against
  payment-base's `HandlesCheckoutReturn`.
- **OPC validator lives inline in `mollie-footer.html.twig`, not in the module bundle.** The footer
  controller registers on OPC's Stimulus application (`window.OnepageCheckout.stimulus`); a controller in
  `mollie-frontend.js` registers on `window.Stimulus` and could not find the footer instance to wrap. Same
  placement as Stripe's.
- **Invalid character planted in the DB, not through the address form** (`fixtures/shop-db.ts`
  `setBillingStreetOf`): the point is what Mollie does with data that *is* stored, and a form-level
  address validator on the shop could have refused it. Restored in `finally`.
- **URL assertions replaced by step-heading assertions in the e2e.** OXID renders the returned controller
  on the POST response, so the browser URL lags one step behind (`cl=user` shown while the payment page
  renders). The heading of the rendered step is the reliable signal.
- **`AdminValidationFeedbackInterface` grew a third method** (`rejectWithMessage`): admin text failures are
  formatted by the tagged user-data formatter, the amount feedback by its own; 3 methods, still ISP-sized.

## Independence

`tests/Unit/Security/ModuleIndependenceTest` greps `src/`, `services.yaml`, `metadata.php` for
`OxidEsales\Payments\Stripe` and `oe_payments_stripe`: none. Describer and translator are Mollie-local
copies of ~40 lines each; lifting them into payment-base would touch Stripe and is a follow-up.

## Follow-ups (out of scope)

Lift `AllowedSymbolsDescriber` / `LanguageTranslator` into payment-base for both modules; per-PSP rate-limit
override; payment-base's `iValidationApiRatePerMinute` setting is still unwired (payment-base TODO).
