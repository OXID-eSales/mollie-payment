/**
 * Mollie Module — JavaScript entry point.
 *
 * Initializes Stimulus.js and registers the storefront checkout controllers. Mirrors Stripe's
 * `resources/build/js/app.js` structure (esbuild + Stimulus), simplified for Mollie's smaller
 * storefront surface. (2026-10-14: the dead `mollie-checkout` controller — its host templates
 * were deleted long ago — left the bundle, review finding B4.)
 */
import { Application } from '@hotwired/stimulus'

import MollieComponentsController from './controllers/mollie_components_controller.js'
import MolliePlaceOrderController from './controllers/mollie_place_order_controller.js'
import MollieAgbGateController from './controllers/mollie_agb_gate_controller.js'
import { createDebugLogger } from './debug.js'

// Reuse an already-started Stimulus application if another payment module's bundle started one
// on this page (e.g. Stripe's frontend also loads on the shared order page). Registering onto the
// live app connects our controllers immediately; starting a second app would clobber the first.
window.Stimulus = window.Stimulus || Application.start()
window.Stimulus.register('mollie-components', MollieComponentsController)
window.Stimulus.register('mollie-place-order', MolliePlaceOrderController)
window.Stimulus.register('mollie-agb-gate', MollieAgbGateController)

const mollieDebugEnabled = () => window.oMollie?.debug === true
const debug = createDebugLogger(mollieDebugEnabled)

debug('Mollie module: Stimulus initialized with controllers', window.Stimulus.router.modulesByIdentifier)
