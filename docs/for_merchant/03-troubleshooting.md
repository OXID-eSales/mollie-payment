# 03 — Troubleshooting

## Order stays unpaid after the customer completed payment on Mollie's page

Two independent paths normally set OXPAID: the customer's browser return (immediate, best case)
and the webhook (authoritative, may arrive seconds later or, rarely, before the browser redirect
does). If both are somehow missed:

1. Check `sMollieWebhookUrl` is reachable from the public internet:
   `curl -i -X POST https://{shop}/index.php?cl=MollieWebhookController` should respond with a
   4xx (missing `id` — the guard chain and controller are alive), never a connection error or 5xx.
2. Confirm the shop's outbound HTTPS calls to `api.mollie.com` aren't blocked by an egress
   firewall — the webhook processor's entire verification step is an outbound API call.
3. `OxpaidReconciliationServiceInterface::reconcile(orderId, providerOrderId)` exists and
   self-heals OXPAID by trusting Mollie's API as ground truth for a specific order/payment id
   pair — but unlike Stripe's module, **no `oe-console` command currently exposes it**. Today it
   can only be invoked programmatically (e.g. from a custom one-off script resolving the service
   from the container) — a CLI wrapper would be a natural small follow-up.

## Webhook always returns 400

Mollie sends no signature — a 400 here means the re-fetch-and-verify step
(`MollieWebhookProcessor`) could not confirm the payment id against Mollie's API. Common causes:

- **Wrong mode's key configured.** A `test_…` key can't see `live_…` payments and vice versa —
  double-check `sMollieMode` matches which key you're testing against.
- **Missing/empty `id` in the POST body.** `curl -i -X POST …` with no body reproduces this
  intentionally (F24 — malformed payload rejected before any API call).
- **Outbound connectivity to `api.mollie.com` blocked** — the "verification" *is* the API call;
  if the shop can't reach Mollie, every webhook fails closed.

## Webhook returns 413 or 429

- **413** — the payload exceeded the 1 MB cap (`WebhookPayloadSizeGuard`). Mollie's real payloads
  are a single `id=` field; a 413 almost always means something else is POSTing to this endpoint.
- **429** — the per-IP token bucket (`WebhookRateLimitGuard`) tripped. If this is legitimate
  Mollie traffic hitting the limit, it's a sign of an unusually high redelivery rate — check for
  an earlier 5xx that's causing Mollie to retry aggressively.

## Customer never reaches Mollie's checkout page ("payment unavailable" error instead)

`MollieCheckoutSessionHandler` validates the `checkoutUrl` Mollie's API returns before ever
redirecting the browser there (F14 — open-redirect protection; see `docs/security/f-matrix.md`).
If Mollie's API response is somehow malformed or the URL isn't on a `mollie.com` host, the
contract is failed with `untrusted_redirect_host` rather than redirecting anywhere. Check the
shop's error log for that string — a genuine occurrence would point at a Mollie API/SDK problem,
not a shop misconfiguration.

## Manual/two-step capture: "Cannot capture contract: not in an authorized state"

Known limitation, not a per-shop misconfiguration — see
`docs/architecture/00-overview.md`'s "Known limitation" note. As of this release, no live path
(webhook or checkout return) transitions a contract into the state `CaptureService` requires.
Auto-capture (`sMollieCaptureMode=automatic`) is unaffected.

## Turning on diagnostic logging

`sMollieLogLevel` (Admin → Extensions → Modules → Mollie Payment → Settings → Logging):

| Level | Backend file audit trail (`log/mollie/mollie_webhooks_<date>.log`) | Frontend browser console |
|---|---|---|
| `off` | nothing written | silent |
| `errors` / `normal` | webhook guard-rejections + processing results written | silent |
| `debug` | same as above | Mollie checkout-controller diagnostics printed to the browser console |

No redeploy or cache clear needed to change level — it's read fresh from the module setting on
every request/page load.

## Apache/nginx rewrite for a friendlier webhook URL

Optional. Add to `.htaccess`:

```apacheconf
RewriteRule ^mollie/webhook/?$ /index.php?cl=MollieWebhookController [L,QSA]
```

nginx equivalent:

```nginx
location = /mollie/webhook {
    rewrite ^/mollie/webhook$ /index.php?cl=MollieWebhookController last;
}
```

Set `sMollieWebhookUrl` to the friendly path so it's sent on every create-payment call.

## Clearing cache after changes

```bash
bin/oe-console oe:cache:clear
rm -rf source/tmp/*
```

Twig templates are aggressively cached — after editing any `.twig` in `views/` run the `rm` too.
