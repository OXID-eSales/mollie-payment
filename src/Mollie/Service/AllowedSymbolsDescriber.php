<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * Turns a field's `allow` rule ("UNICODE_LETTERS SPACES ' - .") into the words a shopper can read
 * ("letters, spaces, ' - ."). Used by the message formatter (checkout + admin) and by the OPC footer,
 * which shows the allowed symbols next to a rejected field (MOL-15).
 */
final class AllowedSymbolsDescriber
{
    private const CLASS_WORD_KEYS = [
        'UNICODE_LETTERS' => 'MOLLIE_VALIDATION_CLASS_LETTERS',
        'LETTERS' => 'MOLLIE_VALIDATION_CLASS_LETTERS',
        'NUMBERS' => 'MOLLIE_VALIDATION_CLASS_DIGITS',
        'SPACES' => 'MOLLIE_VALIDATION_CLASS_SPACES',
    ];

    /**
     * @param array<string, string> $fieldAllowMap logical field => allow rule
     */
    public function __construct(
        private readonly LanguageTranslatorInterface $translator,
        private readonly array $fieldAllowMap,
    ) {
    }

    public function describe(string $field): string
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
