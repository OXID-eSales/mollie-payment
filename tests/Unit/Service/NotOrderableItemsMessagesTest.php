<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Service\BuyabilityFailure;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Mollie\Service\NotOrderableCheckoutFailure;
use OxidEsales\Payments\Mollie\Service\NotOrderableItemsMessages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * MOL-22 — the sentences the shopper reads: one lead sentence, then one per item known by title.
 */
#[CoversClass(NotOrderableItemsMessages::class)]
final class NotOrderableItemsMessagesTest extends TestCase
{
    public function testLeadSentenceThenOnePerKnownItem(): void
    {
        $failure = NotOrderableCheckoutFailure::fromBuyabilityFailures([
            new BuyabilityFailure('art-1', 'Ocean Eyes'),
            new BuyabilityFailure('art-3', 'Kite & "Board"'),
        ]);

        $messages = (new NotOrderableItemsMessages($this->translator()))->messagesFor($failure);

        self::assertSame([
            'Order cannot be completed: items not orderable.',
            'The item "Ocean Eyes" is currently not orderable.',
            'The item "Kite & "Board"" is currently not orderable.',
        ], $messages);
    }

    public function testOnlyTheLeadSentenceWhenNoItemIsKnownByTitle(): void
    {
        $failure = NotOrderableCheckoutFailure::fromBuyabilityFailures([]);

        self::assertSame(
            ['Order cannot be completed: items not orderable.'],
            (new NotOrderableItemsMessages($this->translator()))->messagesFor($failure)
        );
    }

    private function translator(): LanguageTranslatorInterface
    {
        $translator = $this->createMock(LanguageTranslatorInterface::class);
        $translator->method('translateString')->willReturnCallback(static fn (string $key): string => match ($key) {
            NotOrderableItemsMessages::LEAD => 'Order cannot be completed: items not orderable.',
            NotOrderableItemsMessages::ITEM => 'The item "%s" is currently not orderable.',
            default => $key,
        });

        return $translator;
    }
}
