<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;

/**
 * The operator-facing name of the method a customer paid with, shared by the classic Payment tab and the admin API.
 *
 * Translated method name, falling back to the raw Mollie code whenever there is no key for it or the key has no
 * translation - OXID returns the ident itself in that case, and "MOLLIE_PAYMENT_METHOD_IDEAL" must never reach an
 * operator.
 */
final class PaymentMethodLabelResolver
{
    public function __construct(private readonly LanguageTranslatorInterface $translator)
    {
    }

    public function label(PaymentMethodDescriptor $descriptor): string
    {
        $fallback = (string) $descriptor->displayType();
        $key = $descriptor->labelKey();

        if ($key === null) {
            return $fallback;
        }

        $translated = $this->translator->translateString($key);

        if ($translated === '' || $translated === $key) {
            return $fallback;
        }

        return $translated;
    }
}
