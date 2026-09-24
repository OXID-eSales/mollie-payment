<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;

/**
 * Session-backed implementation of the admin validation feedback channel.
 *
 * The session payload is a plain list of already-formatted message strings — never serialized
 * value objects. The key is namespaced per order so two admins editing different orders in one
 * session do not cross-talk. Reuses {@see SessionAdapterInterface} (payment-base's shop-glue
 * seam, already aliased to `OxidSessionAdapter` for this module) rather than reaching into
 * `Registry::getSession()` directly.
 */
final class AdminValidationFeedback implements AdminValidationFeedbackInterface
{
    private const SESSION_KEY_PREFIX = 'mollie_admin_validation_';

    public function __construct(
        private readonly SessionAdapterInterface $session,
        private readonly MessageFormatterInterface $messageFormatter,
    ) {
    }

    public function reject(string $orderId, string $field, string $code): void
    {
        $this->rejectWithMessage($orderId, $this->messageFormatter->format($field, $code, null));
    }

    public function rejectWithMessage(string $orderId, string $message): void
    {
        $entries = $this->readEntries($orderId);
        $entries[] = $message;
        $this->session->setVariable(self::sessionKey($orderId), $entries);
    }

    public function consume(string $orderId): array
    {
        $key = self::sessionKey($orderId);
        $entries = $this->readEntries($orderId);
        $this->session->setVariable($key, null);

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function readEntries(string $orderId): array
    {
        $stored = $this->session->getVariable(self::sessionKey($orderId));
        if (!is_array($stored)) {
            return [];
        }

        return array_values(array_filter($stored, 'is_string'));
    }

    private static function sessionKey(string $orderId): string
    {
        return self::SESSION_KEY_PREFIX . $orderId;
    }
}
