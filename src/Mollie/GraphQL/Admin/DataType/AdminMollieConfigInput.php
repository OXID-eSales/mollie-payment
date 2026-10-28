<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\DataType;

use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigChange;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Input;

/**
 * A field that is not given stays as it is. The API keys are write-only: an empty or missing one is left unchanged,
 * and a new one must start with `test_` / `live_`. `webhookUrlOverride` and `profileId` may be set to the empty
 * string (removes the override / the profile id).
 */
#[Input]
final class AdminMollieConfigInput
{
    /** The `version` of the configuration the admin edited. */
    #[Field]
    public string $expectedVersion;

    #[Field]
    public ?string $mode = null;

    #[Field]
    public ?string $captureMode = null;

    #[Field]
    public ?bool $multiCapture = null;

    #[Field]
    public ?string $profileId = null;

    #[Field]
    public ?string $webhookUrlOverride = null;

    #[Field]
    public ?string $logLevel = null;

    #[Field]
    public ?string $testKey = null;

    #[Field]
    public ?string $liveKey = null;

    public function toChange(): MollieConfigChange
    {
        return new MollieConfigChange(
            expectedVersion: $this->expectedVersion,
            mode: $this->mode,
            captureMode: $this->captureMode,
            multiCapture: $this->multiCapture,
            profileId: $this->profileId,
            webhookUrlOverride: $this->webhookUrlOverride,
            logLevel: $this->logLevel,
            testKey: $this->testKey,
            liveKey: $this->liveKey,
        );
    }
}
