<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mollie\Api\Exceptions\ApiException;
use OxidEsales\Payments\Mollie\Adapter\MollieExceptionConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieExceptionConverter::class)]
final class MollieExceptionConverterTest extends TestCase
{
    public function testTheConvertedMessageCarriesNoSdkText(): void
    {
        $sdk = ApiException::createFromResponse(
            new Response(422, [], '{"status":422,"title":"Unprocessable","detail":"bad email shopper@example.test","field":"billingAddress.email"}'),
            new Request('POST', 'https://api.mollie.com/v2/payments', [], '{"cardToken":"tkn_fixture1234"}'),
        );

        $converted = MollieExceptionConverter::convert($sdk);

        self::assertSame('Mollie API call failed (HTTP 422, field billingAddress.email)', $converted->getMessage());
        self::assertSame(422, $converted->getCode());
        self::assertSame('billingAddress.email', $converted->field);
        self::assertNull($converted->getPrevious(), 'the SDK exception (and its text) is not chained along');
    }

    public function testAnExceptionWithoutFieldNamesNoField(): void
    {
        $converted = MollieExceptionConverter::convert(new ApiException('boom shopper@example.test', 503));

        self::assertSame('Mollie API call failed (HTTP 503)', $converted->getMessage());
        self::assertNull($converted->field);
        self::assertNull($converted->requestId);
    }
}
