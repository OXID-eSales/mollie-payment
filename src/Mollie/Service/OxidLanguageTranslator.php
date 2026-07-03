<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Eshop\Core\Registry;

/**
 * OXID-backed implementation of {@see LanguageTranslatorInterface}.
 */
final class OxidLanguageTranslator implements LanguageTranslatorInterface
{
    public function translateString(string $key): string
    {
        // @phpstan-ignore-next-line OXID core: Registry::getLang() virtual parent
        $result = Registry::getLang()->translateString($key);

        if (is_array($result)) {
            $first = reset($result);
            return is_string($first) ? $first : '';
        }

        return is_string($result) ? $result : $key;
    }
}
