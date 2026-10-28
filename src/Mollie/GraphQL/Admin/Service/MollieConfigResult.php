<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

use LogicException;

final readonly class MollieConfigResult
{
    private function __construct(private ?MollieConfigSnapshot $config, private ?MollieAdminRefusal $refusal)
    {
    }

    public static function done(MollieConfigSnapshot $config): self
    {
        return new self($config, null);
    }

    public static function refused(MollieAdminRefusal $refusal): self
    {
        return new self(null, $refusal);
    }

    public function refusal(): ?MollieAdminRefusal
    {
        return $this->refusal;
    }

    public function config(): MollieConfigSnapshot
    {
        return $this->config ?? throw new LogicException('A refused change has no configuration');
    }
}
