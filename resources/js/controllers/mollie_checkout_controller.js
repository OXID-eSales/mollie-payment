import { Controller } from '@hotwired/stimulus'
import { createDebugLogger } from '../debug.js'

/**
 * Mollie checkout method selector (Sprint 7 Story 5).
 *
 * Unlike Stripe's on-page Payment Element, Mollie has no embedded card widget: the customer picks
 * a specific Mollie method (iDEAL, credit card, …) here, and the actual redirect to Mollie's
 * hosted checkout happens through a classic server-side HTTP flow — submitting the standard
 * payment-selection form calls `MolliePaymentController::execute()` (extends OXID's core payment
 * step), which creates the Mollie payment and issues a `Location` redirect directly. There is no
 * fetch/JSON round-trip to intercept on this page, so this controller's job is selection UX only:
 * highlighting the chosen method and guarding the "Continue" button against a double submit while
 * the browser is mid-navigation.
 *
 * Usage in Twig (see `views/twig/extensions/themes/default/page/checkout/payment.html.twig` and
 * `views/twig/frontend/mollie_methods.html.twig`):
 *   <div data-controller="mollie-checkout" data-mollie-checkout-debug-value="false">
 *     <label data-mollie-checkout-target="method">
 *       <input type="radio" name="mollie_method" data-action="change->mollie-checkout#methodSelected">
 *     </label>
 *   </div>
 */
export default class extends Controller {
  static targets = ['method']
  static values = {
    debug: { type: Boolean, default: false },
  }

  connect() {
    this._debug = createDebugLogger(() => this.debugValue)
    this._debug('Mollie checkout controller connected', { methodCount: this.methodTargets.length })

    this.highlightSelected()

    this._form = this.element.closest('form')
    this._onSubmit = () => this.disableContinueButton()
    if (this._form) {
      this._form.addEventListener('submit', this._onSubmit)
    }
  }

  disconnect() {
    if (this._form) {
      this._form.removeEventListener('submit', this._onSubmit)
    }
  }

  methodSelected(event) {
    this._debug('Mollie method selected', event.target.value)
    this.highlightSelected()
  }

  highlightSelected() {
    this.methodTargets.forEach((label) => {
      const input = label.querySelector('input[type="radio"]')
      label.classList.toggle('selected', Boolean(input && input.checked))
    })
  }

  /**
   * Guards against a double-click while the browser navigates away after the classic form POST
   * (there is no AJAX response to re-enable the button on — the page is about to unload).
   */
  disableContinueButton() {
    const button = document.querySelector('.col-lg-5 button[onclick*="requestSubmit"]')
    if (!button) {
      return
    }
    button.disabled = true
    button.classList.add('is-loading')
  }
}
