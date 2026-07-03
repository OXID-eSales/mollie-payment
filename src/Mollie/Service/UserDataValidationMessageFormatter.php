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

    /** Class token => translation key for its human-readable word. */
    private const CLASS_WORD_KEYS = [
        'UNICODE_LETTERS' => 'MOLLIE_VALIDATION_CLASS_LETTERS',
        'LETTERS' => 'MOLLIE_VALIDATION_CLASS_LETTERS',
        'NUMBERS' => 'MOLLIE_VALIDATION_CLASS_DIGITS',
        'SPACES' => 'MOLLIE_VALIDATION_CLASS_SPACES',
    ];

    /** @var array<string, string> */
    private readonly array $fieldAllowMap;

    public function __construct(
        private readonly LanguageTranslatorInterface $translator,
        ValidationRulesProvider $rulesProvider,
    ) {
        $this->fieldAllowMap = $rulesProvider->getFieldAllowMap();
    }

    public function getPluginModuleId(): string
    {
        return MollieDefinitions::MODULE_ID;
    }

    public function format(string $field, string $code, ?string $offendingChar): string
    {
        $template = $this->translator->translateString(self::TEMPLATE_KEY);
        $label = $this->resolveLabel($field);
        $allowed = $this->describeAllowedSymbols($field);

        return sprintf($template, $label, $allowed);
    }

    private function resolveLabel(string $field): string
    {
        $key = self::LABEL_KEY_PREFIX . strtoupper($field);
        $translation = $this->translator->translateString($key);

        return $translation === $key ? $field : $translation;
    }

    private function describeAllowedSymbols(string $field): string
    {
        $allow = $this->fieldAllowMap[$field] ?? '';
        if ($allow === '') {
            return '';
        }

        $words = [];
        $literals = [];
        foreach (explode(' ', $allow) as $token) {
            if ($token === '') {
                continue;
            }
            $this->classifyToken($token, $words, $literals);
        }

        return $this->joinParts(array_values(array_unique($words)), $literals);
    }

    /**
     * @param list<string> $words
     * @param list<string> $literals
     */
    private function classifyToken(string $token, array &$words, array &$literals): void
    {
        if (isset(self::CLASS_WORD_KEYS[$token])) {
            $words[] = $this->translator->translateString(self::CLASS_WORD_KEYS[$token]);
            return;
        }

        $literals[] = $token;
    }

    /**
     * @param list<string> $words
     * @param list<string> $literals
     */
    private function joinParts(array $words, array $literals): string
    {
        $parts = $words;
        if ($literals !== []) {
            $parts[] = implode(' ', $literals);
        }

        return implode(', ', $parts);
    }
}
