<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

/**
 * Where the classic admin shows this module's settings. The new admin app cannot know that address (its GraphQL
 * endpoint is proxied same-origin), so the shop supplies it.
 */
interface ClassicSettingsUrlInterface
{
    public function url(): string;
}
