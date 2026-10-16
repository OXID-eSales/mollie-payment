# 2026-10-16

- Sprint MOL-32 (multi-capture support) — **IMPLEMENTED (S1–S8), ready for review; NOT merged**.
  Branch `b-7.4.x-MOL-32-multicapture`; plan `sprints/MOL-32-multicapture.md`, report
  `done/MOL-32-multicapture.md`. All 8 stories TDD red-first, gates green after each; Unit 802,
  Integration green, pre-commit-check.sh ALL PASSED. Closes MOL-31's five defects (OXPAID on partial,
  blocked second capture, silent capture failure, no release/"released" figure, docs). Default-off
  setting = no change for single-capture shops. Open: the money-path e2e
  (`MollieAdmin/MultiCaptureFlow.spec.ts`) needs a multi-capture-enabled Mollie profile + the setting
  on (skips unless `MOLLIE_E2E_MULTICAPTURE=1`).

- (original plan note) Multi-capture is now enabled on the Mollie account, which
  unblocks the MOL-31 (2026-10-12) finding — the sprint delivers MOL-31's "option A" and keeps
  "option B" (honest single-capture) as the behaviour when the new setting is off.
  - 8 stories, TDD/Clean-Code/SOLID/DRY, red-first with integration/e2e proof on every money path.
    Backbone: S1 fix `MolliePaymentDto::capturableAmount()` (returns refund-oriented `amountRemaining`
    for an authorized payment; under multi-capture the remainder is `amount − amountCaptured`) + S2 a
    single injected `CaptureCompletionPolicy` + `blMollieMultiCapture` setting (Mollie exposes no API
    flag), default off = today's behaviour, no regression.
  - Closes MOL-31's five defects: S3 partial capture no longer fulfils/stamps OXPAID; S4 subsequent
    captures allowed while a remainder is authorized; S5 capture failures reach the admin (reuses the
    MOL-30 message channel); S6 `release-authorization` (new service mirroring Capture/Refund); S7
    panel shows captured/remainder/released and gates the buttons; S8 docs + two e2e.
  - Key risk flagged for S1: the still-capturable-remainder field semantics must be confirmed by a
    characterization test against a live multi-capture profile (or Klarna/Billie) before the formula
    is trusted — MOL-31 only observed the single-capture path live.
