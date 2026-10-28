<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\DataType;

use OxidEsales\GraphQL\Admin\Shared\DataType\Error\AdminError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Payload\PayloadWithErrors;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigResult;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

#[Type(name: 'AdminMollieConfigPayload')]
final class AdminMollieConfigPayload implements PayloadWithErrors
{
    /**
     * @param list<AdminError> $errors
     */
    private function __construct(private readonly ?AdminMollieConfig $config, private readonly array $errors)
    {
    }

    public static function of(MollieConfigResult $result): self
    {
        $refusal = $result->refusal();
        if ($refusal !== null) {
            return new self(null, [MollieAdminErrors::from($refusal)]);
        }

        return new self(new AdminMollieConfig($result->config()), []);
    }

    #[Field]
    public function config(): ?AdminMollieConfig
    {
        return $this->config;
    }

    /**
     * @return AdminError[]
     */
    #[Field]
    public function errors(): array
    {
        return $this->errors;
    }
}
