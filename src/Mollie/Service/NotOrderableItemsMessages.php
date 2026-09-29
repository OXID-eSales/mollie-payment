<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * The sentences the shopper reads when an item is not orderable (MOL-22): one lead sentence,
 * then one per item known by title. Used verbatim by the OPC footer (JSON) and shown on the
 * basket step of the standard checkout.
 */
class NotOrderableItemsMessages
{
    public const LEAD = 'MOLLIE_CHECKOUT_ITEMS_NOT_ORDERABLE';
    public const ITEM = 'MOLLIE_CHECKOUT_ITEM_NOT_ORDERABLE';

    public function __construct(private readonly LanguageTranslatorInterface $translator)
    {
    }

    /**
     * @return list<string>
     */
    public function messagesFor(NotOrderableCheckoutFailure $failure): array
    {
        $messages = [$this->translator->translateString(self::LEAD)];
        $itemTemplate = $this->translator->translateString(self::ITEM);
        foreach ($failure->productTitles() as $title) {
            $messages[] = sprintf($itemTemplate, $title);
        }

        return $messages;
    }
}
