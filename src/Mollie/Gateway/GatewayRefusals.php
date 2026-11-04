<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Gateway;

use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\PaymentBase\Validation\User\FieldValidationFailure;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;

/**
 * How Mollie's money gateways say no to the admin (VR-22 A6): a translated sentence the admin can read, never
 * Mollie's own text (the SDK puts the request into it). Nothing is recorded for the classic tab: payment-base's
 * money runner shows the refusal, for the classic admin and the admin API alike.
 */
final class GatewayRefusals
{
    private const MESSAGE_CAPTURE_FAILED = 'MOLLIE_ADMIN_CAPTURE_FAILED';
    private const MESSAGE_CAPTURE_FINAL = 'MOLLIE_ADMIN_CAPTURE_FINAL';
    private const MESSAGE_REFUND_FAILED = 'MOLLIE_ADMIN_REFUND_FAILED';
    private const MESSAGE_REFUND_SETTLING = 'MOLLIE_ADMIN_REFUND_CAPTURE_SETTLING';
    private const MESSAGE_CANCEL_FAILED = 'MOLLIE_ADMIN_CANCEL_FAILED';
    private const MESSAGE_NO_PAYMENT = 'MOLLIE_ADMIN_NO_PAYMENT';

    public function __construct(
        private readonly LanguageTranslatorInterface $translator,
        // MOL-15: admin free text is formatted by the tagged user-data formatter.
        private readonly MessageFormatterInterface $textFormatter,
    ) {
    }

    public function captureFailed(): AdminActionRefusedException
    {
        return $this->refusal('capture_failed', self::MESSAGE_CAPTURE_FAILED);
    }

    /** Mollie captures once on an account without multi-capture: what was not captured is released. */
    public function captureFinal(): AdminActionRefusedException
    {
        return $this->refusal('capture_final', self::MESSAGE_CAPTURE_FINAL);
    }

    public function refundFailed(): AdminActionRefusedException
    {
        return $this->refusal('refund_failed', self::MESSAGE_REFUND_FAILED);
    }

    public function refundSettling(): AdminActionRefusedException
    {
        return $this->refusal('capture_settling', self::MESSAGE_REFUND_SETTLING);
    }

    public function cancelFailed(): AdminActionRefusedException
    {
        return $this->refusal('cancel_failed', self::MESSAGE_CANCEL_FAILED);
    }

    public function noPayment(): AdminActionRefusedException
    {
        return $this->refusal('no_payment', self::MESSAGE_NO_PAYMENT);
    }

    /**
     * @param non-empty-list<FieldValidationFailure> $failures
     */
    public function text(array $failures): AdminActionRefusedException
    {
        $first = $failures[0];

        return new AdminActionRefusedException(
            self::machineCode($first->code),
            $this->textFormatter->format($first->field, $first->code, $first->offendingChar),
        );
    }

    private function refusal(string $code, string $messageKey): AdminActionRefusedException
    {
        return new AdminActionRefusedException($code, $this->translator->translateString($messageKey));
    }

    private static function machineCode(string $code): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $code));
    }
}
