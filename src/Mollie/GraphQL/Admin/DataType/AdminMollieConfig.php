<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\DataType;

use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigSnapshot;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * The Mollie settings of the shop. `testKeySet` / `liveKeySet` say whether an API key is stored, and no field carries
 * its value, ever. `version` identifies the state of the settings that was read.
 *
 * The getters carry the GraphQL field names (graphqlite strips get/is).
 */
#[Type(name: 'AdminMollieConfig')]
final class AdminMollieConfig
{
    public function __construct(private readonly MollieConfigSnapshot $config)
    {
    }

    /** `test` or `live`: the API key the shop uses. */
    #[Field]
    public function getMode(): string
    {
        return $this->config->mode;
    }

    /** `automatic` (capture at checkout) or `manual` (authorise now, capture from the Payment tab). */
    #[Field]
    public function getCaptureMode(): string
    {
        return $this->config->captureMode;
    }

    /** The merchant confirmed with Mollie that several captures per authorisation are enabled on the account. */
    #[Field]
    public function isMultiCapture(): bool
    {
        return $this->config->multiCapture;
    }

    /** The website profile id (`pfl_...`) Mollie Components needs; empty when none is set. */
    #[Field]
    public function getProfileId(): string
    {
        return $this->config->profileId;
    }

    /** The merchant's own webhook URL; empty when the shop's webhook controller is used. */
    #[Field]
    public function getWebhookUrlOverride(): string
    {
        return $this->config->webhookUrlOverride;
    }

    /** The URL Mollie is told to call (the override, else the shop's webhook controller). Read-only. */
    #[Field]
    public function getEffectiveWebhookUrl(): string
    {
        return $this->config->effectiveWebhookUrl;
    }

    /** `off`, `errors`, `normal` or `debug`. */
    #[Field]
    public function getLogLevel(): string
    {
        return $this->config->logLevel;
    }

    #[Field]
    public function isTestKeySet(): bool
    {
        return $this->config->testKeySet;
    }

    #[Field]
    public function isLiveKeySet(): bool
    {
        return $this->config->liveKeySet;
    }

    /**
     * The classic admin's settings page of this module for the current shop: the settings change there. No secret and
     * no session token in it.
     */
    #[Field]
    public function getClassicSettingsUrl(): string
    {
        return $this->config->classicSettingsUrl;
    }

    #[Field]
    public function getVersion(): string
    {
        return $this->config->version();
    }
}
