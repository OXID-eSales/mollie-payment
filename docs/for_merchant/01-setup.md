# 01 — Merchant Setup Guide

## 1. Install the module

```bash
bin/oe-console oe:module:install extensions/mollie-payment
bin/oe-console oe:module:activate oe_payments_mollie
bin/oe-console oe:cache:clear
```

## 2. Get your Mollie API keys

1. Sign in to the [Mollie Dashboard](https://my.mollie.com/).
2. *Developers* → *API keys*.
3. Copy the **Test API key** (`test_…`) for setup/testing, and the **Live API key** (`live_…`)
   once you're ready to accept real payments. Each is at least 30 characters; the module rejects
   anything shorter or with the wrong prefix for the active mode (`ConfigurationValidator`).

## 3. Configure in OXID admin

**Admin → Extensions → Modules → Mollie Payment → Settings:**

| Group | Setting | Purpose | Example |
|---|---|---|---|
| General | `sMollieMode` | `test` or `live` | `test` |
| General | `sMollieCaptureMode` | `automatic` (charge immediately) or `manual` (two-step: authorize then capture). Manual applies per method: card and Buy-Now-Pay-Later (Klarna, Riverty, Billie, in3) are authorized first and captured from the admin *Payment* tab; every other method (iDEAL, PayPal, bank transfer, Bancontact, …) stays available and settles immediately. | `automatic` |
| Test config | `sMollieTestKey` | Your `test_…` API key | `test_aBcD…` |
| Live config | `sMollieLiveKey` | Your `live_…` API key | `live_wXyZ…` |
| Webhooks | `sMollieWebhookUrl` | Override the webhook URL (leave empty to use the default below) | |
| Logging | `sMollieLogLevel` | `off` / `errors` / `normal` / `debug` — see [03-troubleshooting.md](./03-troubleshooting.md) | `errors` |

Only the API key matching the active `sMollieMode` needs to be set — the module reads
`sMollieTestKey` in test mode and `sMollieLiveKey` in live mode automatically
(`ModuleConfigurationService::getApiKey()`).

## 4. The webhook URL — nothing to register manually

Unlike PayPal (which requires registering a webhook subscription in a developer dashboard and
copying back a Webhook ID), **Mollie does not require any dashboard-side webhook registration
step**. The shop passes its own webhook URL directly on every create-payment API call
(`CreatePaymentRequest::webhookUrl`), so Mollie always knows where to deliver status updates for
that specific payment — no separate subscription/ID to keep in sync.

Default webhook URL (used automatically unless `sMollieWebhookUrl` is set):

```
https://{yourShopUrl}/index.php?cl=MollieWebhookController
```

Only set `sMollieWebhookUrl` if you need a non-default path (e.g. a friendlier rewrite — see
[03-troubleshooting.md](./03-troubleshooting.md)).

## 5. Clear cache + verify

```bash
bin/oe-console oe:cache:clear
rm -rf source/tmp/*
```

Place a test order with a `test_…` key configured. On return from Mollie's hosted checkout you
should land on the thank-you page (success) or back on the payment step with an error message
(failure/cancellation) — never a generic 500 error. Check
**Admin → Orders → {your order} → Payment tab** for the Mollie panel (payment id, status, and
capture/refund/cancel actions where applicable — see
[02-admin-panel.md](./02-admin-panel.md)).
