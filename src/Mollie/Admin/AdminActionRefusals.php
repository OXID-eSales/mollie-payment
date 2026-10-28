<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Mollie\Service\FieldValidationFailure;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;

/**
 * How the Payment tab's action handler says no. Every refusal is recorded where the classic tab shows it (the
 * one-render validation feedback) and handed back as the typed exception payment-base's admin API turns into
 * `provider_refused`: the sentence is the same on both paths, and `feedbackRecorded` tells the classic tab not to
 * show it twice. The sentences carry no ids, no payloads and no secrets.
 */
final class AdminActionRefusals
{
    public const CODE_NOT_RELEASABLE = 'not_releasable';
    public const CODE_RELEASE_FAILED = 'release_failed';

    private const MESSAGE_NOT_RELEASABLE = 'MOLLIE_ADMIN_RELEASE_NOT_POSSIBLE';
    private const MESSAGE_RELEASE_FAILED = 'MOLLIE_ADMIN_RELEASE_FAILED';

    public function __construct(
        private readonly AdminValidationFeedbackInterface $feedback,
        // MOL-15: admin free text is formatted by the tagged user-data formatter, not by the amount formatter that
        // AdminValidationFeedback owns.
        private readonly MessageFormatterInterface $textFormatter,
        private readonly LanguageTranslatorInterface $translator,
    ) {
    }

    public function amount(string $orderId, string $field, string $code): AdminActionRefusedException
    {
        return new AdminActionRefusedException(
            self::machineCode($code),
            $this->feedback->reject($orderId, $field, $code),
            true
        );
    }

    /**
     * @param non-empty-list<FieldValidationFailure> $failures every one is recorded; the first is the sentence
     */
    public function text(string $orderId, array $failures): AdminActionRefusedException
    {
        $messages = [];
        foreach ($failures as $failure) {
            $message = $this->textFormatter->format($failure->field, $failure->code, $failure->offendingChar);
            $this->feedback->rejectWithMessage($orderId, $message);
            $messages[] = $message;
        }

        return new AdminActionRefusedException(self::machineCode($failures[0]->code), $messages[0], true);
    }

    /**
     * The remainder may not be released (not an authorized hold, nothing captured yet, nothing left): nothing was
     * recorded for the classic tab, so the dispatcher shows it.
     */
    public function notReleasable(): AdminActionRefusedException
    {
        return new AdminActionRefusedException(
            self::CODE_NOT_RELEASABLE,
            $this->translator->translateString(self::MESSAGE_NOT_RELEASABLE),
        );
    }

    /**
     * The release handler already recorded this sentence for the classic tab.
     */
    public function releaseFailed(): AdminActionRefusedException
    {
        return new AdminActionRefusedException(
            self::CODE_RELEASE_FAILED,
            $this->translator->translateString(self::MESSAGE_RELEASE_FAILED),
            true,
        );
    }

    /**
     * The validators name their codes in camelCase (`amountExceedsBound`); the refusal's machine code is snake_case.
     */
    private static function machineCode(string $code): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $code));
    }
}
