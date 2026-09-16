import { test, expect } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    goToCheckoutPayment,
    selectMolliePaymentMethod,
    continueToOrderReview,
    acceptTermsAndConditions,
} from '../../fixtures/shop-helpers';

/**
 * Regression (Sprint 02): "Order now" with a missing/invalid session challenge (CSRF token)
 * must never hand off to Mollie's hosted checkout.
 *
 * Core `OrderController::execute()` runs `$session->checkSessionChallenge()` as its FIRST
 * guard and returns null (silent re-render) on failure. `MollieOrderController::execute()`
 * intercepted the request for the Mollie payment id before that guard could run — a
 * cross-site form POST could trigger a Mollie checkout session for a logged-in customer.
 * The fix mirrors core: invalid challenge → silent null, zero side effects.
 *
 * The rejection is deliberately silent (core parity) — so this spec asserts the NEGATIVE
 * (never leaves the shop, order step re-renders), not an error message.
 */
test.describe('Mollie standard checkout — session-challenge (CSRF) enforcement', () => {
    test('blanked challenge blocks the Mollie redirect and re-renders the order step', async ({ page }) => {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await goToCheckoutPayment(page);
        await selectMolliePaymentMethod(page);
        await continueToOrderReview(page);

        await acceptTermsAndConditions(page);

        // Inline-components shops list the Mollie methods on the order page; the CARD radio
        // hands submit to the Components JS, masking the server-side path. Pick a redirect
        // method (PayPal, else any non-card) so the form POSTs natively to cl=order&fnc=execute.
        const methodRadios = page.locator('input[name="mollieMethod"]');
        if ((await methodRadios.count()) > 0) {
            const paypal = page.locator('input[name="mollieMethod"][value="paypal"]').first();
            const nonCard = page.locator('input[name="mollieMethod"]:not([value="creditcard"])').first();
            test.skip(
                (await nonCard.count()) === 0,
                'only the card method is offered — the Components JS intercepts submit client-side',
            );
            await ((await paypal.count()) ? paypal : nonCard).check({ force: true });
        }

        // Blank the CSRF token in EVERY order form. Session::checkSessionChallenge() compares
        // the session's sess_stoken against the `stoken` REQUEST parameter (NOT the `challenge`
        // hidden field — that one is the order id for sess_challenge).
        const blanked = await page.evaluate(() => {
            const inputs = document.querySelectorAll<HTMLInputElement>('input[name="stoken"]');
            inputs.forEach((el) => { el.value = ''; });
            return inputs.length;
        });
        test.skip(blanked === 0, 'no stoken field rendered — session-challenge protection is off on this shop');

        const orderNowBtn = page.getByRole('button', {
            name: /zahlungspflichtig bestellen|place order|order now/i,
        });
        await orderNowBtn.click();
        await page.waitForLoadState('domcontentloaded');

        // Must NOT leave the shop for Mollie's hosted checkout.
        expect(page.url(), 'checkout left the shop despite an invalid session challenge').not.toMatch(/mollie\.com/i);

        // Core-parity rejection is silent: the order step simply re-renders, still submittable.
        await expect(orderNowBtn.first()).toBeVisible();
    });
});
