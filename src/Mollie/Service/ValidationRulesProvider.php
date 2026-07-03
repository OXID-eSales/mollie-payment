<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * Reads the raw `allow` token strings from src/Resources/validation-rules.php for display
 * purposes (the "allowed symbols" hint in validation error messages).
 *
 * payment-base's FilesystemValidationRuleLoader independently reads the same file for the
 * actual character-level rule enforcement — this class never duplicates that logic, it only
 * projects the `allow` column for {@see UserDataValidationMessageFormatter}.
 */
final class ValidationRulesProvider
{
    private const RULES_FILE = __DIR__ . '/../../Resources/validation-rules.php';

    /**
     * @return array<string, string> field name => space-separated allow-token string
     */
    public function getFieldAllowMap(): array
    {
        /** @var array{fields: array<array{field: string, rules: array{allow?: string, block?: string}}>} $data */
        $data = require self::RULES_FILE;

        $map = [];
        foreach ($data['fields'] as $entry) {
            $map[$entry['field']] = $entry['rules']['allow'] ?? '';
        }

        return $map;
    }
}
