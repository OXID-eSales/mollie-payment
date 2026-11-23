<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Support;

use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;

/**
 * Answers the language key itself: the standalone unit suite has no shop to translate with.
 */
final class KeyEchoTranslator implements LanguageTranslatorInterface
{
    public function translateString(string $key): string
    {
        return $key;
    }
}
