<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller;

use OxidEsales\PaymentBase\Checkout\PreviousCheckoutAttemptCleanerInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Return\ReturnResolverInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Mollie\Controller\MollieOrderController;
use OxidEsales\Payments\Mollie\Service\AbandonedAttemptCleanup;
use OxidEsales\Payments\Mollie\Tests\Unit\Support\PreloadsModuleClassChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * STRP-171 — the order is created before the shopper leaves for Mollie, so one
 * who comes back without paying and does not retry leaves it NOT_FINISHED in
 * the backend until the age-based sweep, which can be days.
 */
#[CoversClass(MollieOrderController::class)]
final class MollieOrderControllerAbandonedAttemptTest extends TestCase
{
    use PreloadsModuleClassChain;

    private PreviousCheckoutAttemptCleanerInterface&MockObject $cleaner;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Build the 'order' chain before instantiating a chain extension by its
        // concrete name; see the trait for why.
        self::preloadModuleClassChain('order');
    }

    protected function setUp(): void
    {
        $this->cleaner = $this->createMock(PreviousCheckoutAttemptCleanerInterface::class);
    }

    public function testRetiresTheAttemptWhenTheShopperComesBackUnpaid(): void
    {
        $this->cleaner->expects($this->once())->method('clean')->with('contract-1');

        $controller = $this->controller(pending: false);

        self::assertSame('payment', $controller->checkoutReturn());
    }

    public function testLeavesAPendingPaymentAloneForTheWebhook(): void
    {
        // The money may still arrive. Cancelling here would storno an order
        // the webhook is about to confirm.
        $this->cleaner->expects($this->never())->method('clean');

        $controller = $this->controller(pending: true);

        self::assertSame('thankyou', $controller->checkoutReturn());
    }

    public function testAFailingCleanerDoesNotChangeWhatTheShopperSees(): void
    {
        $this->cleaner->method('clean')->willThrowException(new \RuntimeException('db gone'));

        $controller = $this->controller(pending: false);

        self::assertSame('payment', $controller->checkoutReturn());
    }

    public function testSurvivesAShopWhereTheCleanerIsUnavailable(): void
    {
        $controller = $this->controller(pending: false, cleaner: null);

        self::assertSame('payment', $controller->checkoutReturn());
    }

    private function controller(
        bool $pending,
        ?PreviousCheckoutAttemptCleanerInterface $cleaner = null
    ): TestableMollieOrderController {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getOrderId')->willReturn('order-42');

        $tokenService = $this->createMock(TokenServiceInterface::class);
        $tokenService->method('validateToken')->willReturn(true);

        $contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $contractRepository->method('findById')->willReturn($contract);

        // A null order id from the responder is the "not finalised" return.
        $responder = $this->createMock(CheckoutReturnResponder::class);
        $responder->method('respond')->willReturn(null);

        $controller = new TestableMollieOrderController(
            ['contract_id' => 'contract-1', 'contract_token' => 'good-token'],
            $tokenService,
            $contractRepository,
            $this->createMock(ReturnResolverInterface::class),
            $responder,
        );
        $controller->pendingReturn = $pending;
        $controller->attemptCleaner = func_num_args() > 1
            ? ($cleaner === null ? null : new AbandonedAttemptCleanup($cleaner))
            : new AbandonedAttemptCleanup($this->cleaner);

        return $controller;
    }
}
