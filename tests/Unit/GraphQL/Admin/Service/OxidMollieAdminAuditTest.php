<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\OxidMollieAdminAudit;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieAdminRefusal;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigResult;
use OxidEsales\GraphQL\Admin\Audit\DataType\AuditTrail;
use OxidEsales\GraphQL\Admin\Audit\Service\AuditRecorder;
use PHPUnit\Framework\TestCase;

final class OxidMollieAdminAuditTest extends TestCase
{
    protected function setUp(): void
    {
        // The real recorder is final: with the admin API installed the audit row is proven by the integration and
        // e2e suites; this unit test runs against the stand-in of the standalone suite.
        if ((new \ReflectionClass(AuditRecorder::class))->isFinal()) {
            self::markTestSkipped('the real AuditRecorder is final; covered by the integration suite');
        }
    }

    public function testAudit_RecordsOneTrailOnTheMollieProviderWithFieldNamesOnly(): void
    {
        $trails = [];
        $recorder = new class ($trails) extends AuditRecorder {
            /** @param list<AuditTrail> $trails */
            public function __construct(public array &$trails)
            {
            }

            public function auditedAdmin(string $operation, \Closure $action): mixed
            {
                $trail = new AuditTrail($operation);
                $result = $action($trail);
                $this->trails[] = $trail;

                return $result;
            }
        };

        $result = (new OxidMollieAdminAudit($recorder))->audited(
            'adminMollieConfigModify',
            ['captureMode', 'testKey'],
            static fn (): MollieConfigResult => MollieConfigResult::refused(MollieAdminRefusal::invalid('captureMode', 'not_allowed', 'not allowed')),
        );

        self::assertSame('not_allowed', $result->refusal()?->code);
        self::assertCount(1, $trails);
        self::assertSame('adminMollieConfigModify', $trails[0]->operation);
        self::assertSame('paymentProvider', $trails[0]->entityType);
        self::assertSame('mollie', $trails[0]->entityId);
        self::assertSame(['captureMode', 'testKey'], $trails[0]->fields);
        self::assertSame([], $trails[0]->values);
    }

    public function testAudit_WithoutTheAdminApi_FailsClosed(): void
    {
        $this->expectException(\LogicException::class);

        (new OxidMollieAdminAudit(null))->audited('adminMollieConfigModify', [], static fn (): MollieConfigResult => throw new \RuntimeException('must not run'));
    }
}
