<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Support;

use Closure;
use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentBase\Admin\PaymentPanelRegistry;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminAuditInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminStepUpInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\DbalRequestShopOrders;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OperationIdempotency;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PanelActionBoundsResolver;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PanelActionOptionsResolver;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetailsReader;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use OxidEsales\PaymentBase\Repository\DoctrineIdempotencyRepository;
use OxidEsales\PaymentBase\Repository\DoctrineTransactionRepository;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\PaymentBase\Validation\User\UserDataValidatorInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionBounds;
use OxidEsales\Payments\Mollie\Admin\AdminActionRefusals;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Mollie\Admin\MolliePanelOrderLoader;
use OxidEsales\Payments\Mollie\Admin\MolliePanelViewDataBuilder;
use OxidEsales\Payments\Mollie\Admin\MolliePaymentPanelProvider;
use OxidEsales\Payments\Mollie\Admin\MollieReleaseAction;
use OxidEsales\Payments\Mollie\Admin\MolliePaymentSnapshotProvider;
use OxidEsales\Payments\Mollie\Admin\ReleaseRule;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieReleaseAuthorizationRequestEvent;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieReleaseOperation;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Mollie\Service\UserDataValidationMessageFormatter;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The Mollie panel provider and the Mollie-only release behind payment-base's admin payment API, against the real
 * database and the real payment-base services (contract and transaction repositories, bounds and options resolvers,
 * idempotency), with only Mollie itself faked: the payments adapter answers with a scripted live payment (or fails)
 * and the event dispatcher records the release it was asked to run. Capture, refund and void are payment-base's
 * money runner's (VR-22 A3) and Mollie's gateways' (unit-tested); no Mollie sandbox is involved here.
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

    private function detailsReader(): PaymentContractDetailsReader
    {
        $registry = new PaymentPanelRegistry([$this->panel()]);

        return new PaymentContractDetailsReader(
            $this->contracts(),
            new DoctrineTransactionRepository($this->connection()),
            new PanelActionOptionsResolver($registry, new NullLogger()),
            new PanelActionBoundsResolver($registry, new NullLogger(), new ThrowableSummary([])),
        );
    }

    private function releaseOperation(): MollieReleaseOperation
    {
        $connection = $this->connection();

        return new MollieReleaseOperation(
            $this->detailsReader(),
            $this->releaseAction(),
            new OperationIdempotency(new DoctrineIdempotencyRepository($connection)),
            $this->countingStepUp(),
            $this->passThroughAudit(),
            new DbalRequestShopOrders($connection),
            new NullLogger(),
        );
    }

    private function panel(): MolliePaymentPanelProvider
    {
        return $this->panel ??= new MolliePaymentPanelProvider(
            $this->createMock(MolliePanelViewDataBuilder::class),
            new MolliePanelOrderLoader(),
            $this->bounds(),
            $this->releaseAction(),
        );
    }

    private function bounds(): AdminActionBounds
    {
        return new AdminActionBounds(new MolliePaymentSnapshotProvider($this->paymentsAdapter(), new NullLogger(), new ThrowableSummary([])));
    }

    private function releaseAction(): MollieReleaseAction
    {
        $container = ContainerFactory::getInstance()->getContainer();

        return new MollieReleaseAction(
            $this->contracts(),
            new ReleaseRule($this->bounds()),
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
            $this->recordingDispatcher(),
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
     * Records the release it is asked to run and, like the real handler, books the effect on the contract.
     */
    private function recordingDispatcher(): EventDispatcherInterface
    {
        $harness = $this;

        return new class ($harness) implements EventDispatcherInterface {
            public function __construct(private readonly object $harness)
            {
            }

            public function addListener(string $eventClass, callable $listener, int $priority = 0): void
            {
            }

            public function removeListener(string $eventClass, callable $listener): void
            {
            }

            public function dispatch(EventInterface $event): EventInterface
            {
                if (!$event instanceof MollieReleaseAuthorizationRequestEvent) {
                    return $event;
                }
                $succeeds = Closure::bind(fn (): bool => $this->releaseSucceeds, $this->harness, $this->harness)();
                Closure::bind(function () use ($event, $succeeds): void {
                    $this->actionsRun[] = 'release:' . ($event->reason ?? '-');
                    if ($succeeds) {
                        $contract = $this->contracts()->findByOrderId((string) $event->contract->getOrderId());
                        $contract->fulfill();
                        $this->contracts()->save($contract);
                    }
                }, $this->harness, $this->harness)();
                $event->setResult($succeeds, $succeeds ? null : 'release_failed', $succeeds ? null : 'refused');

                return $event;
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
        return $this->contractRepository ??= new DoctrineContractRepository($this->connection(), new NullLogger(), new ThrowableSummary([]));
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = ContainerFactory::getInstance()->getContainer()->get(ConnectionProviderInterface::class)->get();

        return $connection;
    }
}
