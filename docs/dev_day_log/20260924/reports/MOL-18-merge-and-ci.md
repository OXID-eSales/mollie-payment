# MOL-18 — Merge and CI report

**Date:** 2026-09-24
**Ticket:** MOL-18 — one order per checkout attempt
**Related:** `MOL-18-single-order-per-checkout-attempt.md` (implementation report), `../done/MOL-18-single-order-per-checkout-attempt.md`

## What was merged

| Repo | Branch | Into | Merge commit | Mode |
|---|---|---|---|---|
| payment-base | `b-7.4.x-MOL-18-single-order-per-checkout-attempt` (`3e4e690`, `ca445b6`, `38b0bd4`, `7f70ede`) | `b-7.4.x` | `4b59482` | `--no-ff` |
| mollie-payment | `b-7.4.x-mol-10-18-single-click-and-order-state` (`512a89f` … `70d5bd5`) | `b-7.4.x` | see `../status.md` | `--no-ff` |

payment-base had to land first: Mollie's `composer.json` requires `oxid-esales/payment-base >=v1.2`, and
only `b-7.4.x` satisfies that (Packagist aliases it `1.2.x-dev`).

## CI

### payment-base, merge commit `4b59482` on `b-7.4.x`

| Workflow | Run | Result |
|---|---|---|
| Secret scan | 35979806181 | success |
| Tests on OXID 7.4 | 35979806165 | success |
| Tests on OXID 7.5 | 35979806260 | success |

The same three workflows were already green on the feature branch tip `7f70ede`.

### mollie-payment, `70d5bd5` on the feature branch

| Workflow | Run | Result |
|---|---|---|
| Secret scan | 35980284333 | success |
| Mollie full tests OXID CE 7.4 | 35980284588 | success |
| Mollie full tests OXID CE 7.5 | 35980284325 | success |

Runs: `https://github.com/OXID-eSales/<repo>/actions/runs/<id>`.

## The failed run before it, and why

Commit `2e97e8f` (docs + CI pin) failed both full-test workflows in the step *Install dependencies,
reset shop, activate theme*:

```
oxid-esales/mollie-payment dev-b-7.4.x-mol-10-18-single-click-and-order-state requires
oxid-esales/payment-base >=v1.2 -> satisfiable by oxid-esales/payment-base[dev-b-7.4.x, v1.2.0, ...,
1.2.x-dev (alias of dev-b-7.4.x)] from composer repo (https://repo.packagist.org) but
oxid-esales/payment-base[dev-b-7.4.x-MOL-18-single-order-per-checkout-attempt] from path repo
(./payment-base) has higher repository priority. The packages from the higher priority repository do
not match your constraint and are therefore not installable.
```

The workflow's `PAYMENT_BASE_BRANCH` had been pinned to the payment-base feature branch so Mollie CI
could test the replay against the resolver before payment-base merged. That cannot work: a path
repository of a feature branch carries no version alias, so `>=v1.2` is unsatisfiable. The fix was to
merge payment-base into `b-7.4.x` and set the pin back (`70d5bd5`), which is what the branch needed
anyway; the workflow comment now says why a feature-branch pin is not an option.

**Rule for co-evolving changes:** land payment-base in `b-7.4.x` first, then run Mollie CI.

## Verification before merging Mollie

- Full `mollie-standard` e2e project against the local stack: 11 passed, 2 Klarna specs skipped
  (env-gated, pre-existing), plus the 3 new MOL-18 tests green (see the implementation report).
- payment-base: Unit 1367, full Integration 130 (1 skipped), PHPCS, PHPMD clean; PHPStan unchanged.
- mollie-payment: Unit 649, PHPCS, PHPStan, PHPMD clean; standalone unit suite ran in CI (green).
- Local DB: 0 phantom `oxorder` rows since Story 2 landed.

## Left open

- Merging is done for both repos; no release tagged.
- Follow-ups from the implementation report: OPC server-side parity, Stripe/PayPal replay glue,
  cleanup of the 55 phantom rows (merchant decision).
