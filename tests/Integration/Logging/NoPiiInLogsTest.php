<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Logging;

use Mollie\Api\Exceptions\ApiException;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Checkout\Return\ContractReturnTokenServiceInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\Exception\WebhookSignatureException;
use OxidEsales\Payments\Mollie\Adapter\Dto\CreatePaymentRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\MollieApiExceptionSummary;
use OxidEsales\Payments\Mollie\Adapter\MollieExceptionConverter;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Adapter\MollieWebhookAdapterInterface;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use OxidEsales\Payments\Mollie\EventSystem\Handler\MollieCheckoutSessionHandler;
use OxidEsales\Payments\Mollie\Service\CheckoutPaymentServiceInterface;
use OxidEsales\Payments\Mollie\Service\MollieRedirectUrlValidator;
use OxidEsales\Payments\Mollie\Service\Return\MollieReturnResolver;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Admin\MolliePaymentSnapshotProvider;
use OxidEsales\Payments\Mollie\Service\TransactionHistoryService;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use Stringable;

/**
 * VR-26 M3 (defect #3): Mollie's SDK exception text embeds the request body - the card token, the addresses, the
 * e-mail. An exception of that kind goes through every place that logs one or stores a failure text; nothing of its
 * content may come out the other end. Fixture values only (`tkn_fixture...` is allow-listed in .gitleaks.toml).
 */
final class NoPiiInLogsTest extends TestCase
{
    private const CARD_TOKEN = 'tkn_fixture1234567890';
    private const E_MAIL = 'shopper@example.test';

    private RecordingLogger $logger;
    private ThrowableSummary $summary;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->summary = new ThrowableSummary([new MollieApiExceptionSummary()]);
    }

    public function testTheConvertedExceptionCarriesNoRequestBody(): void
    {
        $converted = MollieExceptionConverter::convert($this->fixtureApiException());

        $this->assertNoPii((string) $converted);
    }

    public function testAFailedPaymentCreationStoresAndLogsNoSdkText(): void
    {
        $failures = [];
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $contract->method('fail')->willReturnCallback(static function (string $reason) use (&$failures): void {
            $failures[] = $reason;
        });
        $payments = $this->createMock(MolliePaymentsAdapterInterface::class);
        $payments->method('createPayment')->willThrowException($this->adapterFailure());
        $checkout = $this->createMock(CheckoutPaymentServiceInterface::class);
        $checkout->method('buildCreatePaymentRequest')->willReturn(new CreatePaymentRequest(
            amount: MollieAmountDto::fromComponents('EUR', 10.0),
            description: 'x',
            redirectUrl: 'https://shop.test/return',
        ));
        $shop = $this->createMock(ShopAdapterInterface::class);
        $shop->method('getShopUrl')->willReturn('https://shop.test/');
        $handler = new MollieCheckoutSessionHandler(
            $checkout,
            $payments,
            $this->createMock(ContractRepositoryInterface::class),
            $this->createMock(ContractReturnTokenServiceInterface::class),
            $shop,
            new MollieRedirectUrlValidator(),
            $this->logger,
            $this->summary,
        );
        $context = new EventContext();
        $context->setContract($contract);

        $handler->handle(new MollieCheckoutSessionRequestEvent($context));

        self::assertCount(1, $failures);
        $this->assertNoPii(implode("\n", $failures));
        $this->assertNoPii($this->logger->dump());
        self::assertStringContainsString('"httpStatus":422', $this->logger->dump());
    }

    public function testAPaymentThatCannotBeReadIsLoggedWithoutTheSdkText(): void
    {
        $payments = $this->createMock(MolliePaymentsAdapterInterface::class);
        $payments->method('getPayment')->willThrowException($this->adapterFailure());
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $contract->method('getProviderOrderId')->willReturn('tr_1');

        (new MolliePaymentSnapshotProvider($payments, $this->logger, $this->summary))->snapshot($contract);
        (new TransactionHistoryService(
            $payments,
            $this->createMock(\OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface::class),
            $this->createMock(\OxidEsales\Payments\Mollie\Adapter\MollieCaptureAdapterInterface::class),
            new MollieStatusMapper(),
            $this->logger,
            $this->summary,
        ))->fetch($contract);
        $resolution = (new MollieReturnResolver(
            $payments,
            new MollieStatusMapper(),
            $this->createMock(ModuleConfigurationServiceInterface::class),
            $this->logger,
            $this->summary,
        ))->resolve($contract, new EventContext());

        $this->assertNoPii($this->logger->dump());
        $this->assertNoPii((string) $resolution->errorMessage);
        self::assertCount(3, $this->logger->records);
    }

    public function testAWebhookThatCannotBeVerifiedStoresNoSdkText(): void
    {
        $webhookAdapter = $this->createMock(MollieWebhookAdapterInterface::class);
        $webhookAdapter->method('fetchByWebhookId')->willThrowException($this->adapterFailure());
        $processor = new MollieWebhookProcessor(
            $this->createMock(WebhookLogRepositoryInterface::class),
            $this->logger,
            $this->summary,
            $webhookAdapter,
            new MollieStatusMapper(),
            [],
        );
        $parse = new ReflectionMethod($processor, 'parseAndValidateRequest');

        try {
            $parse->invoke($processor, new \OxidEsales\PaymentBase\Webhook\WebhookRequest(
                payload: 'id=tr_1',
                signature: '',
                remoteIp: '127.0.0.1',
                receivedAt: new \DateTimeImmutable(),
            ));
            self::fail('expected a WebhookSignatureException');
        } catch (WebhookSignatureException $e) {
            $this->assertNoPii($e->getMessage());
        }
    }

    private function adapterFailure(): \Throwable
    {
        return MollieExceptionConverter::convert($this->fixtureApiException());
    }

    private function fixtureApiException(): ApiException
    {
        return new ApiException(
            'The email address ' . self::E_MAIL . ' is not valid. Request body: {"cardToken":"' . self::CARD_TOKEN . '"}',
            422,
            'billingAddress.email',
        );
    }

    private function assertNoPii(string $text): void
    {
        self::assertStringNotContainsString(self::CARD_TOKEN, $text);
        self::assertStringNotContainsString(self::E_MAIL, $text);
        self::assertStringNotContainsString('Request body', $text);
    }
}

/**
 * @internal collects every record so the test can search all of them at once
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param string|Stringable $message
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    public function dump(): string
    {
        return (string) json_encode($this->records);
    }
}
