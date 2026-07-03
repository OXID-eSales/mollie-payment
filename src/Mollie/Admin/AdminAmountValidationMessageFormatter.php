<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;

/**
 * Builds the admin-facing message for a failed {@see AdminAmountValidator} result.
 *
 * Deliberately implements the SAME payment-base SPI
 * ({@see MessageFormatterInterface}, tag `oe.payment_base.validation_message_formatter`) that
 * {@see \OxidEsales\Payments\Mollie\Service\UserDataValidationMessageFormatter} uses for
 * checkout's character-level validation — "same plumbing, different rules" (Sprint 7 spec). This
 * class is registered as a plain service and injected directly into
 * {@see AdminValidationFeedback} by class reference, NOT tagged with
 * `oe.payment_base.validation_message_formatter`: that tag feeds the shared
 * `ValidationApiController`'s iterator, which picks a formatter by `pluginModuleId` alone. Both
 * this formatter and `UserDataValidationMessageFormatter` share `MollieDefinitions::MODULE_ID`;
 * tagging both would make the controller's formatter resolution ambiguous for that endpoint. The
 * admin panel calls this formatter directly, so no tag/iterator is needed for its own use case.
 */
final class AdminAmountValidationMessageFormatter implements MessageFormatterInterface
{
    private const FALLBACK_KEY = 'MOLLIE_ADMIN_AMOUNT_INVALID';

    /** @var array<string, string> */
    private const TEMPLATE_KEYS = [
        AmountValidationResult::CODE_MALFORMED => 'MOLLIE_ADMIN_AMOUNT_MALFORMED',
        AmountValidationResult::CODE_NOT_POSITIVE => 'MOLLIE_ADMIN_AMOUNT_NOT_POSITIVE',
        AmountValidationResult::CODE_PRECISION => 'MOLLIE_ADMIN_AMOUNT_PRECISION',
        AmountValidationResult::CODE_EXCEEDS_BOUND => 'MOLLIE_ADMIN_AMOUNT_EXCEEDS_BOUND',
    ];

    public function __construct(private readonly LanguageTranslatorInterface $translator)
    {
    }

    public function getPluginModuleId(): string
    {
        return MollieDefinitions::MODULE_ID;
    }

    public function format(string $field, string $code, ?string $offendingChar): string
    {
        $key = self::TEMPLATE_KEYS[$code] ?? self::FALLBACK_KEY;

        return $this->translator->translateString($key);
    }
}
