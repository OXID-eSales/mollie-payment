import { test, expect } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    goToCheckoutPayment,
    selectMolliePaymentMethod,
} from '../../fixtures/shop-helpers';

/**
 * CI/manual-run-only — see README.md. Not run as part of this sprint's PHP quality gates.
 *
 * Regression: frontend console logging must be GATED by the module log level (Sprint 8 Story 2).
 *
 * `ViewConfig::isMollieDebugLoggingEnabled()` is true only when `sMollieLogLevel === 'debug'`,
 * surfaced to the Stimulus controller as `data-mollie-checkout-debug-value` (see
 * `views/twig/extensions/themes/default/page/checkout/payment.html.twig`). Unless the level is
 * explicitly `debug`, the module must emit NO `console.log` — mirrors Stripe's regression pattern
 * (`tests/e2e/.../StripeStandard/FrontendLoggingGated.spec.ts`) and its "dev domain accidentally
 * forces verbose logging" lesson.
 *
 * Precondition: the shop under test is NOT at log level `debug` (default seed = `errors`).
 * `console.error` is intentionally exempt — genuine errors are never gated (see `debug.js`).
 */

// Logs emitted by Mollie-module code (app.js entry + the checkout controller). Exact strings
// come from resources/js/app.js ("Mollie module: ...") and
// resources/js/controllers/mollie_checkout_controller.js ("Mollie checkout controller
// connected", "Mollie method selected").
const MOLLIE_EMITTED = /Mollie module:|Mollie checkout controller|Mollie method selected/i;

test.describe('Frontend logging gated by log level (feature OFF)', () => {
    test('standard checkout with Mollie selected emits no Mollie-module console.log', async ({ page }) => {
        const logs: string[] = [];
        page.on('console', (message) => {
            // console.error is intentionally exempt — genuine errors are never gated.
            if (message.type() === 'log' || message.type() === 'debug' || message.type() === 'warning') {
                logs.push(message.text());
            }
        });

        // cl=payment bounces anonymous sessions to the login step — the walk stalls there
        // and the payment radios (whose Stimulus controller is under test) never render.
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await goToCheckoutPayment(page);
        await selectMolliePaymentMethod(page, 'ideal');

        const mollieLogs = logs.filter((text) => MOLLIE_EMITTED.test(text));

        expect(
            mollieLogs,
            `Mollie-module console output leaked while logging is off:\n${mollieLogs.slice(0, 15).join('\n')}`,
        ).toHaveLength(0);
    });
});
