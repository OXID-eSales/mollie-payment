# F10 resolved — the delivery-address hash is passed through, not forged

**Date:** 2026-09-03
**Decision owner:** operator (owns the OPC integration Sprint 11 was waiting on)
**Supersedes:** `docs/dev_day_log/20260812/reports/01-unnecessary-and-dangerous-fallbacks.md` — F10

## What Sprint 11 deferred

`MolliePaymentHandler::prepareOxidBasket()` wrote

```php
$_POST['sDeliveryAddressMD5'] = $user->getEncodedDeliveryAddress();
```

F10 named this as forging: writing the *current* encoded address server-side makes OXID's guard
compare a value with itself, so a delivery address that really changed between the address step and
finalisation could never be caught. The fix was deferred because the correct behaviour — pass the
hash through from the OPC request — "changes one-page-checkout's contract and needs a decision from
whoever owns that integration".

`DeliveryAddressHashForgingKnownIssueTest` pinned the behaviour so it could not be forgotten.

## Why it stopped being theoretical

The forged value is not merely self-comparing, it is INCOMPLETE.
`Order::validateDeliveryAddress()` (Order.php:2098) builds the expected value as

```php
$sDeliveryAddress = $oUser->getEncodedDeliveryAddress();
$oDeliveryAddress = $this->getDelAddressInfo();          // from deladrid
if ($blFieldsValid && $oDeliveryAddress) {
    $sDeliveryAddress .= $oDeliveryAddress->getEncodedDeliveryAddress();
}
```

so whenever a delivery address row is selected the expected hash is 64 characters and the forged
one is 32. Measured on the local shop for a guest who had just gone through OPC (`dff@wp.pl`):

| | value | length |
|---|---|---|
| submitted by the handler | `42ce5cac3ff87aa8ec84090e20537a05` | 32 |
| expected by core | `42ce5cac3ff87aa8ec84090e20537a05477ebb5de0f9ec70d1d5cd251bf5644f` | 64 |

`RequiredFieldsValidator` passed on both the billing set (`oxuser__*`) and the delivery set
(`oxaddress__*`), so `$blFieldsValid` was true and the hash mismatch was the sole remaining cause
of `finalizeOrder` returning state 7 (`ORDER_STATE_INVALIDDELADDRESSCHANGED`).

The line was harmless only while no `oxaddress` row existed. OPC-156 (v1.5.113) began writing one
via `DeliveryAddressService::persistDeliveryAddressRow`, and OPC-217 (v1.6.49–1.6.52) made one
exist in nearly every flow — including a guest with "use delivery address as billing address"
ticked, and a form restored from the localStorage draft. From then on the short hash was
guaranteed wrong and Mollie could not finalise an order at all.

Invoice was unaffected because `StandardPaymentHandler` never overwrites the hash: it runs on
OPC's value, which is computed correctly.

## The fix

Delete the assignment. OPC already puts the right value in place —
`CheckoutService::prepareDeliveryAddressMd5()` computes user-plus-row and injects it at
`CheckoutService.php:110`, immediately before the handler is invoked at `:113`. Overwriting it was
the entire bug, and removing the overwrite restores the guard at the same time.

`setBasketUser($user)` stays; that half of the old branch was legitimate.

The standard `cl=order` flow is untouched — there the hash comes from the order form, and
`prepareOxidBasket()` is only reached on the OPC path.

## Tests

- `DeliveryAddressHashForgingKnownIssueTest` — deleted, as its own failure message instructed.
- `DeliveryAddressHashPassThroughTest` — added. Asserts the assignment is absent (by regex, so the
  docblock's mentions of the key do not satisfy it) and that `setBasketUser` survives.

## Still open

Whether OXID's guard now fires for a *legitimate* address change mid-checkout on the OPC path is
untested. That is the behaviour F10 wanted restored, and it should be exercised deliberately rather
than assumed: change the delivery address after `processCheckout` and confirm state 7 appears.
