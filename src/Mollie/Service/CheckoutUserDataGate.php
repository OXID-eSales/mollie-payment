<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * MOL-15: the one question both checkout controllers ask before Mollie is involved - "may this
 * shopper's address data go to the PSP?" - answered as the list of messages to show when it may not.
 *
 * Billing and (when selected) delivery address run through payment-base's ValidationBase with
 * Mollie's rules; each failure becomes the same translated sentence the OPC footer and the admin
 * panel show ("The <field> field is not valid. Allowed symbols are: …"), followed by one "please
 * review your address" notice. Fail-open with a logged warning when the validator cannot run:
 * defence in depth must not take the checkout down.
 */
final class CheckoutUserDataGate
{
    private const REVIEW_KEY = 'MOLLIE_VALIDATION_REVIEW_ADDRESS';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly UserDataValidatorInterface $validator,
        private readonly MessageFormatterInterface $messageFormatter,
        private readonly LanguageTranslatorInterface $translator,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
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
            $failures = $this->validator->validateForUser(new OxidUserFieldReader($user));
        } catch (Throwable $e) {
            $this->logger->warning('[CheckoutUserDataGate] user-data validation unavailable; allowing checkout', [
                'error' => $e->getMessage(),
            ]);

            return [];
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
