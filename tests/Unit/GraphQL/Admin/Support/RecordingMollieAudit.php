<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support;

use Closure;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieAdminAuditInterface;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigResult;
use Throwable;

final class RecordingMollieAudit implements MollieAdminAuditInterface
{
    /** @var list<array{operation: string, fields: list<string>, outcome: string}> */
    public array $rows = [];

    public function audited(string $operationName, array $changedFields, Closure $action): MollieConfigResult
    {
        try {
            $result = $action();
        } catch (Throwable $thrown) {
            $this->rows[] = ['operation' => $operationName, 'fields' => $changedFields, 'outcome' => $thrown::class];
            throw $thrown;
        }
        $this->rows[] = ['operation' => $operationName, 'fields' => $changedFields, 'outcome' => $result->refusal()?->code ?? 'ok'];

        return $result;
    }
}
