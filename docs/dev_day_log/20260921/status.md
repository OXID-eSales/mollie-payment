# 2026-09-21

- Manual capture per method — DONE. See `reports/01-manual-capture-per-method.md`.
  - Manual mode no longer filters the inline selector; `CheckoutPaymentService::captureModeFor()`
    asks for manual capture only for card/BNPL, instant methods are created automatic (Mollie 422s
    otherwise); no pinned method → mode passed through (Mollie's hosted page drops it itself).
  - Unit RED→GREEN (5 new/changed tests); gate ALL PASSED (Unit 641/641).
  - Live on the manual-mode dev shop: selector offers 10 methods; PayPal → `paid`, captureMode null;
    card → `authorized`, captureMode manual, amountCaptured 0.00.
  - E2E helpers tolerate the skipped payment step; 4 MollieStandard specs green.
- OPC footer — manual capture per method + one-option rule — DONE. See `reports/02-opc-footer-manual-capture.md`.
  - Footer inherits the per-method capture decision (same service + handler); template now names a
    single method read-only (`MollieFooterSingleMethodTemplateTest` 2/2). Live: footer offers 10
    methods on the manual-mode shop; PayPal via OPC `processCheckout` → paid, captureMode null → thank-you.
  - **OPC defect found and fixed** (one-page-checkout `b-7.4.x-opc-fold-keeps-payment-execution`,
    CI green, merge pending Daniil's go): single method + iframe mode folded `payment-execution` and hid
    every provider footer; now only the picker folds. All 4 Mollie OPC specs GREEN live on that build.
  - Standard-checkout specs need OPC disabled (replacement mode overlays the classic checkout).
  - Shared e2e helpers: `openOpcCheckoutModal()`, `waitForOpcPaymentState()`, `opcPaymentSectionFolded()`.
