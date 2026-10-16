# Done — MOL-32 multi-capture support (2026-10-16)

Branch `b-7.4.x-MOL-32-multicapture` off `b-7.4.x`. Implements the MOL-31 (2026-10-12) "option A"
now that Mollie has enabled multi-capture on the account; "option B" (honest single-capture) is the
behaviour when the new setting is off. **Not merged — awaiting review.**

## Stories (all TDD, red-first; gates green after each)

- **S1** `MolliePaymentDto::capturableAmount()` = `amount − amountCaptured` while `authorized`
  (Mollie's `amountRemaining` is refund-oriented — the old formula offered the whole authorization
  for capture again after a partial capture). Commit `1aa6bcb`.
- **S2** `CaptureCompletionPolicy` (+ `CaptureCompletion` enum, interface) and the `blMollieMultiCapture`
  setting (en/de, default off): the single-vs-multi decision in one injected place. `98c7159`.
- **S3** a partial capture no longer fulfils / stamps OXPAID — `CaptureService` consults the policy;
  only the completing capture (or any capture on a single-capture account) fulfils. `0dc7a47`.
- **S4** subsequent captures allowed while a remainder is authorized (the contract never becomes
  FULFILLED mid-way); over-remainder rejected via the S1 bound. `b64b425`.
- **S5** a refused capture reaches the admin via the MOL-30 channel (`MOLLIE_ADMIN_CAPTURE_FAILED`),
  no longer swallowed. `a91dc7e`.
- **S6** `ReleaseAuthorizationService` (+ adapter `releaseAuthorization`, event, handler, admin
  dispatch via `MollieExtraActionDispatcherInterface` straight to the shared dispatcher — release
  has no payment-base-generic event). `1a60199`.
- **S7** panel distinguishes capture/release/cancel (cancel pre-capture only, release after a partial
  capture) and shows captured / remainder / released. `86a7fd4`.
- **S8** merchant + developer docs; e2e `MollieAdmin/MultiCaptureFlow.spec.ts` (two-part capture;
  partial-then-release). This commit.

## Closes MOL-31's defects

1 (OXPAID on partial) → S3 · 2 (second capture blocked) → S4 · 3 (silent capture failure) → S5 ·
4 (no release / no "released" figure) → S6+S7 · 5 (docs) → S8.

## Proof

- Unit **802** green in both configs; Integration green (panel renders through the real twig bridge);
  `./bin/pre-commit-check.sh` ALL PASSED after every story.
- Mollie API semantics for S1 confirmed against the live get-payment reference
  (`amountRemaining` = "remaining amount that can be refunded"), not assumed.

## Open before merge (honest)

- **e2e not run green here**: the money-path e2e needs a multi-capture-enabled Mollie profile +
  `blMollieMultiCapture` on. The spec `test.skip()`s unless `MOLLIE_E2E_MULTICAPTURE=1` (it would
  false-fail on a single-capture account, where the first partial capture is final by design). Run it
  on a multi-capture-ready shop to complete the proof.
- Default off = no behaviour change for existing single-capture shops (every pre-existing capture
  test stayed green), so the branch is safe to merge even before that e2e runs.
