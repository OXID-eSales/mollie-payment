import { Controller } from '@hotwired/stimulus'
import { createDebugLogger } from '../debug.js'

/**
 * IFRAME-04 — Mollie Components inline card entry.
 *
 * Mollie's hosted checkout page cannot be framed (X-Frame-Options: DENY), but Mollie *Components*
 * — card fields served from js.mollie.com — are designed to be embedded. This controller mounts
 * those fields on the order page, and on "Place order" tokenizes the card
 * (`mollie.createToken()`) and hands the single-use token to the server via a hidden field
 * (`mollieCardToken`); the server then creates a `creditcard` payment with that token. Card data
 * never touches the shop DOM — it lives inside Mollie's iframes.
 *
 * The customer may instead choose "other Mollie methods" (radio `mollie_flow`=redirect), in which
 * case this controller stays out of the way and the classic redirect flow runs unchanged.
 *
 * Usage (see page/checkout/order.html.twig Mollie branch):
 *   <div data-controller="mollie-components"
 *        data-mollie-components-profile-id-value="pfl_…"
 *        data-mollie-components-testmode-value="true"
 *        data-mollie-components-locale-value="en_US">
 *     <div data-mollie-components-target="fields"> … field mount points … </div>
 *     <input type="hidden" name="mollieCardToken" data-mollie-components-target="token">
 *   </div>
 */
const MOLLIE_JS = 'https://js.mollie.com/v1/mollie.js'

const FIELDS = ['cardNumber', 'cardHolder', 'expiryDate', 'verificationCode']

export default class extends Controller {
  static targets = ['cardNumber', 'cardHolder', 'expiryDate', 'verificationCode', 'error', 'token', 'fields']
  static values = {
    profileId: String,
    testmode: { type: Boolean, default: false },
    locale: { type: String, default: 'en_US' },
    // Id of the core order form the "Order now" button submits (index.php?cl=order&fnc=execute).
    formId: { type: String, default: 'orderConfirmAgbBottom' },
    debug: { type: Boolean, default: false },
  }

  connect() {
    this._debug = createDebugLogger(() => this.debugValue)
    this._components = {}
    this._cardMode = this._readCardMode()
    this._syncMode()
    this._debug('Mollie Components controller connected', { cardMode: this._cardMode })
  }

  /** data-action: change->mollie-components#flowChanged on the method radios. */
  flowChanged() {
    this._cardMode = this._readCardMode()
    this._tokenized = false
    this._syncMode()
  }

  _readCardMode() {
    const checked = document.querySelector('input[name="mollie_flow"]:checked')
    // No radio rendered (card-only) → default to card mode.
    return !checked || checked.value === 'card'
  }

  _syncMode() {
    if (this.hasFieldsTarget) {
      this.fieldsTarget.style.display = this._cardMode ? '' : 'none'
    }
    if (this._cardMode) {
      this._ensureMounted()
    }
  }

  async _ensureMounted() {
    if (this._mollie) {
      return
    }
    if (!this.profileIdValue) {
      this._debug('no profile id configured — cannot mount Mollie Components')
      return
    }
    await this._loadMollieJs()
    if (!window.Mollie) {
      this._showError('Mollie.js is unavailable')
      return
    }
    this._mollie = window.Mollie(this.profileIdValue, {
      locale: this.localeValue,
      testmode: this.testmodeValue,
    })
    for (const name of FIELDS) {
      const mount = this[`${name}Target`] ? this[`${name}Target`] : null
      if (!mount) {
        continue
      }
      const component = this._mollie.createComponent(name)
      component.mount(mount)
      component.addEventListener('change', (event) => this._fieldChanged(name, event))
      this._components[name] = component
    }
    this._debug('Mollie Components mounted', Object.keys(this._components))
  }

  /**
   * data-action: click->mollie-components#placeOrder on the order button.
   * Redirect flow → submit the core order form as-is. Card flow → tokenize the card, put the
   * token on the hidden field, then submit the same form (server creates the creditcard payment).
   */
  async placeOrder(event) {
    if (event) {
      event.preventDefault()
    }
    const form = document.getElementById(this.formIdValue)
    if (!form) {
      this._showError('Order form not found.')
      return
    }

    if (!this._cardMode) {
      this._submit(form)
      return
    }

    this._clearError()
    if (!this._mollie) {
      await this._ensureMounted()
    }
    if (!this._mollie) {
      this._showError('Card form is not ready. Please try again.')
      return
    }

    try {
      const { token, error } = await this._mollie.createToken()
      if (error) {
        this._showError(error.message || 'Please check your card details.')
        return
      }
      if (this.hasTokenTarget) {
        this.tokenTarget.value = token
      }
      this._debug('card tokenized — submitting order')
      this._submit(form)
    } catch (err) {
      this._debug('createToken failed', err)
      this._showError('Could not process the card. Please try again.')
    }
  }

  _submit(form) {
    form.requestSubmit ? form.requestSubmit() : form.submit()
  }

  _fieldChanged(name, event) {
    if (event && event.error && event.touched) {
      this._showError(event.error)
      return
    }
    this._clearError()
  }

  _loadMollieJs() {
    return new Promise((resolve, reject) => {
      if (window.Mollie) { resolve(); return }
      const existing = document.querySelector(`script[src="${MOLLIE_JS}"]`)
      if (existing) {
        existing.addEventListener('load', () => resolve())
        existing.addEventListener('error', () => reject(new Error('Failed to load Mollie.js')))
        return
      }
      const script = document.createElement('script')
      script.src = MOLLIE_JS
      script.async = true
      script.onload = () => resolve()
      script.onerror = () => reject(new Error('Failed to load Mollie.js'))
      document.head.appendChild(script)
    })
  }

  _showError(message) {
    if (this.hasErrorTarget) {
      this.errorTarget.textContent = message
      this.errorTarget.style.display = 'block'
    }
  }

  _clearError() {
    if (this.hasErrorTarget) {
      this.errorTarget.textContent = ''
      this.errorTarget.style.display = 'none'
    }
  }
}
