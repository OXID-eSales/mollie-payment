<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * Fails fast on mode/key inconsistencies: a Mollie API key must be non-empty, at least 30
 * characters, and carry the prefix matching the active mode (test_ in test, live_ in live).
 * A regex + prefix check — deliberately not a rules engine (no overengineering).
 */
final class ConfigurationValidator implements ConfigurationValidatorInterface
{
    private const MIN_KEY_LENGTH = 30;

    public function __construct(
        private readonly ModuleConfigurationServiceInterface $config,
    ) {
    }

    public function validate(): ConfigurationValidationResult
    {
        $mode = $this->config->getMode();
        $key = $this->config->getApiKey();
        $expectedPrefix = $mode . '_';

        $errors = [];
        if ($key === '') {
            $errors[] = sprintf('No Mollie API key configured for %s mode.', $mode);
            return ConfigurationValidationResult::invalid($errors);
        }
        if (!str_starts_with($key, $expectedPrefix)) {
            $errors[] = sprintf('API key for %s mode must start with "%s".', $mode, $expectedPrefix);
        }
        if (strlen($key) < self::MIN_KEY_LENGTH) {
            $errors[] = sprintf('API key looks too short (min %d characters).', self::MIN_KEY_LENGTH);
        }

        return $errors === []
            ? ConfigurationValidationResult::valid()
            : ConfigurationValidationResult::invalid($errors);
    }

    public function isLiveMode(): bool
    {
        return $this->config->getMode() === MollieDefinitions::MODE_LIVE;
    }
}
