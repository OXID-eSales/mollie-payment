# 03 — Extending the Mollie Module

## Non-negotiable rules for any extension

1. **The Mollie SDK is confined to `src/Mollie/Adapter/`.** Add a new SDK-touching class there,
   never elsewhere — `NoDirectSdkImportsRegressionTest` will fail your build otherwise.
2. **Never call `PaymentContractInterface::setState()`.** It doesn't exist. Use a named
   transition (`authorize()`, `fulfill()`, `cancel($reason)`, …) — `NoSetStateOnContractRegressionTest`
   enforces this.
3. **Never hardcode `'oe_payments_mollie'`.** Use `MollieDefinitions::MODULE_ID` /
   `MollieDefinitions::PAYMENT_ID`.

## Extension pattern 1 — additional webhook status

Implement `MollieWebhookEventHandlerInterface` and tag it `mollie.webhook_handler`:

```php
final class PaymentPointsOfInterestHandler implements MollieWebhookEventHandlerInterface
{
    public function handledStatuses(): array
    {
        return ['some_new_mollie_status'];
    }

    public function handle(WebhookEvent $event): MollieWebhookOutcome
    {
        // your logic, then:
        return MollieWebhookOutcome::of(WebhookResult::success('your_action'), $contractId);
    }
}
```

```yaml
OxidEsales\MyModule\Webhook\Handler\PaymentPointsOfInterestHandler:
  tags: [mollie.webhook_handler]
  public: true
```

`MollieWebhookProcessor` never needs to change (OCP) — it iterates the tagged collection and
picks the first handler whose `handledStatuses()` matches.

## Extension pattern 2 — additional admin action

Follow the `Mollie{Capture,Refund,CancelAuthorization}RequestEvent`/`…RequestHandler` shape:

1. A new abstract event on payment-base (if it doesn't exist yet) or reuse an existing one.
2. `MollieEventTranslator::translate()` maps it to your concrete Mollie event (add a branch).
3. A new handler implementing `HandlerInterface`, tagged `payment.event_handler`, delegating to
   your own service.
4. `OrderActionDispatcher` (or your own admin controller) dispatches through
   `EventBrokerInterface`, never the concrete event directly — that's what keeps the panel
   provider-agnostic (see [architecture/02-event-system.md](../architecture/02-event-system.md)).

## Extension pattern 3 — custom capture/refund logic

Decorate the existing service via Symfony DI, keeping the segregated interface intact:

```php
final class LineItemAwareRefundService implements RefundServiceInterface
{
    public function __construct(private readonly RefundServiceInterface $inner)
    {
    }

    public function refund(PaymentContractInterface $contract, ?float $amount = null, …): MollieRefundDto
    {
        // your logic, eventually:
        return $this->inner->refund($contract, $amount, …);
    }
}
```

```yaml
OxidEsales\MyModule\Service\LineItemAwareRefundService:
  decorates: OxidEsales\Payments\Mollie\Service\RefundServiceInterface
  arguments: ['@.inner']
```

## Extension pattern 4 — opt into the IP allowlist guard

`WebhookIpAllowlistGuard` exists but ships disabled (empty allowlist = pass-through) because
Mollie publishes no fixed webhook source ranges. If your infrastructure fronts the webhook
endpoint with a reverse proxy/CDN you control, wire it into the guard chain:

```yaml
OxidEsales\Payments\Mollie\Controller\Webhook\WebhookIpAllowlistGuard:
  arguments:
    $allowedCidrs:
      - '10.0.0.0/8'   # your reverse-proxy/CDN egress range

OxidEsales\Payments\Mollie\Controller\Webhook\WebhookGuardChain:
  arguments:
    $guards:
      - '@OxidEsales\Payments\Mollie\Controller\Webhook\WebhookPayloadSizeGuard'
      - '@OxidEsales\Payments\Mollie\Controller\Webhook\WebhookHttpsGuard'
      - '@OxidEsales\Payments\Mollie\Controller\Webhook\WebhookIpAllowlistGuard'
      - '@OxidEsales\Payments\Mollie\Controller\Webhook\WebhookRateLimitGuard'
```

## Extension pattern 5 — override the Mollie API client

Replace `MollieAdapterFactory` via DI alias to inject your own `LazyMollieAdapter` (custom TLS
pinning, request/response logging, a proxy, …). Keep the five segregated interfaces intact — that
is the DIP contract every downstream service relies on.

## Extension pattern 6 — a new file-logger channel

Follow `MollieWebhookFileLoggerFactory`'s shape: extend `AbstractFileLoggerFactory`, build the
`?\Closure $isEnabled` gate from `ModuleConfigurationServiceInterface`, implement
`getLogFile()`/`getPrefix()`/`getShopDirectory()`. Wire it in `services.yaml` with
`autowire: false` and an explicit `$config` argument, then bind the produced `FileLoggerInterface`
to a plain string service id (not the interface itself, to avoid colliding with Stripe's/PayPal's
own bindings of the same shared interface — see the comment in `services.yaml`).
