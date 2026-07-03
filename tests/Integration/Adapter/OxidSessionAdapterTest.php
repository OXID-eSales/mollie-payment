<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Adapter;

use OxidEsales\Payments\Mollie\Adapter\OxidSessionAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the session adapter against the real OXID session (needs shop bootstrap).
 *
 * @group integration
 */
final class OxidSessionAdapterTest extends TestCase
{
    public function testSetAndGetVariable_RoundTrips(): void
    {
        $adapter = new OxidSessionAdapter();
        $adapter->setVariable('mollie_test_var', 'hello');
        self::assertSame('hello', $adapter->getVariable('mollie_test_var'));
    }

    public function testGetSessionId_ReturnsString(): void
    {
        self::assertIsString((new OxidSessionAdapter())->getSessionId());
    }
}
