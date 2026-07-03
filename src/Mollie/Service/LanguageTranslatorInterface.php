<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * Narrow testability seam around OXID's language/translation primitive. Strips
 * Registry::getLang() out of business services, making them independently unit-testable
 * without a full OXID bootstrap.
 */
interface LanguageTranslatorInterface
{
    /**
     * Returns the translated string for the given OXID language constant. When the constant
     * has no translation in the active language, OXID returns the raw key; callers that need
     * fallback behaviour must detect this case (key === returned value) themselves.
     */
    public function translateString(string $key): string;
}
