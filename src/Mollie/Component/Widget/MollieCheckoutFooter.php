<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Component\Widget;

use OxidEsales\Eshop\Application\Component\Widget\WidgetController;
use OxidEsales\Eshop\Core\ViewConfig;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Core\ViewConfig as MollieViewConfig;

/**
 * Mollie checkout footer widget for one-page-checkout (OPC).
 *
 * Advertised by {@see \OxidEsales\Payments\Mollie\PaymentHandler\MolliePaymentHandler::getFrontendConfig()}
 * (as `footerWidget: 'molliecheckoutfooter'`) only when the payment-base "Use iframe instead of
 * checkout button" flag is on. OPC then loads this widget into its modal instead of the generic
 * redirect button.
 *
 * The widget renders the SAME inline experience as the standard `cl=order` page (IFRAME-04): a Mollie
 * method selector plus inline Mollie Components card fields. Its self-registering Stimulus controller
 * (inline in the template) posts the chosen method + card token to OPC's `processCheckout` endpoint —
 * which forwards them through the agnostic PaymentContext metadata bag — and then follows the Mollie
 * redirect URL the handler returns. Mollie's hosted page cannot be framed (IFRAME-03), so the payment
 * itself still completes via redirect; only the method/card entry happens inline.
 */
class MollieCheckoutFooter extends WidgetController
{
    protected $_sThisTemplate = '@' . MollieDefinitions::MODULE_ID . '/widget/checkout/mollie-footer.html.twig';

    public function render()
    {
        parent::render();

        $this->addTplParam('checkoutData', $this->getCheckoutData());
        $this->addTplParam('mollieConfig', $this->getMollieConfig());

        return $this->_sThisTemplate;
    }

    /**
     * OPC passes these view parameters when it loads the footer widget (see
     * CheckoutApiController::loadFooterWidget). The Stimulus controller needs the payment id and the
     * CSRF token to post processCheckout.
     *
     * @return array<string, mixed>
     */
    protected function getCheckoutData(): array
    {
        return [
            'paymentMethodId' => (string) $this->getViewParameter('paymentMethodId'),
            'csrfToken' => (string) $this->getViewParameter('csrfToken'),
        ];
    }

    /**
     * Mollie Components config + the inline method list — reuses the same ViewConfig helpers the
     * standard order page uses, so OPC and the classic page stay in lockstep.
     *
     * @return array<string, mixed>
     */
    protected function getMollieConfig(): array
    {
        $viewConfig = $this->mollieViewConfig();

        return [
            'methods' => $viewConfig->getMollieMethods(),
            'profileId' => $viewConfig->getMollieProfileId(),
            'testmode' => $viewConfig->isMollieTestMode(),
            'locale' => $viewConfig->getMollieComponentsLocale(),
            'inlineCardEnabled' => $viewConfig->isMollieInlineCardEnabled(),
            'debug' => $viewConfig->isMollieDebugLoggingEnabled(),
        ];
    }

    /**
     * Resolve the active ViewConfig extension chain (which carries the Mollie helpers). Extracted as
     * a seam so tests can override it without a shop bootstrap.
     */
    protected function mollieViewConfig(): MollieViewConfig
    {
        /** @var MollieViewConfig $viewConfig oxNew resolves the module ViewConfig extension chain */
        $viewConfig = oxNew(ViewConfig::class);

        return $viewConfig;
    }
}
