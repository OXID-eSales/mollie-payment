# GRAPH-QL / MS2 — Wiring and the temporary CI pin (DONE 2026-10-06)

**Sprint:** [../sprints/GRAPH-QL-mollie-provider-story.md](../sprints/GRAPH-QL-mollie-provider-story.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## What changed

| Piece | Job |
|---|---|
| `services.yaml` — `MollieReturnResolver` | `tags: [ { name: oe.payment.return_resolver, provider: mollie } ]`: payment-base's `ReturnResolverRegistry` finds it for `mollieCheckoutReturn`. The Twig controller keeps resolving the concrete class directly |
| `services.yaml` — `EarlyOrderCreationHandler` | `$openAttemptFinder: '@…\Repository\OpenAttemptFinderInterface'`: a headless attempt has no session, its previous attempt is the open contract of the same user basket (payment-base S3). Priority 90 kept (Stripe's definition of the same id says 100; whichever module's file merges last wins — both pass the same arguments now) |
| `.github/workflows/mollie-tests-oxid7.{4,5}.yml` | **TEMPORARY:** `PAYMENT_BASE_BRANCH: 'b-7.4.x-GRAPH-QL'` + `PAYMENT_BASE_VERSION_ALIAS: ' as 1.2.x-dev'` on all four `composer require` lines (shop level and module level, since `composer.json` requires `>=v1.2`). Same trick as Stripe `f164517`; the old comment "a feature branch cannot be pinned" was true only without the alias. Revert after payment-base merges |

## Proof

Container rebuilt (`var/cache/container` cleared) on the dev shop: `ReturnResolverRegistry` answers
`MollieReturnResolver` for `mollie`, `PaymentHandlerRegistry` answers `MolliePaymentHandler` for `oe_payments_mollie`.
Integration suite (shop PHPUnit) still 38 green. CI: see status.
