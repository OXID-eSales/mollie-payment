<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use OxidEsales\Payments\Mollie\EventSystem\Handler\MollieContractCreationHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(MollieContractCreationHandler::class)]
final class MollieContractCreationHandlerTest extends TestCase
{
    public function testRegistersCheckoutSessionRequestEvent(): void
    {
        self::assertSame(
            MollieCheckoutSessionRequestEvent::class,
            MollieContractCreationHandler::getHandledEventClass(),
        );
    }

    public function testGetPriorityReturns100(): void
    {
        $handler = new MollieContractCreationHandler(
            $this->createMock(ContractServiceInterface::class),
            $this->createMock(EventDispatcherInterface::class),
        );

        self::assertSame(100, $handler->getPriority());
    }

    public function testHandleCreatesDraftContractWithBasketSnapshot(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);

        $service = $this->createMock(ContractServiceInterface::class);
        $service->expects(self::once())
            ->method('createContract')
            ->with('USR-1', self::isInstanceOf(stdClass::class), [])
            ->willReturn($contract);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $context = new EventContext([
            'userId' => 'USR-1',
            'basket' => new stdClass(),
        ]);

        (new MollieContractCreationHandler($service, $dispatcher))->handle(
            new MollieCheckoutSessionRequestEvent($context),
        );

        self::assertSame($contract, $context->getContract());
    }

    public function testHandleStoresSelectedMethodInContractMetadata(): void
    {
        $metadataCalls = [];
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('setMetadata')->willReturnCallback(
            static function (string $key, mixed $value) use (&$metadataCalls): void {
                $metadataCalls[$key] = $value;
            },
        );

        $service = $this->createMock(ContractServiceInterface::class);
        $service->method('createContract')->willReturn($contract);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        (new MollieContractCreationHandler($service, $dispatcher))->handle(
            new MollieCheckoutSessionRequestEvent(new EventContext([
                'userId' => 'USR-1',
                'basket' => new stdClass(),
                'mollieMethod' => 'ideal',
            ])),
        );

        self::assertSame('ideal', $metadataCalls[MollieContractCreationHandler::METADATA_MOLLIE_METHOD] ?? null);
        self::assertSame(
            MollieDefinitions::PAYMENT_ID,
            $metadataCalls[MollieContractCreationHandler::METADATA_PAYMENT_ID] ?? null,
        );
    }

    public function testHandleWithoutMethodDoesNotSetMethodMetadata(): void
    {
        $metadataCalls = [];
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('setMetadata')->willReturnCallback(
            static function (string $key, mixed $value) use (&$metadataCalls): void {
                $metadataCalls[$key] = $value;
            },
        );

        $service = $this->createMock(ContractServiceInterface::class);
        $service->method('createContract')->willReturn($contract);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        (new MollieContractCreationHandler($service, $dispatcher))->handle(
            new MollieCheckoutSessionRequestEvent(new EventContext([
                'userId' => 'USR-1',
                'basket' => new stdClass(),
            ])),
        );

        self::assertArrayNotHasKey(MollieContractCreationHandler::METADATA_MOLLIE_METHOD, $metadataCalls);
    }

    public function testHandleDispatchesContractDraftCompletedEvent(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);

        $service = $this->createMock(ContractServiceInterface::class);
        $service->method('createContract')->willReturn($contract);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(ContractDraftCompletedEvent::class));

        $context = new EventContext([
            'userId' => 'USR-1',
            'basket' => new stdClass(),
        ]);

        (new MollieContractCreationHandler($service, $dispatcher))->handle(
            new MollieCheckoutSessionRequestEvent($context),
        );
    }

    public function testHandleSkipsWhenContractAlreadyInContext(): void
    {
        $existing = $this->createMock(PaymentContractInterface::class);
        $service = $this->createMock(ContractServiceInterface::class);
        $service->expects(self::never())->method('createContract');
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $context = new EventContext();
        $context->setContract($existing);

        (new MollieContractCreationHandler($service, $dispatcher))->handle(
            new MollieCheckoutSessionRequestEvent($context),
        );

        self::assertSame($existing, $context->getContract());
    }
}
