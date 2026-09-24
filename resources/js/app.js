/**
 * Mollie Module — JavaScript entry point.
 *
 * Initializes Stimulus.js and registers the storefront checkout controller. Mirrors Stripe's
 * `resources/build/js/app.js` structure (esbuild + Stimulus), simplified for Mollie's smaller
 * storefront surface (no embedded card element — see mollie_checkout_controller.js docblock).
 */
import { Application } from '@hotwired/stimulus'

import MollieCheckoutController from './controllers/mollie_checkout_controller.js'
import MollieComponentsController from './controllers/mollie_components_controller.js'
import MolliePlaceOrderController from './controllers/mollie_place_order_controller.js'
import { createDebugLogger } from './debug.js'

// Reuse an already-started Stimulus application if another payment module's bundle started one
// on this page (e.g. Stripe's frontend also loads on the shared order page). Registering onto the
// live app connects our controllers immediately; starting a second app would clobber the first.
window.Stimulus = window.Stimulus || Application.start()
window.Stimulus.register('mollie-checkout', MollieCheckoutController)
window.Stimulus.register('mollie-components', MollieComponentsController)
window.Stimulus.register('mollie-place-order', MolliePlaceOrderController)

const mollieDebugEnabled = () => window.oMollie?.debug === true
const debug = createDebugLogger(mollieDebugEnabled)

debug('Mollie module: Stimulus initialized with controllers', window.Stimulus.router.modulesByIdentifier)
