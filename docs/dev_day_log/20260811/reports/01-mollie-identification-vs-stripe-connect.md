# Mollie merchant identification vs. Stripe Connect — feasibility of an OXID "Mollie Connect" middleware

**Date:** 2026-08-11
**Author:** Daniil Tkachev (with Claude Code)
**Scope:** Compare how the Stripe module obtains merchant credentials (Stripe Connect + OXID middleware) with how the Mollie module obtains them today; determine whether an equivalent OXID-hosted onboarding server is possible for Mollie, or whether Mollie's model is closer to PayPal's partner-referral onboarding.
**Verdict (short):** Mollie has **both** — a full OAuth delegation flow (Mollie Connect) *and* a partner/referral program with commissions. An OXID middleware is technically possible, but it is **not a one-shot handoff like Stripe's** — it would become a permanently-online token-refresh service. The cheap win is the **referral/signup-link partner path** (PayPal-like), which needs **zero** middleware.

---

## 1. How the Stripe module identifies the merchant today

### 1.1 Two independent credential paths

`metadata.php` carries two *distinct* credential pairs per mode — this is easy to misread, so it is worth stating explicitly:

| Setting | Meaning |
| --- | --- |
| `sStripeTestToken` / `sStripeLiveToken` | Connected-account **`access_token` from Connect OAuth** — written by the middleware round-trip |
| `sStripeTestPk` / `sStripeLivePk` | Connected-account **publishable key** — also written by the middleware round-trip |
| `sStripeTestKey` / `sStripeLiveKey` | The merchant's **own secret key**, pasted manually ("platform key" in code) |

`metadata.php:87` and `:93` call this out in comments: *"Distinct from sStripeTestToken (connected-account access_token from OAuth)."*

So the module already supports **manual key entry** *and* **OAuth onboarding** side by side. The manual key is not vestigial — see §1.3.

### 1.2 The OXID middleware

`src/Stripe/Controller/Admin/ModuleConfiguration.php:54,59`:

```php
private const CONNECT_MIDDLEWARE_TEST_URL = 'https://stripe-middleware-test.oxid-esales.com/stripe-connect';
private const CONNECT_MIDDLEWARE_LIVE_URL = 'https://osm.oxid-esales.com/stripe-connect';
```

`stripeGetConnectUrl()` (`:196`) builds the shop's return URL and hands it to the middleware:

```php
$redirectUrl  = getShopUrl(0, true) . 'admin/index.php?cl=StripeConnect&fnc=stripeFinishOnBoarding';
$redirectUrl .= '&stoken=' . getSessionChallengeToken();
$redirectUrl .= '&shop_param=' . $sMode;   // test|live
$redirectUrl .= '&shp=' . getShopId();
return self::CONNECT_MIDDLEWARE_*_URL . '?shop_redirect_url=' . rawurlencode($redirectUrl);
```

Flow:

```
Admin clicks "Connect with Stripe"
  → osm.oxid-esales.com/stripe-connect?shop_redirect_url=…
      (OXID's server holds the platform client_id + client_secret;
       runs the Stripe Connect OAuth authorize + token exchange)
  → back to shop: admin/index.php?cl=StripeConnect&fnc=stripeFinishOnBoarding
        &access_token=…&publishable_key=…&shop_param=test|live&stoken=…
  → StripeConnect::stripeFinishOnBoarding() persists both into module settings
```

`StripeConnect.php:73-124` is deliberately thin: CSRF check (`checkSessionChallenge()`), read three request params, validate mode ∈ {test, live}, `moduleSettingService->save()` × 2. No token refresh, no expiry handling, no state table.

