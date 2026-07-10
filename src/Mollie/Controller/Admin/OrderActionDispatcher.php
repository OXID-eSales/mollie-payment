<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionDispatcherInterface;
use OxidEsales\PaymentBase\EventSystem\Broker\EventBrokerInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\Request\AbstractProviderRequestEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CancelAuthorizationRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CaptureRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\RefundRequestedEvent;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * Dispatches admin-initiated Mollie order actions (refund / capture / cancel) through
 * payment-base's provider-agnostic {@see EventBrokerInterface}.
 *
 * Unlike PayPal's `OrderActionDispatcher` (which currently only builds a context — the concrete
 * event dispatch there is left to a future sprint), this dispatcher actually routes through the
 * broker: it builds an `EventContext` carrying the resolved contract + provider name, dispatches
 * the provider-agnostic `*RequestedEvent`, and lets {@see \OxidEsales\Payments\Mollie\EventSystem\Translator\MollieEventTranslator}
 * translate it into the Mollie concrete event that {@see \OxidEsales\Payments\Mollie\EventSystem\Handler\MollieRefundRequestHandler}
 * (and its capture/cancel siblings) consume. No admin UI is wired to this yet (Sprint 7) — Sprint
 * 6 exercises it directly from unit tests.
 */
final class OrderActionDispatcher implements AdminActionDispatcherInterface
{
    public function __construct(
        private readonly EventBrokerInterface $eventBroker,
        private readonly ContractRepositoryInterface $contractRepository,
    ) {
    }

    /** @param array<string, mixed> $extras */
    public function refund(Order $order, ?float $amount, ?string $reason, array $extras = []): void
    {
        // Story 3 (Sprint 9): Extract optional description from extras for audit trail.
        $description = isset($extras['description']) && is_string($extras['description']) && $extras['description'] !== ''
            ? $extras['description']
            : null;

        $context = $this->buildContext($order);
        if ($description !== null) {
            $context->set('refundDescription', $description);
        }

        $this->dispatch(new RefundRequestedEvent($context, $amount, $reason));
    }

    /** @param array<string, mixed> $extras */
    public function capture(Order $order, ?float $amount, ?string $reason, array $extras = []): void
    {
        $this->dispatch(new CaptureRequestedEvent($this->buildContext($order), $amount, $reason));
    }

    /** @param array<string, mixed> $extras */
    public function cancel(Order $order, ?string $reason, array $extras = []): void
    {
        $this->dispatch(new CancelAuthorizationRequestedEvent($this->buildContext($order), null, $reason));
    }

    private function buildContext(Order $order): EventContext
    {
        $orderId = (string) $order->getId();
        $context = new EventContext([
            'orderId' => $orderId,
            'providerName' => MollieDefinitions::PROVIDER_NAME,
        ]);

        $contract = $this->contractRepository->findByOrderId($orderId);
        if ($contract !== null) {
            $context->setContract($contract);
        }

        return $context;
    }

    private function dispatch(AbstractProviderRequestEvent $event): void
    {
        $this->eventBroker->dispatch($event);
    }
}
