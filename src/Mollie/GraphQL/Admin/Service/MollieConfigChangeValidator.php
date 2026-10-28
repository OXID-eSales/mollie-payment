<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * Server-side validation of a settings change (the client's own validation is UX only). Messages name the field and
 * the rule, never the value: an invalid API key must not be echoed anywhere.
 */
final class MollieConfigChangeValidator
{
    private const KEY_BODY_PATTERN = '/^[A-Za-z0-9]+$/';
    // Mollie's SDK refuses a key shorter than this (MollieClientFactory), so a shorter one would be stored and
    // then fail every payment.
    private const MIN_KEY_LENGTH = 30;
    private const MAX_KEY_LENGTH = 255;
    private const PROFILE_ID_PATTERN = '/^pfl_[A-Za-z0-9]{1,64}$/';
    private const MAX_URL_LENGTH = 255;
    private const URL_SCHEMES = ['http', 'https'];

    public function validate(MollieConfigChange $change): ?MollieAdminRefusal
    {
        return $this->choices($change)
            ?? $this->key($change, MollieDefinitions::MODE_TEST, 'testKey')
            ?? $this->key($change, MollieDefinitions::MODE_LIVE, 'liveKey')
            ?? $this->profileId($change)
            ?? $this->webhookUrl($change);
    }

    private function choices(MollieConfigChange $change): ?MollieAdminRefusal
    {
        $choices = [
            'mode' => [$change->mode, [MollieDefinitions::MODE_TEST, MollieDefinitions::MODE_LIVE]],
            'captureMode' => [
                $change->captureMode,
                [MollieDefinitions::CAPTURE_MODE_AUTOMATIC, MollieDefinitions::CAPTURE_MODE_MANUAL],
            ],
            'logLevel' => [
                $change->logLevel,
                [
                    MollieDefinitions::LOG_LEVEL_OFF,
                    MollieDefinitions::LOG_LEVEL_ERRORS,
                    MollieDefinitions::LOG_LEVEL_NORMAL,
                    MollieDefinitions::LOG_LEVEL_DEBUG,
                ],
            ],
        ];
        foreach ($choices as $field => [$value, $allowed]) {
            if ($value !== null && !in_array($value, $allowed, true)) {
                return MollieAdminRefusal::invalid(
                    $field,
                    'not_allowed',
                    sprintf('The %s must be one of: %s', $field, implode(', ', $allowed))
                );
            }
        }

        return null;
    }

    private function key(MollieConfigChange $change, string $mode, string $field): ?MollieAdminRefusal
    {
        $key = $change->keyFor($mode);
        if ($key === null) {
            return null;
        }
        $prefix = $mode . '_';
        if (!str_starts_with($key, $prefix)) {
            return MollieAdminRefusal::invalid(
                $field,
                'invalid_prefix',
                sprintf('The %s must start with %s', $field, $prefix)
            );
        }
        $length = strlen($key);
        $wellFormed = $length >= self::MIN_KEY_LENGTH
            && $length <= self::MAX_KEY_LENGTH
            && preg_match(self::KEY_BODY_PATTERN, substr($key, strlen($prefix))) === 1;

        return $wellFormed
            ? null
            : MollieAdminRefusal::invalid(
                $field,
                'invalid_format',
                sprintf('The %s holds letters and digits after %s, at least 30 characters in all', $field, $prefix)
            );
    }

    private function profileId(MollieConfigChange $change): ?MollieAdminRefusal
    {
        // An empty profile id is allowed: the storefront then falls back to Mollie's hosted card page.
        if ($change->profileId === null || $change->profileId === '') {
            return null;
        }

        return preg_match(self::PROFILE_ID_PATTERN, $change->profileId) === 1
            ? null
            : MollieAdminRefusal::invalid(
                'profileId',
                'invalid_format',
                'The profile id looks like pfl_ followed by letters and digits'
            );
    }

    private function webhookUrl(MollieConfigChange $change): ?MollieAdminRefusal
    {
        $url = $change->webhookUrlOverride;
        // Empty removes the override: the shop's own webhook URL is used then.
        if ($url === null || $url === '') {
            return null;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $acceptable = strlen($url) <= self::MAX_URL_LENGTH
            && preg_match('/[\s\x00-\x1F\x7F]/', $url) !== 1
            && is_string($scheme)
            && in_array(strtolower($scheme), self::URL_SCHEMES, true)
            && is_string(parse_url($url, PHP_URL_HOST));

        return $acceptable
            ? null
            : MollieAdminRefusal::invalid(
                'webhookUrlOverride',
                'invalid_format',
                'The webhook URL is an http(s) address without spaces (up to 255 characters); Mollie needs https'
            );
    }
}
