# Security parity matrix — Mollie vs. PayPal/Stripe F1–F25

**Date:** 2026-07-03
**Scope:** `source/extensions/mollie-payment/src/Mollie/**`
**Baseline reference:** PayPal's own F1–F25 audit (`../paypal/docs/dev_log/20260420/reports/security-audit.md`)

PayPal's sprint 21 security audit claims parity with an internal Stripe F1–F25 finding set. This
document performs the same exercise for Mollie: for every F-item, either point at the concrete
Mollie surface and its test, or state — explicitly — why it does not apply. No item is left
unaddressed; "N/A" always carries a one-line reason, never a blank.

Mollie's architecture differs from both siblings in ways that reshape several findings:

- **No signature on webhooks.** Mollie webhooks carry only a payment id; verification IS the
  re-fetch of that payment from Mollie's API over TLS with the shop's secret key (see
  `MollieWebhookProcessor`). This replaces PayPal's/Stripe's HMAC/RSA signature check outright —
  it is the single biggest architectural divergence in this matrix (F18).
- **No OAuth2, no client-id/secret pair.** Mollie authenticates with one static API key
  (`test_…`/`live_…`). There is no bearer-token refresh flow, no separate "client secret" field,
  and therefore no analog to several of PayPal's OAuth-specific findings (F6, F12).
- **No client-side SDK widget.** Mollie is a classic server-side redirect flow: the shop creates
  the payment, gets back a `checkoutUrl`, and 302-redirects the browser. There is no PayPal-style
  `checkout.js`/Stripe-style Payment Element embedding config/secrets into the page.
- **Central user-input validation (Sprint 4 Story 6) is exercised, not skipped.** PayPal's own
  audit doesn't mention this subsystem at all — it's the parity item *PayPal itself* skipped.
  Mollie wires its `validation-rules.php` + `UserDataValidator` through the same shared
  `oepaymentvalidationapi` guard chain payment-base provides, and this sweep tests that chain
  directly with Mollie's module id (`ValidationEndpointGuardParityTest`).

## Finding matrix

