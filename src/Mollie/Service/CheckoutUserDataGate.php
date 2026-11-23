<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\PaymentBase\Validation\User\OxidUserFieldReader;
use OxidEsales\PaymentBase\Validation\User\UserDataValidatorInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MOL-15: the one question both checkout controllers ask before Mollie is involved - "may this
 * shopper's address data go to the PSP?" - answered as the list of messages to show when it may not.
 *
 * Billing and (when selected) delivery address run through payment-base's ValidationBase with
 * Mollie's rules; each failure becomes the same translated sentence the OPC footer and the admin
 * panel show ("The <field> field is not valid. Allowed symbols are: …"), followed by one "please
 * review your address" notice.
 *
 * Sprint 149 (AW): fail closed. When the validator cannot run, the data is not validated and does not
 * go to Mollie: the answer is the "payment not available right now" sentence, with an error log line.
 * Before, the gate answered "no problems" with a warning.
 */
final class CheckoutUserDataGate implements CheckoutUserDataGateInterface
{
    private const REVIEW_KEY = 'MOLLIE_VALIDATION_REVIEW_ADDRESS';

    /** The sentence the checkout shows when Mollie cannot be used right now. */
    private const UNAVAILABLE_KEY = 'MOLLIE_CHECKOUT_UNAVAILABLE';

    public function __construct(
        private readonly UserDataValidatorInterface $validator,
        private readonly MessageFormatterInterface $messageFormatter,
        private readonly LanguageTranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly ThrowableSummaryInterface $throwableSummary,
    ) {
    }

    /**
     * @return list<string> translated messages to show; empty when the data may be sent
     */
    public function problemsFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        try {
            $failures = $this->validator->validateForUser(
                MollieDefinitions::MODULE_ID,
                new OxidUserFieldReader($user),
            );
        } catch (Throwable $e) {
            $this->logger->error('[CheckoutUserDataGate] user-data validation unavailable; checkout refused', [
                'exception' => $this->throwableSummary->fromThrowable($e),
            ]);

            return [$this->translator->translateString(self::UNAVAILABLE_KEY)];
        }

        if ($failures === []) {
            return [];
        }

        $messages = [];
        foreach ($failures as $failure) {
            $messages[] = $this->messageFormatter->format($failure->field, $failure->code, $failure->offendingChar);
        }
        $messages = array_values(array_unique($messages));
        $messages[] = $this->translator->translateString(self::REVIEW_KEY);

        return $messages;
    }
}
