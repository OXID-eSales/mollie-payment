<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

use Closure;
use LogicException;
use OxidEsales\GraphQL\Admin\Audit\DataType\AuditTrail;
use OxidEsales\GraphQL\Admin\Audit\Service\AuditRecorder;
use OxidEsales\GraphQL\Admin\Shared\DataType\Payload\PayloadWithErrors;
use OxidEsales\Payments\Mollie\GraphQL\Admin\DataType\AuditedMollieOutcome;

/**
 * The admin module's audit recorder (G05) for the Mollie settings: one row per call, entity `paymentProvider` /
 * `mollie`, the names of the fields the call carried and no value at all (the audit allow-list has none of these).
 * The module is optional: wired with `@?`, and without it the operation fails closed.
 */
final class OxidMollieAdminAudit implements MollieAdminAuditInterface
{
    private const ENTITY = 'paymentProvider';
    private const PROVIDER = 'mollie';

    public function __construct(private readonly ?AuditRecorder $recorder)
    {
    }

    public function audited(string $operationName, array $changedFields, Closure $action): MollieConfigResult
    {
        if ($this->recorder === null) {
            throw new LogicException('The admin API is not installed: there is no audit log to write');
        }
        $result = null;
        $this->recorder->auditedAdmin(
            $operationName,
            static function (AuditTrail $trail) use ($changedFields, $action, &$result): PayloadWithErrors {
                $trail->on(self::ENTITY, self::PROVIDER)->field(...$changedFields);
                $result = $action();

                return new AuditedMollieOutcome($result);
            },
        );

        return $result ?? throw new LogicException('The audited operation did not run');
    }
}
