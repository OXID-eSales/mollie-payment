<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminAuditInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminStepUpInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\IdempotencyClaim;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OperationIdempotencyInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetails;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetailsReaderInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRefusal;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\ProviderActionOutcome;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\ProviderActionRunnerInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\RequestShopOrdersInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * `adminMolliePaymentRelease`: the MOL-32 release of the Payment tab, for the admin API. The rules are NOT
 * re-implemented here: the order's Mollie panel provider runs its own `release` action (the same {@see
 * \OxidEsales\Payments\Mollie\Admin\ReleaseRule}, the same reason validation, the same Mollie event as the classic
 * button) through payment-base's provider-action boundary; this service adds what the admin API adds to every money
 * operation: step-up, the request's shop, an idempotency record, one audit row, and the read-back of what the
 * provider achieved. Releasing moves no money to the shop, but it gives money back to the customer and ends the
 * order's capturing, so it takes the step-up like a capture does.
 */
final class MollieReleaseOperation implements MollieReleaseOperationInterface
{
    public const OPERATION = 'adminMolliePaymentRelease';
    public const PROVIDER_ACTION = 'release';

    private const IDEMPOTENCY_NAME = 'admin_mollie_release';
    private const KEY_PATTERN = '/^[A-Za-z0-9_-]{8,64}$/';
    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/';

    public function __construct(
        private readonly PaymentContractDetailsReaderInterface $details,
        private readonly ProviderActionRunnerInterface $provider,
        private readonly OperationIdempotencyInterface $idempotency,
        private readonly AdminStepUpInterface $stepUp,
        private readonly AdminAuditInterface $audit,
        private readonly RequestShopOrdersInterface $shopOrders,
    ) {
    }

    public function release(string $orderId, ?string $reason, ?string $idempotencyKey): PaymentOperationResult
    {
        $reason = $this->blankToNull($reason);

        return $this->audit->audited(
            self::OPERATION,
            $orderId,
            $reason === null ? [] : ['reason'],
            null,
            function () use ($orderId, $reason, $idempotencyKey): PaymentOperationResult {
                $this->stepUp->require();

                return $this->perform($orderId, $reason, $idempotencyKey);
            },
        );
    }

    private function perform(string $orderId, ?string $reason, ?string $idempotencyKey): PaymentOperationResult
    {
        $inputRefusal = $this->inputRefusal($reason, $idempotencyKey);
        if ($inputRefusal !== null) {
            return PaymentOperationResult::refused($inputRefusal);
        }
        $before = $this->shopOrders->contains($orderId) ? $this->details->findByOrderId($orderId) : null;
        if ($before === null) {
            return PaymentOperationResult::refused(PaymentRefusal::notFound());
        }
        if ($before->provider !== MollieDefinitions::PROVIDER_NAME) {
            return PaymentOperationResult::refused(
                PaymentRefusal::invalid(
                    'orderId',
                    'not_releasable',
                    'Only a Mollie payment has an authorization to release'
                )
            );
        }
        $claim = $this->idempotency->claim(
            self::IDEMPOTENCY_NAME,
            $orderId,
            $this->discriminator($before, $idempotencyKey)
        );
        if ($claim->outcome === IdempotencyClaim::COMPLETED) {
            return PaymentOperationResult::done($before);
        }
        if ($claim->outcome !== IdempotencyClaim::FRESH) {
            return PaymentOperationResult::refused(
                PaymentRefusal::invalid('idempotencyKey', 'in_progress', 'The same call is still running')
            );
        }

        return $this->execute($orderId, $reason, $before, $claim);
    }

    private function execute(
        string $orderId,
        ?string $reason,
        PaymentContractDetails $before,
        IdempotencyClaim $claim
    ): PaymentOperationResult {
        $request = $reason === null ? [] : ['release_reason' => $reason];
        $refusal = $this->refusalOf($this->provider->run($before->provider, $orderId, self::PROVIDER_ACTION, $request));
        $after = $refusal === null ? $this->details->findByOrderId($orderId) : null;
        if ($after !== null && $after->fingerprint() !== $before->fingerprint()) {
            $this->idempotency->complete($claim);

            return PaymentOperationResult::done($after);
        }
        $this->idempotency->release($claim);

        return PaymentOperationResult::refused($refusal ?? PaymentRefusal::invalid(
            'orderId',
            'not_confirmed',
            'Mollie did not confirm the release; check the transactions of the payment and the Mollie dashboard',
        ));
    }

    private function refusalOf(ProviderActionOutcome $outcome): ?PaymentRefusal
    {
        return match ($outcome->status) {
            ProviderActionRunnerInterface::REFUSED => PaymentRefusal::invalid(
                'orderId',
                'provider_refused',
                (string) $outcome->refusalMessage
            ),
            ProviderActionRunnerInterface::UNAVAILABLE => PaymentRefusal::invalid(
                'orderId',
                'provider_unavailable',
                'No payment provider module handles this payment'
            ),
            ProviderActionRunnerInterface::FAILED => PaymentRefusal::invalid(
                'orderId',
                'provider_failed',
                'Mollie refused or could not complete the release'
            ),
            default => null,
        };
    }

    private function inputRefusal(?string $reason, ?string $idempotencyKey): ?PaymentRefusal
    {
        if ($idempotencyKey !== null && preg_match(self::KEY_PATTERN, $idempotencyKey) !== 1) {
            return PaymentRefusal::invalid(
                'idempotencyKey',
                'invalid_key',
                'The idempotency key must be 8 to 64 letters, digits, "-" or "_"'
            );
        }
        if ($reason === null) {
            return null;
        }
        if (mb_strlen($reason) > MollieDefinitions::ADMIN_TEXT_MAX_LENGTH) {
            return PaymentRefusal::invalid(
                'reason',
                'too_long',
                sprintf('The reason is longer than %d characters', MollieDefinitions::ADMIN_TEXT_MAX_LENGTH)
            );
        }

        return preg_match(self::CONTROL_CHARACTERS, $reason) === 1
            ? PaymentRefusal::invalid('reason', 'invalid_characters', 'The reason must not contain control characters')
            : null;
    }

    private function discriminator(PaymentContractDetails $payment, ?string $idempotencyKey): string
    {
        return $idempotencyKey !== null ? 'client:' . $idempotencyKey : 'state:' . $payment->fingerprint();
    }

    private function blankToNull(?string $text): ?string
    {
        $trimmed = $text === null ? '' : trim($text);

        return $trimmed === '' ? null : $trimmed;
    }
}
