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
 * MOL-18 — "Order now" clicked several times on the standard order page.
 *
 * Expected: one submission is processed, every further click while it is in flight is answered
 * with the same redirect to Mollie, and exactly one order exists afterwards.
 *
 * Today (root cause, established in the sprint plan): the clicks are serialised by PHP's file
 * session lock. Click 2 creates a new contract, payment-base retires the first attempt (contract
 * cancelled, order CANCELLED / storno) and then core's finalizeOrder() answers ORDEREXISTS for
 * the same sess_challenge — which OxidShopOrderService treats as success and saves a never-loaded
 * Order: a second row with no user, no articles, no payment type. The shopper pays for that.
 *
 * How the double click is reproduced: two `form.requestSubmit()` calls in the same event-loop
 * tick collapse into ONE navigation per the HTML spec (the second planned navigation replaces the
 * first), so a synthetic dblclick would not exercise the server at all. A human's two clicks are
 * two POSTs that reach PHP one after the other; `page.request.post()` (same browser context, same
 * session cookie) sends exactly those two POSTs with the order form's own fields, and the browser
 * then follows the second response — which is what a real browser does with the last submission.
 *
 * Order counting goes through the shop DB (fixtures/shop-db.ts): the admin list renders storno'd
 * rows and payment-less rows just like real orders, so it cannot tell one order from three.
 *
 * Environment: OPC's `oeOnePageCheckoutEnabled` must be off for MollieStandard specs (see
 * reports/01-order-shipping-address-from-billing.md of 2026-09-23); Mollie sandbox key configured.
 *
 * Story 1 of sprint MOL-18: RED on the order-count assertion until Stories 2-4 land.
 */
test.describe('MOL-18 — "Order now" clicked twice creates exactly one order', () => {
    test('two rapid "Order now" submissions create exactly one order and share one Mollie redirect', async ({ page }) => {
        const baseline = allOrderIds();
        const phantomsBefore = phantomOrderCount();

        await reachOrderPageWithRedirectMethod(page);
        const { action, fields } = await readOrderForm(page);

        // Click 1 and click 2, one after the other, exactly as the server sees them.
        const first = await page.request.post(action, { form: fields, maxRedirects: 0 });
        const second = await page.request.post(action, { form: fields, maxRedirects: 0 });

        expect(first.status(), 'first submission must redirect to Mollie').toBe(302);
        const firstLocation = first.headers()['location'] ?? '';
        expect(firstLocation).toMatch(/mollie\.com/i);

        expect(second.status(), 'second submission must redirect (not error out)').toBe(302);
        const secondLocation = second.headers()['location'] ?? '';
        expect(secondLocation).toMatch(/mollie\.com/i);

        // The browser follows the last submission. Pay it, then wait to be back in the shop. The
        // landing page is asserted AFTER the order count: today the shopper pays for a phantom
        // order and the thank-you page, unable to render an order without articles, falls back
        // to the start page - a symptom, not the defect.
        await page.goto(secondLocation);
        await completeMollieTestPayment(page, 'paid');
        await page.waitForURL((url) => !/mollie\.com$/i.test(url.hostname), { timeout: 45_000 });
        await page.waitForLoadState('networkidle').catch(() => {});
        const body = await page.locator('body').innerText();
        const orderNumber = await readThankYouOrderNumber(page);

        const added = ordersAddedSince(baseline);
        const summary = added.map((o) => `#${o.orderNr} payment='${o.paymentType}' storno=${o.storno} ` +
            `status=${o.transStatus} total=${o.totalOrderSum} articles=${o.articleCount}`).join('\n');

        // The ticket's expectation. Today: two rows - the first attempt (CANCELLED, storno) and
        // the phantom the second click paid for (no payment type, no articles, total 0).
        expect(added, `exactly one order must exist for one checkout attempt, got:\n${summary}`).toHaveLength(1);
        const order = added[0];
        expect(order.storno, 'the one order must not be storno\'d').toBe('0');
        expect(order.paymentType, 'the one order must carry the Mollie payment type').not.toBe('');
        expect(order.articleCount, 'the one order must contain the basket articles').toBeGreaterThan(0);
        expect(phantomOrderCount(), 'no new payment-less order row').toBe(phantomsBefore);

        expect(body).not.toMatch(/MOLLIE_CHECKOUT_UNAVAILABLE|MOLLIE_RETURN_/i);
        expect(page.url(), 'the shopper must land on the thank-you page').toMatch(/cl=thankyou/i);
        expect(orderNumber, 'thank-you page must show the order number of the one order').toBe(order.orderNr);

        // "Additional clicks should not trigger further submissions": the second click is answered
        // with the first attempt's Mollie checkout, not a new payment.
        expect(secondLocation, 'the second submission must replay the first Mollie redirect').toBe(firstLocation);
    });
});

async function reachOrderPageWithRedirectMethod(page: Page): Promise<void> {
    await loginStorefront(page);
    await addFirstFeaturedProductToBasket(page);
    await goToCheckoutPayment(page);
    await selectMolliePaymentMethod(page, 'paypal');
    await continueToOrderReview(page);
    await acceptTermsAndConditions(page);
    const method = await pickRedirectMollieMethod(page);
    test.skip(method === 'card-only', 'only the card method is offered — the server-side submit path cannot run');
}

/** The core order form exactly as the "Order now" button would submit it (incl. `form=`-associated fields). */
async function readOrderForm(page: Page): Promise<{ action: string; fields: Record<string, string> }> {
    return page.evaluate(() => {
        const form = document.getElementById('orderConfirmAgbBottom') as HTMLFormElement | null;
        if (!form) {
            throw new Error('order form #orderConfirmAgbBottom not found on the order page');
        }
        const fields: Record<string, string> = {};
        new FormData(form).forEach((value, key) => {
            fields[key] = String(value);
        });
        return { action: form.action, fields };
    });
}