| # | Finding | Mollie surface | Covered by | Status |
|---|---------|-----------------|------------|--------|
| F1  | TLS required on webhook | `WebhookHttpsGuard` (Sprint 5) | `WebhookSecurityParityTest::testF1_WebhookHttpsEnforced` | ✅ |
| F2  | Oversize payload guard | `WebhookPayloadSizeGuard` (Sprint 5, 1 MB default) | `WebhookSecurityParityTest::testF2_PayloadSizeCapped` | ✅ |
| F3  | Source IP allowlist | `WebhookIpAllowlistGuard` (built, **not wired by default** — Mollie publishes no fixed webhook source ranges, unlike PayPal; see the guard's own docblock and `services.yaml`) | `WebhookSecurityParityTest::testF3_IpAllowlistGuardRejectsUnlistedSource` (first dedicated test for this guard) | ✅ (opt-in) |
| F4  | Request sanitisation / control chars | Character-**allowlist** validation (`validation-rules.php` + `UserDataValidator`), which **rejects** (422) rather than PayPal's strip-and-continue `CheckoutHelper::sanitise()` — a stricter, not weaker, design | `UserDataValidatorTest`, `ValidationRulesProviderTest` | ✅ |
| F5  | Missing credentials rejected | `ConfigurationValidator` (Sprint 3) | `ConfigurationValidatorTest::testValidate_EmptyKey_Fails` | ✅ |
| F6  | Client secret validation | **N/A** — Mollie has one credential (the API key), not a client-id/secret pair; already covered by F5 | n/a | N/A |
| F7  | Mode enumeration | `ModuleConfigurationService::getMode()` defensively folds anything but `live` to `test` | `ModuleConfigurationServiceTest` (existing) + metadata `constraints` select | ✅ |
| F8  | Capture mode enumeration | `ModuleConfigurationService::getCaptureMode()` defensively folds anything but `manual` to `automatic` | `ModuleConfigurationServiceTest::testGetCaptureMode_DefaultsToAutomatic` (existing) | ✅ |
| F9  | Allowed currencies non-empty | **N/A** — currencies are not merchant-configurable; `MollieDefinitions::getSupportedCurrencies()` is a compile-time constant (`['EUR']`) | n/a | N/A |
| F10 | Currency code format | **N/A** — same reasoning as F9, no user/admin input to malform | n/a | N/A |
| F11 | Webhook id mandatory when enabled | **N/A** — Mollie has no separate "webhook subscription id" secret to validate; `getWebhookUrl()` derives a default from the shop URL when unset | n/a | N/A |
| F12 | JWT bypass | **N/A** — no JWT anywhere in this module's code paths | n/a | N/A |
| F13 | MCP disclosure | **N/A** — module-level; no MCP surface | n/a | N/A |
| F14 | Open redirect | `MollieRedirectUrlValidator` (**new this sprint** — see below) gates `checkoutUrl` to the `mollie.com` host before `MollieCheckoutSessionHandler` ever writes it onto the contract/context | `MollieRedirectUrlValidatorTest` (12 adversarial cases) + `MollieCheckoutSessionHandlerTest::testHandleFailsContractWhenCheckoutUrlHostIsNotAllowed` | ✅ |
| F15 | Amount validation | `CaptureService`/`RefundService` bound checks against the *live Mollie payment* (API truth), not local arithmetic (Sprint 6) | `CaptureServiceTest::testCapture_ExceedingAuthorized_Rejected`, `RefundServiceTest::testRefund_ExceedingRefundable_ThrowsException` (existing) | ✅ |
| F16 | Currency mismatch | **N/A** — `capture()`/`refund()` take no caller-supplied currency parameter at all; amount and currency are always read off the live Mollie payment resource, so there is no separate value that could mismatch | n/a | N/A |
| F17 | Rate limiting | `WebhookRateLimitGuard` (webhook) + shared `RateLimitGuard` (central validation endpoint) | `WebhookSecurityParityTest::testF17_RateLimited`, `ValidationEndpointGuardParityTest::testValidationApi_RateLimited` | ✅ |
| F18 | Webhook auth bypass | **Verification IS the fetch**: `MollieWebhookProcessor::parseAndValidateRequest()` re-fetches the payment from Mollie's API; a forged/unrecognised id throws before the idempotency claim, so it can never mutate a contract | `WebhookSecurityParityTest::testF18_VerificationByApiFetch_NotForgeable` (+ positive-path `testF18_GenuinePaymentIsAccepted`), `MollieWebhookProcessorTest` (existing) | ✅ |
| F19 | Sensitive data in exceptions | No OAuth tokens exist to leak; the one credential (API key) is validated by format only and never interpolated into any exception message this module constructs (`ConfigurationValidator`, `MollieClientFactory`) — verified, not assumed | `CredentialConfidentialityTest::testConfigurationValidatorErrorsNeverContainTheApiKeyValue`, `::testClientFactoryMalformedKeyExceptionNeverContainsTheKeyValue` | ✅ |
| F20 | Session fixation on return URL | `ContractTokenService` — HMAC-secured `contract_token` in the redirect URL; the exact Mollie analog of PayPal's return-URL session binding | `WebhookSecurityParityTest::testF20_ReturnTokenTamperRejected`, `ContractTokenServiceTest` (existing) | ✅ |
| F21 | Order id / secret confidentiality at frontend | No client-side SDK widget exists; the only Mollie-sourced data rendered on the storefront is the method picker, fed exclusively by `MollieMethodDto` (`id`/`description`/`imageUrl` — no payment/contract id, no key) | `FrontendConfidentialityParityTest::testMollieMethodDtoOnlyExposesPresentationFields` | ✅ |
| F22 | UCP URL validation | **N/A** — module doesn't interact with UCP | n/a | N/A |
| F23 | Exception logging | Same rationale as F19 — nothing sensitive is ever logged because nothing sensitive is ever embedded in a message in the first place | `CredentialConfidentialityTest` | ✅ |
| F24 | Webhook schema validation | Payload is a single `id=` field; missing/empty id is rejected by `MollieWebhookProcessor::extractPaymentId()` before any API call | `WebhookSecurityParityTest::testF24_MalformedWebhookPayloadRejected`, `MollieWebhookProcessorTest::testParseAndValidate_WhenIdMissing_ThrowsSignatureException` (existing) | ✅ |
| F25 | Payment id constant consistency | `MollieDefinitions::MODULE_ID === MollieDefinitions::PAYMENT_ID`, both `'oe_payments_mollie'` | `MetadataTest::testMetadata_IdIsOePaymentsMollie` (existing), `MollieDefinitionsTest` (existing) | ✅ |

## Central validation subsystem (the item PayPal skipped)

`ValidationEndpointGuardParityTest` exercises payment-base's shared `oepaymentvalidationapi`
guard chain directly, using `MollieDefinitions::MODULE_ID` as the plugin id under test:

- `testValidationApi_RejectsNonPost` — `PostOnlyGuard` → 405.
- `testValidationApi_RejectsCrossOrigin` — `SameOriginGuard` → 403.
- `testValidationApi_RejectsMissingCsrf` — `CsrfTokenGuard` → 403.
- `testValidationApi_RejectsInactiveModuleId422` — `PluginIdAllowlistGuard` → 422 (a deactivated
  Mollie module must not be reachable by POSTing its module id).
- `testValidationApi_RateLimited` — `RateLimitGuard` → 429.

Mollie's own field-level rules (`src/Resources/validation-rules.php`) and `UserDataValidator` are
covered separately by `UserDataValidatorTest` and `ValidationRulesProviderTest` (Sprint 4 Story 6).

## Summary

- **Applicable findings: 17 of 25** covered with a dedicated regression test: F1–F5, F7, F8,
  F14, F15, F17–F21, F23–F25 — plus the central-validation subsystem test file (Sprint 4 Story
  6), which does not carry its own F-number but is the explicit parity item the sprint spec calls
  out as "the subsystem PayPal skipped."
- **N/A findings: 8** — F6, F9, F10, F11, F12, F13, F16, F22 — each with a concrete architectural
  reason in the table above, not a shrug.
- **One genuine gap closed this sprint:** F14 (open redirect). `MollieCheckoutSessionHandler`
  previously wrote the API-returned `checkoutUrl` straight onto the contract with no host check;
  `MollieRedirectUrlValidator` (new) now gates it to `mollie.com`/its subdomains before the
  browser is ever redirected there. Everything else in this matrix was already correct by
  construction — the remaining new test files formalise that coverage (mirrors PayPal sprint 21's
  own framing: "implemented inline during earlier sprints, this sprint's job was formalising").

## Out of scope for this sweep

- External penetration test (follow-up, post-launch, merchant/OXID responsibility).
- DAST/SAST against the full shop — handled at OXID core / CI level, not per-module.
- Mollie Dashboard / API-key rotation policy — merchant responsibility, documented in
  `docs/for_merchant/setup.md`.
