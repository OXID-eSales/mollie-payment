import { test, expect } from '@playwright/test';
import {
    loginStorefront, addFirstFeaturedProductToBasket, goToCheckoutPayment,
    selectMolliePaymentMethod, continueToOrderReview, setAgbChecked,
} from '../../fixtures/shop-helpers';
import { fillComponentsField, TEST_CARD } from '../../fixtures/mollie-inline-card';

const OUT = process.env.PROBE_OUT || '';

/**
 * Standard order step, Mollie selected, inline Components on: the method list is a card on the
 * same layer as the Summary and the Agreements beside it (same `card`, same header classes as the
 * order sections), the card-number field shows the shop's `0000 0000 0000 0000` placeholder until
 * the shopper types, and the card-holder label is not marked optional (Mollie requires the holder).
 */
test.describe('Mollie order page — method list as a card, card-number placeholder', () => {
    test('the method card sits on the same layer as the Summary card and the card fields carry the placeholder', async ({ page }) => {
        test.setTimeout(180_000);
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await goToCheckoutPayment(page);
        await selectMolliePaymentMethod(page);
        await continueToOrderReview(page);
        await expect(page).toHaveURL(/cl=order/);

        const methodCard = page.locator('[data-testid="mollie-method-card"]');
        await expect(methodCard, 'the Mollie block is a card').toHaveClass(/\bcard\b/);
        const header = methodCard.locator('h2.h4.card-header.card-title');
        await expect(header, 'the header is a card header like the other sections').toBeVisible();
        await expect(header).toHaveText(/Zahlungsart wählen|Choose your payment method|^\s*Zahlungsart\s*$|^\s*Payment method\s*$/);
        await expect(methodCard.locator('.card-body input[name="mollieMethod"]').first(), 'the methods are inside the card body').toBeAttached();

        const summary = page.locator('.col-lg-5 .card').first();
        const [summaryBox, cardBox] = await Promise.all([summary.boundingBox(), methodCard.boundingBox()]);
        expect(summaryBox && cardBox, 'both cards render').toBeTruthy();
        expect(Math.abs(cardBox!.x - summaryBox!.x), 'same column edge as the Summary card').toBeLessThanOrEqual(1);
        expect(Math.abs(cardBox!.width - summaryBox!.width), 'same width as the Summary card').toBeLessThanOrEqual(1);

        if (await page.locator('#checkAgbTop').count()) await setAgbChecked(page, true);
        const card = page.locator('input[name="mollieMethod"][value="creditcard"]');
        await expect(card, 'the Card method must be offered (inline Components flow)').toHaveCount(1);
        await card.check();
        for (const field of Object.keys(TEST_CARD)) {
            await expect(page.locator(`iframe[name="${field}-input"]`)).toBeAttached({ timeout: 20_000 });
        }
        await page.waitForTimeout(3000);

        const numberMount = page.locator('[data-mollie-components-target="cardNumber"]');
        await expect(numberMount).toHaveAttribute('data-placeholder', '0000 0000 0000 0000');
        const before = () => numberMount.evaluate((el) => getComputedStyle(el, '::before').content);
        expect(await before(), 'the placeholder is drawn while the field is empty').toContain('0000 0000 0000 0000');
        await expect(page.locator('[data-testid="mollie-card-holder-label"]'), 'the card holder is required by Mollie — never labelled optional').not.toContainText(/optional/i);
        if (OUT) await page.locator('.col-lg-5').first().screenshot({ path: `${OUT}/10-method-card-empty.png` });

        await fillComponentsField(page, 'cardNumber', TEST_CARD.cardNumber);
        await page.waitForTimeout(500);
        await expect(numberMount, 'typing marks the mount as filled').toHaveClass(/\bis-filled\b/);
        expect(await before(), 'the placeholder is gone once the shopper typed').toBe('none');
        const frame = page.frames().find((f) => f.name() === 'cardNumber-input');
        const typed = frame ? await frame.evaluate(() => (document.querySelector('input') as HTMLInputElement | null)?.value ?? '') : '';
        console.log('CARD_NUMBER_AS_DISPLAYED', JSON.stringify(typed));
        expect(typed.replace(/\s+/g, ''), 'all digits arrived in Mollie\'s input').toBe(TEST_CARD.cardNumber);
        if (OUT) await page.locator('.col-lg-5').first().screenshot({ path: `${OUT}/11-method-card-typed.png` });

        // Why the holder is not "(optional)": with every other field valid Mollie refuses to tokenize
        // without it, and the shopper stays on the order page with the error.
        await fillComponentsField(page, 'expiryDate', TEST_CARD.expiryDate);
        await fillComponentsField(page, 'verificationCode', TEST_CARD.verificationCode);
        await page.getByRole('button', { name: /order now|zahlungspflichtig bestellen|place order/i }).first().click();
        const error = page.locator('[data-mollie-components-target="error"]');
        await expect(error, 'Mollie refuses a card without a holder').toBeVisible({ timeout: 15_000 });
        await expect(error).toContainText(/ungültig|invalid/i);
        await expect(page).toHaveURL(/cl=order/);
        console.log('CARD_HOLDER_COMPONENT_CLASSES', await page.locator('[data-mollie-components-target="cardHolder"] .mollie-component').getAttribute('class'));
        if (OUT) await page.screenshot({ path: `${OUT}/12-submit-without-holder.png`, fullPage: true });
    });
});
