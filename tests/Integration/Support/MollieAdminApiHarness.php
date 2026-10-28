<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Support;

use Closure;
use Doctrine\DBAL\Connection;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionDispatcherInterface;
use OxidEsales\PaymentBase\Admin\PaymentPanelRegistry;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminAuditInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminPaymentOperations;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminStepUpInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\DbalRequestShopOrders;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OperationIdempotency;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PanelActionBoundsResolver;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PanelActionOptionsResolver;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PanelProviderActionRunner;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetailsReader;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use OxidEsales\PaymentBase\Repository\DoctrineIdempotencyRepository;
use OxidEsales\PaymentBase\Repository\DoctrineTransactionRepository;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionBounds;
use OxidEsales\Payments\Mollie\Admin\AdminActionRefusals;
use OxidEsales\Payments\Mollie\Admin\AdminAmountValidator;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Mollie\Admin\MolliePanelOrderLoader;
use OxidEsales\Payments\Mollie\Admin\MolliePanelViewDataBuilder;
use OxidEsales\Payments\Mollie\Admin\MolliePaymentPanelProvider;
use OxidEsales\Payments\Mollie\Admin\MolliePaymentSnapshotProvider;
use OxidEsales\Payments\Mollie\Admin\ReleaseRule;
use OxidEsales\Payments\Mollie\Controller\Admin\MollieExtraActionDispatcherInterface;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieReleaseOperation;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Mollie\Service\UserDataValidationMessageFormatter;
use OxidEsales\Payments\Mollie\Service\UserDataValidatorInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The Mollie panel provider behind payment-base's admin payment API, against the real database and the real
 * payment-base services (contract and transaction repositories, bounds and options resolvers, idempotency, the
 * provider-action runner), with only Mollie itself faked: the payments adapter answers with a scripted live payment
 * (or fails) and the action dispatchers record what the panel asked of them. No Mollie sandbox is involved: the money
 * movement is Mollie's side, what is proven is everything the shop decides.
 *
 * The using test case must be a TestCase on a rolled-back connection (OXID's IntegrationTestCase) with `contracts()`
 * built on that connection.
 */
trait MollieAdminApiHarness
{
    private ?ContractRepositoryInterface $contractRepository = null;
    private ?MolliePaymentDto $livePayment = null;
    private bool $mollieDown = false;
    /** @var list<string> */
    private array $actionsRun = [];
    private int $stepUps = 0;
    private bool $releaseSucceeds = true;
    private ?MolliePaymentPanelProvider $panel = null;

    /** @param array<string, mixed> $values */
    private function scriptLivePayment(array $values): void
    {
        $this->livePayment = MolliePaymentDto::fromArray($values);
        $this->panel = null;
    }

    private function operations(): AdminPaymentOperations
    {
        $connection = $this->connection();
        $registry = new PaymentPanelRegistry([$this->panel()]);

        return new AdminPaymentOperations(
            new PaymentContractDetailsReader(
                $this->contracts(),
                new DoctrineTransactionRepository($connection),
                new PanelActionOptionsResolver($registry, new NullLogger()),
                new PanelActionBoundsResolver($registry, new NullLogger()),
            ),
            new PanelProviderActionRunner($registry, $this->contracts(), new NullLogger()),
            new OperationIdempotency(new DoctrineIdempotencyRepository($connection)),
            $this->countingStepUp(),
            $this->passThroughAudit(),
            new DbalRequestShopOrders($connection),
            new NullLogger(),
        );
    }

    private function releaseOperation(): MollieReleaseOperation
    {
        $connection = $this->connection();
        $registry = new PaymentPanelRegistry([$this->panel()]);

        return new MollieReleaseOperation(
            new PaymentContractDetailsReader(
                $this->contracts(),
                new DoctrineTransactionRepository($connection),
                new PanelActionOptionsResolver($registry, new NullLogger()),
                new PanelActionBoundsResolver($registry, new NullLogger()),
            ),
            new PanelProviderActionRunner($registry, $this->contracts(), new NullLogger()),
            new OperationIdempotency(new DoctrineIdempotencyRepository($connection)),
            $this->countingStepUp(),
            $this->passThroughAudit(),
            new DbalRequestShopOrders($connection),
        );
    }

    private function panel(): MolliePaymentPanelProvider
    {
        $container = ContainerFactory::getInstance()->getContainer();
        $snapshots = new MolliePaymentSnapshotProvider($this->paymentsAdapter(), new NullLogger());
        $bounds = new AdminActionBounds($snapshots);
        $dispatcher = $this->recordingDispatcher();

        return $this->panel ??= new MolliePaymentPanelProvider(
            $dispatcher,
            $this->createMock(MolliePanelViewDataBuilder::class),
            new MolliePanelOrderLoader(),
            $this->contracts(),
            $bounds,
            new AdminAmountValidator(),
            $container->get(UserDataValidatorInterface::class),
            new AdminActionRefusals(
                $container->get(AdminValidationFeedbackInterface::class),
                $container->get(UserDataValidationMessageFormatter::class),
                new class implements LanguageTranslatorInterface {
                    public function translateString(string $key): string
                    {
                        return $key;
                    }
                },
            ),
            new ReleaseRule($bounds),
            $dispatcher,
        );
    }

    private function paymentsAdapter(): MolliePaymentsAdapterInterface
    {
        $harness = $this;

        return new class ($harness) implements MolliePaymentsAdapterInterface {
            public function __construct(private readonly object $harness)
            {
            }

            public function createPayment(\OxidEsales\Payments\Mollie\Adapter\Dto\CreatePaymentRequest $request): MolliePaymentDto
            {
                throw new RuntimeException('not used');
            }

            public function getPayment(string $paymentId): MolliePaymentDto
            {
                return Closure::bind(function () {
                    if ($this->mollieDown || $this->livePayment === null) {
                        throw new RuntimeException('Mollie cannot be read');
                    }

                    return $this->livePayment;
                }, $this->harness, $this->harness)();
            }

            public function cancelPayment(string $paymentId): MolliePaymentDto
            {
                throw new RuntimeException('not used');
            }

            public function releaseAuthorization(string $paymentId): void
            {
                throw new RuntimeException('not used');
            }
        };
    }

    /**
     * Records what the panel asked of the dispatcher and, like the real handlers, books the effect on the contract.
     */
    private function recordingDispatcher(): AdminActionDispatcherInterface&MollieExtraActionDispatcherInterface
    {
        $harness = $this;

        return new class ($harness) implements AdminActionDispatcherInterface, MollieExtraActionDispatcherInterface {
            public function __construct(private readonly object $harness)
            {
            }

            public function refund(Order $order, ?float $amount, ?string $reason, array $extras = []): void
            {
                $this->book($order, 'refund', $amount, static function (PaymentContract $contract, ?float $amount): void {
                    $contract->addRefundedAmount($amount ?? 0.0);
                });
            }

            public function capture(Order $order, ?float $amount, ?string $reason, array $extras = []): void
            {
                $this->book($order, 'capture', $amount, static function (PaymentContract $contract, ?float $amount): void {
                    $contract->setCapturedAmount(($contract->getCapturedAmount() ?? 0.0) + ($amount ?? $contract->getAmount()));
                });
            }

            public function cancel(Order $order, ?string $reason, array $extras = []): void
            {
                $this->book($order, 'cancel', null, static function (PaymentContract $contract): void {
                });
            }

            public function release(Order $order, ?string $reason = null): bool
            {
                $succeeds = Closure::bind(fn (): bool => $this->releaseSucceeds, $this->harness, $this->harness)();
                $this->book($order, 'release:' . ($reason ?? '-'), null, static function (PaymentContract $contract) use ($succeeds): void {
                    if ($succeeds) {
                        $contract->fulfill();
                    }
                });

                return $succeeds;
            }

            private function book(Order $order, string $action, ?float $amount, Closure $effect): void
            {
                Closure::bind(function () use ($order, $action, $amount, $effect): void {
                    $this->actionsRun[] = $action . ($amount === null ? '' : ':' . $amount);
                    $contract = $this->contracts()->findByOrderId((string) $order->getId());
                    $effect($contract, $amount);
                    $this->contracts()->save($contract);
                }, $this->harness, $this->harness)();
            }
        };
    }

    private function countingStepUp(): AdminStepUpInterface
    {
        $harness = $this;

        return new class ($harness) implements AdminStepUpInterface {
            public function __construct(private readonly object $harness)
            {
            }

            public function require(): void
            {
                Closure::bind(function (): void {
                    ++$this->stepUps;
                }, $this->harness, $this->harness)();
            }
        };
    }

    private function passThroughAudit(): AdminAuditInterface
    {
        return new class implements AdminAuditInterface {
            public function audited(string $operationName, string $orderId, array $changedFields, ?float $amount, Closure $action): PaymentOperationResult
            {
                return $action();
            }
        };
    }

    private function saveCommittedMollieContract(
        string $orderId,
        float $gross,
        ?float $captured,
        string $paymentId,
        bool $fulfilled = true
    ): PaymentContract
    {
        $contract = new PaymentContract(1, 'pay5-user', BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => $gross,
            'totalNet' => round($gross / 1.19, 2),
            'totalVat' => round($gross - $gross / 1.19, 2),
            'currency' => 'EUR',
        ]));
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $contract->setProvider('mollie', $paymentId);
        $contract->authorize();
        $contract->captureAuthorization();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder($orderId);
        if ($captured !== null) {
            $contract->setCapturedAmount($captured);
        }
        if ($fulfilled) {
            $contract->fulfill();
        }
        $this->contracts()->save($contract);

        return $contract;
    }

    /**
     * Built on the connection the test transaction covers: the container's own connection is another one, and what it
     * writes is committed and survives the test.
     */
    private function contracts(): ContractRepositoryInterface
    {
        return $this->contractRepository ??= new DoctrineContractRepository($this->connection());
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = ContainerFactory::getInstance()->getContainer()->get(ConnectionProviderInterface::class)->get();

        return $connection;
    }
}
