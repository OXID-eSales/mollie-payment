<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use Mollie\Api\Resources\BaseCollection;

/**
 * Maps a Mollie SDK list-endpoint result (`BaseCollection|<single resource>` per the SDK's own
 * loosely-typed docblocks) into a `list<T>` of module DTOs, filtering out anything that isn't a
 * collection of the expected resource type. Split out of {@see MollieAdapter} purely to keep that
 * class's cyclomatic/class complexity within PHPMD's threshold — still confined to `Adapter/`,
 * so the "one SDK-aware class" note in `MollieAdapter`'s docblock (the module's OUTER boundary:
 * Service/Controller/EventSystem never import the SDK) is unaffected.
 */
final class MollieCollectionMapper
{
    /**
     * @template T of object
     * @template U
     * @param class-string<T> $expectedClass
     * @param callable(T): U $mapper
     * @return list<U>
     */
    public static function map(mixed $collection, string $expectedClass, callable $mapper): array
    {
        $rows = [];
        if (!$collection instanceof BaseCollection) {
            return $rows;
        }

        foreach ($collection as $item) {
            if ($item instanceof $expectedClass) {
                $rows[] = $mapper($item);
            }
        }

        return $rows;
    }
}
