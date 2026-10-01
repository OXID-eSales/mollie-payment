<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;

/**
 * Tells the operator why a capture or a release of an authorization did not happen.
 *
 * The admin request events travel through payment-base's broker, which hands back the agnostic
 * event, not Mollie's — so the error the Mollie handler recorded on its event never reached
 * the panel, and a refused action re-rendered an unchanged tab without a word (2026-10-01).
 * The handlers now report the failure through the same consume-once feedback channel the
 * amount validation uses, so the next render shows it exactly once.
 */
final class AdminActionFailureReporter
{
    public const ACTION_CAPTURE = 'capture';
    public const ACTION_CANCEL = 'cancel';

    private const MESSAGE_KEYS = [
        self::ACTION_CAPTURE => 'MOLLIE_ADMIN_CAPTURE_FAILED',
        self::ACTION_CANCEL => 'MOLLIE_ADMIN_CANCEL_FAILED',
    ];

    public function __construct(
        private readonly AdminValidationFeedbackInterface $feedback,
        private readonly LanguageTranslatorInterface $translator,
    ) {
    }

    public function report(PaymentContractInterface $contract, string $action, string $detail): void
    {
        $orderId = $contract->getOrderId();
        if ($orderId === null || $orderId === '') {
            return;
        }

        $this->feedback->rejectWithMessage($orderId, $this->message($action, $detail));
    }

    private function message(string $action, string $detail): string
    {
        $key = self::MESSAGE_KEYS[$action] ?? self::MESSAGE_KEYS[self::ACTION_CAPTURE];
        $template = $this->translator->translateString($key);

        // OXID hands the ident back when it has no translation; never show a bare key.
        if ($template === '' || $template === $key || !str_contains($template, '%s')) {
            return $detail;
        }

        return sprintf($template, $detail);
    }
}