**Why it can be that thin:** a Stripe Connect OAuth `access_token` for a Standard account **never expires** (it is the connected account's secret key; it can only be revoked by the merchant). One handoff and the middleware is never contacted again. The middleware is stateless from the shop's point of view.

### 1.3 Why the manual key still exists

Webhook registration. `ModuleConfiguration::stripeCreateWebhookEndpoint()` (`:221`) uses `getPlatformKey()` — i.e. `sStripeTestKey`/`sStripeLiveKey` — and passes `connect: true`, with the comment at `:251`:

> `true, // Connect webhook — connected-account tokens cannot create webhooks at all.`

So the OAuth token alone cannot complete setup on Stripe; a second, manually-entered key is needed for the webhook endpoint. That is an artefact of Stripe's permission model, and — as §3.4 shows — **it has no Mollie counterpart**, because Mollie has no webhook-endpoint registration API at all.

---

## 2. How the Mollie module identifies the merchant today

Purely manual. `metadata.php:85,88,89`:

```php
['group' => 'MOLLIE_TEST_CONFIG', 'name' => 'sMollieTestKey',   'type' => 'str', …],
['group' => 'MOLLIE_GENERAL',     'name' => 'sMollieProfileId', 'type' => 'str', …],
['group' => 'MOLLIE_LIVE_CONFIG', 'name' => 'sMollieLiveKey',   'type' => 'str', …],
```

`ModuleConfigurationService::getApiKey()` picks `sMollieTestKey` vs `sMollieLiveKey` from `sMollieMode`; `MollieAdapterFactory::create()` feeds it to `MollieClientFactory` inside a lazy closure. `getProfileId()` returns `sMollieProfileId`, consumed by `ViewConfig::getMollieProfileId()` and the `MollieCheckoutFooter` widget (Components / hosted-field rendering).

There is **no** admin controller under `src/Mollie/Controller/Admin/` other than `OrderActionDispatcher.php` — no Connect landing page, no OAuth anything.

The merchant journey today: open Mollie Dashboard → Developers → API keys → copy `test_…` / `live_…` → paste into OXID admin → also copy the profile ID (`pfl_…`) → paste. Three copy-paste operations, two of which the merchant has to understand conceptually (which key for which mode; what a profile even is).

---

## 3. What Mollie actually offers

Verified against live docs (August 2026) and against the vendored SDK, `mollie/mollie-api-php` **2.79.1**.

### 3.1 Mollie Connect (OAuth 2.0) — the direct Stripe Connect analogue

- Register an app: Mollie Dashboard → **More → Developers → Your apps → Create Application** → yields `client_id` (`app_…`) + `client_secret`.
- **Authorize:** `GET https://my.mollie.com/oauth2/authorize` with `client_id`, `state`, `scope` (space-separated), `response_type=code`, and optionally `redirect_uri` (must match the registered URL exactly), `approval_prompt=auto|force`, `locale`, **`landing_page=login|signup`**.
- **Token:** `POST https://api.mollie.com/oauth2/tokens`, authenticated with `Authorization: Basic base64(client_id:client_secret)`, `grant_type=authorization_code|refresh_token`.
- Response: `access_token` (prefix `access_`), `refresh_token` (**does not expire**), `expires_in` (**3600** in the docs' example — 60 minutes), `token_type=bearer`, `scope`.
- Scopes are granular and additive: `payments.read/write`, `refunds.read/write`, `customers.*`, `mandates.*`, `subscriptions.*`, `profiles.*`, `organizations.*`, `settlements.read`, `balances.read`, `invoices.read`, orders/shipments/terminals. **Adding a scope later forces every merchant to re-connect the app.**
- Revocation: standard OAuth revoke endpoint; a disconnected merchant surfaces as `invalid_grant` on the next refresh attempt.

**SDK support is already in the tree** — no new dependency required:

| Capability | Class |
| --- | --- |
| OAuth token as credential | `MollieApiClient::setAccessToken()` (validates `access_` prefix, sets `oauthAccess=true`, appends `OAuth/2.0` to UA) |
| Onboarding link creation | `Endpoints/ClientLinkEndpoint` + `Resources/ClientLink::getRedirectUrl($clientId, $state, $scopes, $approvalPrompt)` |
| Onboarding progress | `Endpoints/OnboardingEndpoint` (`onboarding/me`) — **deprecated in favour of Capabilities API** |
| Connected merchants | `Endpoints/ClientEndpoint` (`clients`, ids are `org_…`) |
| Partner/commission status | `Endpoints/OrganizationPartnerEndpoint` (`organizations/me/partner`) + `Resources/Partner` |
| Profiles | `Endpoints/ProfileEndpoint`, `ProfileMethodEndpoint` |

### 3.2 Client Links — onboarding *new* merchants

`POST /v2/client-links` with pre-fill data (email, name, organization name, address, registration/VAT numbers) returns a `clientLink`; the app appends `client_id`, `scope`, `state`, `approval_prompt` and sends the merchant there. The merchant lands on a **pre-filled Mollie signup screen**, Mollie runs KYB, and the merchant appears under **Partners → Clients** in OXID's partner dashboard.

This is the closest thing to Stripe's "onboard a brand-new account through the platform" — and it is strictly better than Stripe OAuth for greenfield merchants, because the business data can be pre-populated from the shop.

### 3.3 Post-callback work the platform must do (this is the part with teeth)

Per `connect-platforms-onboarding-customers`:

1. Exchange `code` (valid **30 seconds**) → `access_token` (60 min) + `refresh_token` (persistent, store securely).
2. **Fetch or create a website profile** via the Profiles API — existing merchants may have several, and the docs say to *ask the merchant which profile to use*.
3. Enable the desired payment methods via the API (Klarna and SEPA DD need extra verification).
4. Poll onboarding/capabilities until `canReceivePayments` (and `canReceiveSettlements`) is true.
5. **Every payment must carry `profileId`.** With an API key this is implicit (keys are profile-scoped); with an OAuth token it is not (tokens are organization-scoped).

Item 5 is why `sMollieProfileId` already exists in the module — and it is also why an OAuth path would *simplify* that field (it can be discovered and offered as a dropdown instead of typed).

### 3.4 What Mollie does **not** require

No webhook-endpoint registration API. Mollie webhooks are per-resource: `webhookUrl` is a field on Payment / Order / Subscription / PaymentLink (confirmed in the SDK resources; there is no `WebhookEndpoint` endpoint class). The module's `getWebhookUrl()` already computes `…/index.php?cl=MollieWebhookController` and passes it per payment.

**Consequence:** the whole "Create webhooks / Clear all webhooks + platform key" apparatus that the Stripe module needs (`WebhookEndpointRegistrar`, `sStripe*Key`, `connect: true`) has **no Mollie equivalent and must not be ported**. A Mollie onboarding flow only ever has to deliver credentials — not credentials *plus* a second key for webhook creation.

### 3.5 Partner program — the PayPal-referral analogue

`Resources/Partner` documents exactly three `partnerType` values:

- **`oauth`** — partner integrated via an OAuth app (§3.1).
- **`signuplink`** — *"The URL that can be used to have new organizations sign up and be automatically linked to this partner."*
- **`useragent`** — attribution via a user-agent token string sent on API calls.

Plus `isCommissionPartner` (*"Whether the current organization is receiving commissions"*), `partnerContractSignedAt`, `partnerContractExpiresAt`, `partnerContractUpdateAvailable`.

Commercially, Mollie runs a tiered **Technology Partner Program** (and an agency program) with commissions defined in the individual partner agreement — visible in the Dashboard under **Partner → News & Resources → Partner Agreement**. Agency partners get a *personal referral signup link* that auto-links referred merchants for commission.

**So: yes, Mollie has a referral program comparable to PayPal's.** And it has three attribution mechanisms of increasing integration depth, of which only one (`oauth`) needs a server.

Monetization on the Connect side is **application fees** — the platform sets a per-payment fee that moves to the platform's balance when the payment succeeds. That requires OAuth (it is a platform-on-behalf-of-merchant concept) and is orthogonal to partner commission.

---

## 4. Head-to-head

| Dimension | Stripe (as built) | Mollie (as available) |
| --- | --- | --- |
| Delegation protocol | Connect OAuth 2.0 | Mollie Connect OAuth 2.0 |
| Where client_secret lives | `osm.oxid-esales.com` | would have to be an OXID server too |
| Authorize URL | Stripe Connect OAuth | `https://my.mollie.com/oauth2/authorize` |
| Token URL | Stripe token endpoint | `https://api.mollie.com/oauth2/tokens` (Basic auth) |
| **Access-token lifetime** | **never expires** | **~3600 s** |
| Refresh needed? | **No** | **Yes, hourly-ish**, needs `client_secret` |
| Auth-code lifetime | — | **30 s** |
| Handoff can be one-shot? | **Yes** | **No** (see §5) |
| Credential granularity | account-wide secret key | scoped permissions, re-consent to widen |
| Onboard a *new* merchant with pre-filled data | not in this module | **yes** — Client Links |
| Per-payment scoping | n/a | `profileId` mandatory |
| Webhook registration | required, needs a 2nd manual key | **not applicable** |
| Provider-side referral/commission program | Stripe partner ecosystem (not wired here) | **partner program, 3 attribution types, commissions** |
| Platform take-rate hook | Connect application fees | **application fees** (OAuth-only) |

---

## 5. The blocker: Mollie tokens expire, so "middleware" means something different

This is the single finding that should drive the decision.

The Stripe middleware is a **doorman**: it authenticates once, hands the shop a permanent key, and is never needed again. If `osm.oxid-esales.com` went dark tomorrow, every already-connected shop would keep processing payments.

A Mollie middleware would be a **utility**: refreshing an access token requires `grant_type=refresh_token` with `Authorization: Basic base64(client_id:client_secret)`. The shop cannot do this alone without holding OXID's `client_secret` — which would ship the platform secret to every merchant installation, i.e. a non-starter. Therefore one of these must be true:

- **(a) Middleware proxies refresh forever.** The shop stores only the `refresh_token`; each time the access token expires it calls `osm.oxid-esales.com` to trade the refresh token for a fresh access token. The middleware stays stateless-ish, but it is now **on the critical payment path**. Middleware down or slow ⇒ checkout down for every OXID Mollie shop. Needs SLA, monitoring, rate limits, and a graceful-degradation story we do not have today.
- **(b) Middleware proxies the whole Mollie API.** Worse: all payment traffic through OXID infrastructure. Rejected on latency, liability, and PCI/GDPR surface.
- **(c) One app per shop.** Each merchant registers *their own* OAuth app in their own Mollie Dashboard and pastes `client_id` + `client_secret` into OXID. No OXID server at all, and refresh works locally. But the merchant now does *more* copy-paste than today, and OXID gets no partner attribution. Strictly worse UX than the API key it replaces.
- **(d) No OAuth. Referral link + API key.** OXID enrols as a partner, ships a `signuplink`/referral URL and a deep link into the Dashboard's API-keys page. Merchant clicks through, signs up (auto-attributed to OXID for commission), copies one key back. Zero infrastructure, zero secrets held by OXID, partner commission intact. UX ≈ today plus a guided path.

There is also a mixed shape worth naming:

- **(e) Client Links for signup, API key for credentials.** Use `POST /v2/client-links` *only* to give the merchant a pre-filled onboarding+attribution link (that call needs an OXID-held token, so it needs a small server endpoint — but one that is off the payment path and only touched at install time), then let the merchant paste the API key. Best onboarding UX per unit of infrastructure risk. Note that a client link's redirect *is* an OAuth authorize URL, so if we want to avoid the token exchange entirely we would use the plain referral/signup link instead — which is exactly (d).

---

## 6. Recommendation

**Do not clone the Stripe middleware for Mollie.** The property that makes the Stripe design safe — a non-expiring token, so the middleware is optional after first use — does not hold for Mollie. Cloning it converts a one-shot convenience into a permanent single point of failure in front of every merchant's checkout.

Recommended sequencing:

1. **Now (no code, business action):** enrol OXID as a Mollie partner and obtain the referral/signup link + commission agreement. This is a prerequisite for everything below and has no engineering cost. Confirm which `partnerType` OXID is granted and whether `isCommissionPartner` is true.
2. **Sprint-sized (option d):** an onboarding panel on the Mollie module config tab: "Don't have a Mollie account? → [OXID referral signup link]" plus "Get your API key → [deep link to Dashboard API keys]", a `test_`/`live_` prefix validator that checks the key against `sMollieMode`, and a **profile dropdown** populated from `GET /v2/profiles` using the just-entered key (replaces the hand-typed `sMollieProfileId` — this is a real usability win independent of any Connect work). Mirrors the Stripe tab's key-validation affordance (`stripeGetKeyValidationError()`).
3. **Only if a business case appears (options a/e):** full Mollie Connect. Justified by **application fees** (a take-rate OXID cannot get any other way) or by a marketplace/multi-vendor product — not by onboarding convenience alone. If it happens, spec the middleware as a *refresh proxy* with an explicit availability budget, and keep the API-key path as a permanent fallback exactly as the Stripe module keeps `sStripe*Key` alongside `sStripe*Token`.

### If step 3 is ever taken — what the module needs

- `sMollieOAuthRefreshToken`, `sMollieOAuthAccessToken`, `sMollieOAuthAccessTokenExpiresAt`, `sMollieOrganizationId` (per mode) — refresh token stored encrypted, never rendered in the config form (cf. Stripe's decision to keep endpoint id/secret in `oxconfig`, out of the editable form).
- `ModuleConfigurationService::getApiKey()` becomes a credential *resolver*: OAuth token when present and fresh (refresh if `expiresAt` is near), else API key. `MollieAdapterFactory`'s lazy closure is already the right seam — it resolves credentials at call time, not container-build time, so a refresh-on-demand fits without restructuring.
- `MollieClientFactory` must branch `setAccessToken()` vs `setApiKey()` (SDK validates the prefixes and will throw on a mismatch).
- `profileId` becomes **mandatory** on every payment/order create — audit the payment builders before flipping any switch, because today it is only used for the frontend Components widget.
- A `MollieConnect` admin controller mirroring `StripeConnect` (CSRF via `checkSessionChallenge()`, mode from `shop_param`, persist via `ModuleSettingBridgeInterface`) — thin, but it must additionally persist the refresh token and expiry.
- Capabilities API polling to gate whether Mollie is offered as a payment method (`canReceivePayments`), analogous to `isConfigured()` gating in `PaymentController`.
- Scope list must be finalised **before** launch — widening scopes later forces every connected merchant to re-consent.

---

## 7. Answers to the three questions as asked

**Q: Can we establish a similar Stripe-Connect-style server for Mollie?**
Technically yes — Mollie Connect is a complete OAuth 2.0 delegation flow and the vendored SDK already supports every piece (`setAccessToken`, client links, clients, profiles, partner status). But not *similar in kind*: Stripe's non-expiring token lets the middleware retire after one handoff, while Mollie's 60-minute token plus secret-authenticated refresh makes any OXID server a permanent dependency on the checkout path. Same shape, materially worse risk profile.

**Q: Does Mollie do a referral program like PayPal — onboarding through the partner?**
Yes. Mollie runs a tiered Technology/Agency Partner Program with contractual commissions, and the API models three attribution types: `oauth`, **`signuplink`** (referral URL that auto-links new organizations to the partner), and `useragent`. Additionally, **Client Links** provide pre-filled, KYB-verified onboarding of brand-new merchants — functionally the closest analogue to PayPal's partner-referral onboarding, and something the Stripe module does not currently do at all.

**Q: How does Mollie get identification today (in our module)?**
Manually: `sMollieTestKey` / `sMollieLiveKey` selected by `sMollieMode`, plus a hand-typed `sMollieProfileId`. No OAuth, no onboarding controller, no middleware.

---

## Sources

**Code (this repo)**
- `source/extensions/stripe/src/Stripe/Controller/Admin/ModuleConfiguration.php:54,59,196-208,221-268`
- `source/extensions/stripe/src/Stripe/Controller/Admin/StripeConnect.php:73-124`
- `source/extensions/stripe/metadata.php:81-107`
- `source/extensions/mollie-payment/metadata.php:70-94`
- `source/extensions/mollie-payment/src/Mollie/Service/ModuleConfigurationService.php:74-108`
- `source/extensions/mollie-payment/src/Mollie/Service/Factory/MollieAdapterFactory.php`
- `source/vendor/mollie/mollie-api-php` **2.79.1** — `MollieApiClient.php:525-571,722-734`, `Endpoints/{ClientLinkEndpoint,OnboardingEndpoint,ClientEndpoint,OrganizationPartnerEndpoint,ProfileEndpoint}.php`, `Resources/{ClientLink,Partner}.php`

**Mollie documentation**
- [Mollie Connect: Overview](https://docs.mollie.com/docs/connect-overview)
- [Connect for Platforms: Getting started](https://docs.mollie.com/docs/connect-platforms-getting-started)
- [Connect for Platforms: Setting up OAuth](https://docs.mollie.com/docs/connect-platforms-setting-up-oauth)
- [Connect for Platforms: Onboarding customers](https://docs.mollie.com/docs/connect-platforms-onboarding-customers)
- [Connect for Platforms: Managing customers](https://docs.mollie.com/docs/connect-platforms-managing-customers)
- [OAuth: Authorize](https://docs.mollie.com/reference/authorize) · [Generate tokens](https://docs.mollie.com/reference/oauth-generate-tokens) · [OAuth API](https://docs.mollie.com/reference/oauth-api)
- [Create client link](https://docs.mollie.com/reference/create-client-link) · [Clients API](https://docs.mollie.com/reference/clients-api) · [Capabilities API](https://docs.mollie.com/reference/capabilities-api) · [Get partner status](https://docs.mollie.com/reference/get-partner-status)
- [Mollie launches Technology Partner Program](https://www.mollie.com/news/technology-partner-program) · [Partner Program — Agencies](https://www.mollie.com/partner-program/agencies) · [How to refer a new merchant](https://help.mollie.com/hc/en-us/articles/18493873609106-How-to-Refer-a-New-Merchant-to-Mollie) · [Where do I find my partner commission](https://help.mollie.com/hc/en-us/articles/12188503523730-Where-do-I-find-my-partner-commission)

**Stripe documentation**
- [Using OAuth with Standard accounts](https://docs.stripe.com/connect/oauth-standard-accounts) · [Connect OAuth reference](https://docs.stripe.com/connect/oauth-reference) — access tokens never expire, revocable by the user
