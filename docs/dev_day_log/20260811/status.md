# Dev log — 2026-08-11 (Mollie module)

## Research: Mollie merchant identification vs. Stripe Connect — is an OXID "Mollie Connect" server viable?

No code changes. Compared the Stripe module's Connect onboarding (OXID-hosted middleware at
`osm.oxid-esales.com/stripe-connect` → `StripeConnect::stripeFinishOnBoarding()` persists the
connected-account `access_token` + publishable key) with Mollie's options, verified against live
Mollie docs and the vendored `mollie/mollie-api-php` 2.79.1.

- **Report:** [reports/01-mollie-identification-vs-stripe-connect.md](reports/01-mollie-identification-vs-stripe-connect.md)

### Findings

- **Mollie has both models.** A full OAuth 2.0 delegation flow (**Mollie Connect**:
  `my.mollie.com/oauth2/authorize` → `api.mollie.com/oauth2/tokens`) *and* a tiered partner
  program with contractual commissions and three attribution types — `oauth`, **`signuplink`**
  (referral URL, auto-links new organizations), `useragent`. So yes, there is a PayPal-style
  referral/onboarding-through-partner path. **Client Links** additionally give pre-filled,
  KYB-verified onboarding of brand-new merchants.
- **The SDK already supports all of it** — `setAccessToken()`, `ClientLinkEndpoint`,
  `OnboardingEndpoint`, `ClientEndpoint`, `OrganizationPartnerEndpoint`, `ProfileEndpoint`.
  No new dependency needed.
- **Blocker for cloning the Stripe design:** Stripe Connect access tokens **never expire**, so the
  OXID middleware is a one-shot doorman — if it went dark, connected shops keep working. Mollie
  access tokens expire in **~3600 s** and refresh requires `Basic base64(client_id:client_secret)`,
  which a shop cannot hold. Any OXID middleware therefore becomes a **permanent token-refresh
  proxy on the checkout critical path**. Same shape, materially worse risk profile.
- **Do not port the webhook apparatus.** Mollie has no webhook-endpoint registration API —
  `webhookUrl` is a per-payment/order field. The Stripe module's second manual key
  (`sStripe*Key`, `connect: true`) exists solely because *"connected-account tokens cannot create
  webhooks at all"*; that constraint has no Mollie counterpart.
- **Today's Mollie identification is fully manual:** `sMollieTestKey` / `sMollieLiveKey` chosen by
  `sMollieMode`, plus a hand-typed `sMollieProfileId`. No OAuth, no onboarding controller.

### Recommendation

1. **Business, no code:** enrol OXID in the Mollie partner program; get the referral/signup link
   and commission agreement.
2. **Sprint-sized:** guided onboarding panel on the module config tab — referral signup link,
   deep link to the Dashboard API-keys page, `test_`/`live_` prefix validation against
   `sMollieMode`, and a **profile dropdown** from `GET /v2/profiles` replacing the hand-typed
   `sMollieProfileId`. Zero infrastructure, keeps partner attribution.
3. **Only on a business case** (application fees / marketplace): full Mollie Connect, specced as a
   refresh proxy with an explicit availability budget, keeping the API-key path as a permanent
   fallback — exactly as the Stripe module keeps `sStripe*Key` beside `sStripe*Token`.
