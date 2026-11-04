<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter;

use GuzzleHttp\Psr7\Response;
use Mollie\Api\Exceptions\ApiException;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MollieApiExceptionSummary;
use OxidEsales\Payments\Mollie\Adapter\MollieExceptionConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(MollieApiExceptionSummary::class)]
final class MollieApiExceptionSummaryTest extends TestCase
{
    public function testSummarisesAnSdkExceptionByStatusFieldAndRequestIdOnly(): void
    {
        $summary = (new MollieApiExceptionSummary())->summarize($this->sdkException());

        self::assertSame(
            ['httpStatus' => 422, 'field' => 'billingAddress.email', 'requestId' => 'req_fixture_1'],
            $summary,
        );
    }

    public function testSummarisesAnAdapterExceptionFromItsOwnFacts(): void
    {
        $adapterException = MollieExceptionConverter::convert($this->sdkException());

        $summary = (new MollieApiExceptionSummary())->summarize($adapterException);

        self::assertSame(['httpStatus' => 422, 'field' => 'billingAddress.email', 'requestId' => 'req_fixture_1'], $summary);
    }

    public function testSupportsTheSdkAndTheAdapterExceptionOnly(): void
    {
        $extractor = new MollieApiExceptionSummary();

        self::assertTrue($extractor->supports($this->sdkException()));
        self::assertTrue($extractor->supports(new MollieAdapterException('x')));
        self::assertFalse($extractor->supports(new RuntimeException('x')));
    }

    public function testNothingOfTheSdkTextReachesTheSummaryLine(): void
    {
        $summary = new ThrowableSummary([new MollieApiExceptionSummary()]);

        $context = json_encode($summary->fromThrowable($this->sdkException())) . $summary->toLine($this->sdkException());

        self::assertStringNotContainsString('tkn_fixture', $context);
        self::assertStringNotContainsString('shopper@example.test', $context);
        self::assertStringContainsString('req_fixture_1', $context);
    }

    private function sdkException(): ApiException
    {
        $body = '{"cardToken":"tkn_fixture1234","billingAddress":{"email":"shopper@example.test"}}';
        $request = new \GuzzleHttp\Psr7\Request('POST', 'https://api.mollie.com/v2/payments', [], $body);
        $response = new Response(422, ['X-Request-Id' => 'req_fixture_1'], json_encode([
            'status' => 422,
            'title' => 'Unprocessable Entity',
            'detail' => 'The email is invalid',
            'field' => 'billingAddress.email',
        ]));

        return ApiException::createFromResponse($response, $request);
    }
}
