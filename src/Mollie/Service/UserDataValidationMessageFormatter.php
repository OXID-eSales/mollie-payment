<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * Builds the user-facing validation message for the shared
 * `index.php?cl=oepaymentvalidationapi&fnc=validate` endpoint:
 *
 *   "The {field label} field is not valid. Allowed symbols are: {allowed symbols}."
 *
 * The field label is translated via MOLLIE_VALIDATION_LABEL_{FIELD}; the allowed-symbol list is
 * derived from {@see ValidationRulesProvider}'s allow-token map. The violation code and offending
 * character are intentionally not surfaced — the message tells the user what IS allowed.
 */
final class UserDataValidationMessageFormatter implements MessageFormatterInterface
{
    private const TEMPLATE_KEY = 'MOLLIE_VALIDATION_FIELD_INVALID';
    private const LABEL_KEY_PREFIX = 'MOLLIE_VALIDATION_LABEL_';

    public function __construct(
        private readonly LanguageTranslatorInterface $translator,
        private readonly AllowedSymbolsDescriber $allowedSymbolsDescriber,
    ) {
    }

    public function getPluginModuleId(): string
    {
        return MollieDefinitions::MODULE_ID;
    }

    /**
     * "The <field> field is not valid. Allowed symbols are: <words>" - the same sentence for the
     * checkout, the OPC footer and the admin panel; `$code` / `$offendingChar` are deliberately not
     * echoed, the allowed set is what the shopper can act on.
     */
    public function format(string $field, string $code, ?string $offendingChar): string
    {
        $template = $this->translator->translateString(self::TEMPLATE_KEY);
        $label = $this->resolveLabel($field);
        $allowed = $this->allowedSymbolsDescriber->describe($field);

        return sprintf($template, $label, $allowed);
    }

    private function resolveLabel(string $field): string
    {
        $key = self::LABEL_KEY_PREFIX . strtoupper($field);
        $translation = $this->translator->translateString($key);

        return $translation === $key ? $field : $translation;
    }
}
