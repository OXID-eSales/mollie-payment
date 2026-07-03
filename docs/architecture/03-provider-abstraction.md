# 03 — Provider Abstraction

## No client-side SDK — the key difference from Stripe/PayPal

Stripe embeds a Payment Element; PayPal embeds Smart Buttons (`checkout.js`) with a fallback
redirect. Mollie has **neither**. The entire "Mollie SDK" surface facing the customer is a single
302 redirect to a Mollie-hosted checkout page and one return URL. Consequences:

- No `data-client-id`/`data-publishable-key` ever reaches the browser — there is nothing
  equivalent to F21's PayPal/Stripe frontend-confidentiality concern (see
  `docs/security/f-matrix.md`, F21).
- No JS SDK version pinning, no CSP relaxation for a third-party script origin.
- The storefront JS (`resources/js/controllers/mollie_checkout_controller.js`) only handles
  **method selection UX** (iDEAL vs. card vs. …) and a double-submit guard — it never talks to
  Mollie's API directly.

## Segregated adapter interfaces (ISP)

```
MolliePaymentsAdapterInterface   createPayment / getPayment / cancelPayment / fetchByWebhookId
MollieCaptureAdapterInterface    createCapture / listCaptures
MollieRefundAdapterInterface     createRefund / getRefund / listRefunds
MollieWebhookAdapterInterface    fetchByWebhookId
MollieMethodsAdapterInterface    listActiveMethods
```

Each consumer (CaptureService, RefundService, MollieWebhookProcessor, PaymentMethodListService,
…) depends on only the slice it needs — a unit test for `RefundService` mocks
`MollieRefundAdapterInterface` and `MolliePaymentsAdapterInterface` only, never the whole SDK
surface.

## One shared instance behind five interfaces — the Lazy pattern

All five interfaces alias to the **same** `LazyMollieAdapter` instance (`services.yaml`):

```php
LazyMollieAdapter implements <all 5 interfaces>
  __construct(private readonly \Closure $adapterFactory)
  private ?MollieAdapter $adapter = null;
  // every interface method → $this->adapter()->theMethod(...)
  private function adapter(): MollieAdapter {
      return $this->adapter ??= ($this->adapterFactory)();
  }
```

`MollieAdapterFactory::create()` returns `new LazyMollieAdapter(static fn() => new
MollieAdapter($clientFactory->create()))`. The Mollie SDK client — and therefore the
`MollieClientFactory`'s API-key validation — is only constructed on the **first** real API call
in a request, not at container-build time. This matters because:

1. A request that never touches Mollie (browsing, checkout with a different payment method) pays
   zero cost for an unconfigured/invalid API key.
2. `MollieClientFactory::create()`'s fail-fast behavior (empty/malformed key →
   `MollieConfigurationException`) only fires when Mollie is actually used, not on every request.

## The one SDK-aware seam

Exactly two classes are allowed to `use Mollie\Api\...`:

- `MollieAdapter` — translates between the module's own DTOs (`Adapter/Dto/*`) and the SDK's
  `Payment`/`Refund`/`Capture` resources; converts every `ApiException` to the module's own
  `MollieAdapterException` via `MollieExceptionConverter` so no SDK type crosses the boundary.
- `MollieClientFactory` — builds the configured `MollieApiClient`.

Enforced by `tests/Unit/Architecture/NoDirectSdkImportsRegressionTest.php`, which walks `src/`
(skipping `Adapter/`) and fails if any file imports `Mollie\Api\*`.

## DTOs, not SDK objects, everywhere else

`Adapter/Dto/*` (`MolliePaymentDto`, `MollieRefundDto`, `MollieCaptureDto`, `MollieAmountDto`,
`MollieMethodDto`, `CreatePaymentRequest`, `CaptureRequest`, `RefundRequest`,
`MethodsListRequest`) are the only shapes any domain service, handler, or controller ever sees.
They are immutable (`final readonly class`) value objects with no SDK dependency, which is what
makes `RefundServiceTest`/`CaptureServiceTest`/etc. pure unit tests with no SDK stub needed.
