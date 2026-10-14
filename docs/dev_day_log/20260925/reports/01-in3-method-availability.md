# Report 01: in3 is offered in some checkout sessions and missing in others

**Date:** 2026-09-25
**Ticket:** id tbd (in3 / Installments inconsistently available at the Mollie payment step)
**Status:** investigated, root cause identified by code trace + Mollie API matrix; no code changed.

## Ticket

Mollie is enabled, in3 is activated on the Mollie profile, the same products are in the basket.
The in3 tile is in the Mollie method list in some checkout sessions and absent in others; no
reproduction steps are known.

## Short answer

The list is deterministic. What differs between sessions is the **billing country the module sends
to Mollie's `/methods` call**, and that country comes from the *session user*:

| Session state at the moment the list is built | `billingCountry` sent | in3 in the list |
|---|---|---|
| No session user yet (OPC footer rendered before the address is saved), or user without country | none | **yes** |
| Session user with NL billing address | `NL` | yes |
| Session user with DE / AT / BE / … billing address | `DE` … | **no** |

Almost every account in this shop is German (397 DE, 20 AT, 16 US, 15 GB, 14 CH, 11 CA, 0 NL).
A logged-in customer therefore never sees in3, while a guest whose one-page checkout footer loaded
before the address existed does. Add to that a **silent fallback**: any error while fetching the
list yields `[]` with no log line, and the page quietly shows the classic redirect button instead
of the tile list, which also reads as "in3 missing".

## Evidence 1: Mollie's own answer (test profile `pfl_cVf8bSWdQV`, `GET /v2/methods`)

Probed 2026-09-25 with the shop's test key. `IN3` = in3 present in the response.

| Parameters | in3 |
|---|---|
| *(none — exactly what the module sends for a session without a user/country)* | IN3 |
| `billingCountry=NL` | IN3 |
| `billingCountry=DE`, `AT`, `BE` | no |
| `billingCountry=NL&locale=de_DE`, `locale=de_DE` alone, `locale=en_US` alone | IN3 (locale only reorders) |
| `amount=49.99 EUR & NL` | no |
| `amount=50.00 … 5000.00 EUR & NL` | IN3 |
| `amount=5000.01 EUR & NL` and above | no |
| `amount=100 USD & NL` | no |
| `sequenceType=first` | no |

So on this profile in3 is eligible for **NL billing (or no country given), EUR, 50.00–5000.00,
one-off**. Note `GET /methods/in3` reports `minimumAmount 0.00 / maximumAmount 30000.00`; the
real window is only applied when an amount is passed to the list call.

## Evidence 2: what the module sends (code trace)

1. There is **one** OXID payment row for Mollie (`oe_payments_mollie`, active, 0.01–999999, no
   country restriction; 4 delivery sets assigned). Per-method visibility is not an OXID setting;
   the module has no in3 switch at all (`oe_payments_mollie.yaml`: mode, capture mode, keys,
   profile id, webhook URL, log level).
2. The tile list is rendered in two places, both only in inline-components mode
   (`blPaymentBaseUseIframe` + profile id):
   - order step `views/twig/extensions/themes/default/page/checkout/order.html.twig:14-20`
     — `mollieMethods is empty` → classic redirect button;
   - OPC footer `src/Mollie/Component/Widget/MollieCheckoutFooter.php` (`getMollieConfig()`),
     rendered through OPC's `CheckoutApiController` → `FooterWidgetService::renderFooterWidget()`.
3. `ViewConfig::getMollieMethods()` (`src/Mollie/Core/ViewConfig.php:74-91`) →
   `PaymentMethodListService::listActiveMethods($currency, $country)` →
   `MollieMethodsFetcher::listActiveMethods()` → `$client->methods->allActive($params)`.
   - `$params` contains **only `billingCountry`** (locale is never set by the service). **No
     amount, no currency, no resource, no sequenceType** (`src/Mollie/Adapter/MollieMethodsFetcher.php:50-62`).
   - `$country` = `ViewConfig::mollieBillingCountryIso()` (`ViewConfig.php:111-132`):
     `Registry::getSession()->getUser()->oxcountryid` → `oxcountry.oxisoalpha2`; **null when there is
     no session user, no country id, or any exception** → the filter is dropped and Mollie returns
     the full activated list, in3 included.
   - The delivery address is never consulted.
4. `getMollieMethods()` catches every `Throwable` and returns `[]` **without logging**; the
   adapter has no logger either. A Mollie timeout / 5xx / 429 or a config-read error therefore
   turns the tile list into the redirect button for that one request, invisibly.
5. Caching is per request only (`PaymentMethodListService::$cache`, key `CURRENCY|COUNTRY`,
   request-scoped service). Nothing is kept in the session or OXID cache — a stale cache is ruled out.
6. On submit with in3 chosen, `CheckoutPaymentService::buildCreatePaymentRequest()`
   (`src/Mollie/Service/CheckoutPaymentService.php:38-80`) drops the method if the billing address
   is incomplete (logged as a warning "dropping the shopper's pay-later method …") and sends the
   shopper to Mollie's hosted page, which applies its own eligibility. Because the list never sends
   an amount, a 30 EUR or 6000 EUR basket still offers in3 and only fails later.
7. Git history: `1d53d09` (2026-07-30) put in3/Klarna/Riverty/Billie back on the inline selector;
   `cf76639` (2026-09-21) removed the manual-capture filter from the list. Neither touched the
   country/amount inputs. JS (`mollie_components_controller.js`) never hides method radios.

## Why the tester saw both outcomes

The two screenshots (2026-09-23 12:09 and 12:11) are consistent with one session where the list
was built without a session user (OPC footer loaded before the address was saved, or an account
without a country) and one where a German billing address was already in the session. Nothing
else in the pipeline varies per session. A transient Mollie API error is the second candidate; it
cannot be confirmed or excluded today because the failure is not logged.

## Assessment

- Mollie filtering by billing country is correct behaviour: in3 is a Dutch product. Hiding it for
  a DE address is **not** the bug.
- The bug is that the list does not reflect the shopper's real eligibility inputs consistently:
  - no country → everything is offered (in3 for a German guest);
  - no amount → in3 offered below 50 EUR / above 5000 EUR;
  - API failure → nothing offered, silently.
- The one-page checkout compounds it: the footer is rendered by an API call that can precede the
  address, and the list is not rebuilt when the address later changes (to verify in OPC — see the
  sprint plan, Story 4).

## Recommendation

See `../sprints/01-in3-method-availability.md`: send the basket amount + currency to `/methods`,
resolve the billing country from the address the shopper actually entered (session user, else the
OPC form / shop default) instead of silently sending nothing, log the `/methods` inputs, outputs
and failures, and rebuild the OPC footer list when the address changes. Red e2e proof first.
