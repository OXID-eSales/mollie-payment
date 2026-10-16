# 2026-10-16

- Sprint MOL-32 (multi-capture support) — **SPRINT PLAN written, nothing implemented**:
  `sprints/MOL-32-multicapture.md`. Multi-capture is now enabled on the Mollie account, which
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
