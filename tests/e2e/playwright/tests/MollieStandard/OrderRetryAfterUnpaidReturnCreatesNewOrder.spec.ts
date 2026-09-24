import { test, expect, type Page } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    goToCheckoutPayment,
    selectMolliePaymentMethod,
    continueToOrderReview,
    acceptTermsAndConditions,
    pickRedirectMollieMethod,
    completeMollieTestPayment,
} from '../../fixtures/shop-helpers';
import { readThankYouOrderNumber } from '../../fixtures/mollie-authorized-helpers';
import { allOrderIds, ordersAddedSince, phantomOrderCount } from '../../fixtures/shop-db';

/**
 * MOL-18 Story 6 — the legitimate retry keeps working once the in-flight replay exists.
 *
 * A shopper comes back from Mollie unpaid (payment failed), the return leg retires the attempt
 * (contract cancelled, order CANCELLED / storno - the row is kept for a gap-free number sequence)
 * and they order again. That second "Order now" must create a NEW order: the retired one is not
 * "in flight" (its order is no longer NOT_FINISHED), and payment-base forgets the session's
 * `sess_challenge` when it retires an attempt, so core does not answer ORDEREXISTS for the row it
 * kept. Before MOL-18 that retry only "worked" by writing a phantom order.
 *
 * Expected afterwards: exactly two rows added - the retired one (storno) and the paid one, both
 * real orders with articles - and no row without a payment type.
 */
test.describe('MOL-18 — ordering again after an unpaid return creates a new order', () => {
    test('failed Mollie payment, return, "Order now" again -> a second real order, no phantom', async ({ page }) => {
        const baseline = allOrderIds();
        const phantomsBefore = phantomOrderCount();

        // Attempt 1: leave for Mollie and fail the payment there.
        await reachOrderPage(page, { fresh: true });
        await clickOrderNow(page);
        await completeMollieTestPayment(page, 'failed');
        await page.waitForURL((url) => !/mollie\.com$/i.test(url.hostname), { timeout: 45_000 });
        await page.waitForLoadState('domcontentloaded');
        expect(page.url(), 'an unpaid return must not land on the thank-you page').not.toMatch(/cl=thankyou/i);

        // Attempt 2: same basket, order again, pay.
        await reachOrderPage(page, { fresh: false });
        await clickOrderNow(page);
        await completeMollieTestPayment(page, 'paid');
        await page.waitForURL((url) => !/mollie\.com$/i.test(url.hostname), { timeout: 45_000 });
        await page.waitForLoadState('networkidle').catch(() => {});
        const orderNumber = await readThankYouOrderNumber(page);

        const added = ordersAddedSince(baseline).sort((a, b) => Number(a.orderNr) - Number(b.orderNr));
        const summary = added.map((o) => `#${o.orderNr} payment='${o.paymentType}' storno=${o.storno} ` +
            `status=${o.transStatus} total=${o.totalOrderSum} articles=${o.articleCount}`).join('\n');

        expect(added, `two attempts must leave exactly two real orders, got:\n${summary}`).toHaveLength(2);
        const [retired, paid] = added;
        expect(retired.storno, 'the unpaid attempt is retired (storno)').toBe('1');
        expect(retired.transStatus).toBe('CANCELLED');
        expect(retired.articleCount).toBeGreaterThan(0);
        expect(paid.storno, 'the retry is a live order').toBe('0');
        expect(paid.paymentType).not.toBe('');
        expect(paid.articleCount).toBeGreaterThan(0);
        expect(phantomOrderCount(), 'no new payment-less order row').toBe(phantomsBefore);

        expect(page.url(), 'the shopper must land on the thank-you page').toMatch(/cl=thankyou/i);
        expect(orderNumber, 'thank-you page must show the number of the retried order').toBe(paid.orderNr);
    });
});

async function reachOrderPage(page: Page, { fresh }: { fresh: boolean }): Promise<void> {
    if (fresh) {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await goToCheckoutPayment(page);
        await selectMolliePaymentMethod(page, 'paypal');
        await continueToOrderReview(page);
    } else {
        // The unpaid return lands on the payment step with the basket and payment still in the
        // session; the order step is one navigation away.
        await page.goto('/index.php?cl=order');
        await page.waitForLoadState('domcontentloaded');
    }
    await acceptTermsAndConditions(page);
    const method = await pickRedirectMollieMethod(page);
    test.skip(method === 'card-only', 'only the card method is offered — the redirect path cannot run');
}

async function clickOrderNow(page: Page): Promise<void> {
    await page.getByRole('button', { name: /order now|zahlungspflichtig bestellen|place order/i }).first().click();
    await page.waitForURL(/mollie\.com/i, { timeout: 45_000 });
}
