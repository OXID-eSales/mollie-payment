/**
 * Mollie Module — JavaScript entry point.
 *
 * Initializes Stimulus.js and registers the storefront checkout controller. Mirrors Stripe's
 * `resources/build/js/app.js` structure (esbuild + Stimulus), simplified for Mollie's smaller
 * storefront surface (no embedded card element — see mollie_checkout_controller.js docblock).
 */
import { Application } from '@hotwired/stimulus'

import MollieCheckoutController from './controllers/mollie_checkout_controller.js'
import { createDebugLogger } from './debug.js'

window.Stimulus = Application.start()
window.Stimulus.register('mollie-checkout', MollieCheckoutController)

const mollieDebugEnabled = () => window.oMollie?.debug === true
const debug = createDebugLogger(mollieDebugEnabled)

debug('Mollie module: Stimulus initialized with controllers', window.Stimulus.router.modulesByIdentifier)
