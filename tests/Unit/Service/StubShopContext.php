<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use PHPUnit\Framework\TestCase;

/**
 * A ContextInterface that answers shop id 1. ContextInterface is too wide to implement by hand, so a
 * throw-away TestCase makes the stub.
 */
final class StubShopContext
{
    public static function create(): ContextInterface
    {
        $factory = new class ('stub') extends TestCase {
            public function make(): ContextInterface
            {
                $context = $this->createStub(ContextInterface::class);
                $context->method('getCurrentShopId')->willReturn(1);

                return $context;
            }
        };

        return $factory->make();
    }
}
