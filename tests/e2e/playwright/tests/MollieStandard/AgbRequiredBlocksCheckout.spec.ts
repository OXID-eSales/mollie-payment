import { test, expect } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    goToCheckoutPayment,
    selectMolliePaymentMethod,
    continueToOrderReview,
    pickRedirectMollieMethod,
} from '../../fixtures/shop-helpers';

/**
 * Regression: with `blConfirmAGB` active, "Order now" with the AGB checkbox UNCHECKED must
 * never hand off to Mollie's hosted checkout.
 *
 * Core `OrderController::execute()` enforces this via `validateTermsAndConditions()`, but
 * `MollieOrderController::execute()` intercepts the request for the Mollie payment id and
 * redirected to Mollie without running that guard — the customer could complete payment
 * without accepting the Terms and Conditions. The fix re-runs the core validation before
 * dispatching the checkout-session event; on rejection the order step re-renders with the
 * core `READ_AND_CONFIRM_TERMS` error, exactly like a non-Mollie payment.
 *
 * Precondition: `blConfirmAGB` enabled on the shop under test (the Apex order step then
 * renders the `#checkAgbTop` checkbox). If it is disabled the test skips loudly — a silent
 * pass would prove nothing.
 */
test.describe('Mollie standard checkout — AGB enforcement', () => {
    test('unchecked AGB blocks the Mollie redirect and re-renders the order step', async ({ page }) => {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await goToCheckoutPayment(page);
        await selectMolliePaymentMethod(page);
        await continueToOrderReview(page);

        const agbCheckbox = page.locator('#checkAgbTop, input[name="ord_agb"][type="checkbox"]').first();
        test.skip(
            (await agbCheckbox.count()) === 0,
            'blConfirmAGB is not active on the shop under test — enable it to run this regression',
        );
        await expect(agbCheckbox).not.toBeChecked();

        // Pick a redirect method so the form POSTs natively to cl=order&fnc=execute — the card
        // Components JS would intercept the submit and mask the server-side guard under test.
        const method = await pickRedirectMollieMethod(page);
        test.skip(method === 'card-only', 'only the card method is offered — the server-side guard cannot be exercised');

        const orderNowBtn = page.getByRole('button', {
            name: /zahlungspflichtig bestellen|place order|order now/i,
        });
        await orderNowBtn.click();
        await page.waitForLoadState('domcontentloaded');

        // Must NOT leave the shop for Mollie's hosted checkout.
        expect(page.url(), 'checkout left the shop despite unchecked AGB').not.toMatch(/mollie\.com/i);

        // Core re-renders the order step with the READ_AND_CONFIRM_TERMS error. Asserting the
        // AGB wording (not just any .alert-danger) also rules out a false green from
        // MOLLIE_CHECKOUT_UNAVAILABLE on a misconfigured shop.
        await expect(page.locator('.alert-danger').first()).toContainText(
            /AGB|Geschäftsbedingungen|terms and conditions/i,
        );

        // Still on the order-review step — the customer can tick the box and retry.
        await expect(orderNowBtn.first()).toBeVisible();
    });
});
