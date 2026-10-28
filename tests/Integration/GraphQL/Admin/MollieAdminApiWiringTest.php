<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\GraphQL\Admin;

// CI runs this suite with the shop bootstrap, which does not autoload the module's Tests\ namespace.
require_once __DIR__ . '/../../Support/AdminSchemaProbe.php';

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\GraphQL\Admin\Authorization\Service\RightsCatalogue;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Relation\AdminPaymentContractMollieRelation;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieAdminAuditInterface;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\OxidMollieAdminAudit;
use OxidEsales\Payments\Mollie\GraphQL\Service\NamespaceMapper;
use OxidEsales\Payments\Mollie\Module;
use OxidEsales\Payments\Mollie\Tests\Integration\Support\AdminSchemaProbe;
use PHPUnit\Framework\Attributes\Group;

/**
 * The Mollie admin API in the real shop: wired, in the schema, and no API key value anywhere in what it returns.
 */
#[Group('integration')]
final class MollieAdminApiWiringTest extends IntegrationTestCase
{
    private const CONFIG_FIELDS = 'mode captureMode multiCapture profileId webhookUrlOverride effectiveWebhookUrl logLevel '
        . 'testKeySet liveKeySet version';

    public function setUp(): void
    {
        parent::setUp();
        if (!class_exists(RightsCatalogue::class)) {
            self::markTestSkipped('oxid-admin-graphql is not installed');
        }
    }

    public function testNamespaceMapper_InTheRealShop_MapsTheMollieAdminApi(): void
    {
        $mapper = new NamespaceMapper();

        self::assertArrayHasKey('OxidEsales\\Payments\\Mollie\\GraphQL\\Admin\\Controller', $mapper->getControllerNamespaceMapping());
        self::assertArrayHasKey('OxidEsales\\Payments\\Mollie\\GraphQL\\Admin\\Relation', $mapper->getTypeNamespaceMapping());
    }

    public function testTheConfigRightIsInTheCatalogue(): void
    {
        self::assertTrue($this->service(RightsCatalogue::class)->has('ADMIN_PAYMENT_CONFIG'));
    }

    public function testContainer_WiresTheAuditToTheRealRecorder(): void
    {
        self::assertInstanceOf(OxidMollieAdminAudit::class, $this->service(MollieAdminAuditInterface::class));
    }

    public function testSchema_HasTheConfigAndConnectionCheckOperations(): void
    {
        $schema = AdminSchemaProbe::sdl();

        self::assertMatchesRegularExpression('/adminMollieConfig: AdminMollieConfig!/', $schema);
        self::assertMatchesRegularExpression('/adminMollieConfigModify\(input: AdminMollieConfigInput!\): AdminMollieConfigPayload!/', $schema);
        self::assertMatchesRegularExpression('/adminMollieConnectionCheck: AdminMollieConnectionCheck!/', $schema);
        self::assertMatchesRegularExpression('/type AdminMollieConnectionCheck \{[^}]*status: String![^}]*ok: Boolean![^}]*mode: String!/s', $schema);
    }

    public function testSchema_TheContractTypeIsExtendedWithTheMollieDetails(): void
    {
        $schema = AdminSchemaProbe::sdl();

        self::assertMatchesRegularExpression('/type AdminPaymentContract \{[^}]*mollie: AdminMolliePaymentDetails/s', $schema);
        self::assertMatchesRegularExpression(
            '/type AdminMolliePaymentDetails \{[^}]*paymentId: String[^}]*dashboardUrl: String[^}]*methodLabel: String[^}]*methodDetail: String'
            . '[^}]*mollieStatus: String[^}]*amountReleased: Float![^}]*amountReleasable: Float[^!}][^}]*multiCapture: Boolean![^}]*apiError: String/s',
            $schema,
        );
    }

    public function testSchema_HasTheReleaseMutationAnsweringLikePaymentBasesOperations(): void
    {
        self::assertMatchesRegularExpression(
            '/adminMolliePaymentRelease\(orderId: ID!, reason: String = null, idempotencyKey: String = null\): AdminPaymentOperationPayload!/',
            AdminSchemaProbe::sdl(),
        );
    }

    public function testContainer_HandsOutTheContractExtensionByItsClassName(): void
    {
        self::assertInstanceOf(
            AdminPaymentContractMollieRelation::class,
            $this->service(AdminPaymentContractMollieRelation::class),
        );
    }

    public function testSchema_TheConfigTypeHasNoFieldThatCouldCarryAKey_TheInputOnlyTakesOne(): void
    {
        $schema = AdminSchemaProbe::sdl();

        preg_match('/type AdminMollieConfig \{([^}]*)\}/s', $schema, $type);
        self::assertNotEmpty($type, 'type AdminMollieConfig');
        self::assertMatchesRegularExpression('/testKeySet: Boolean!/', $type[1]);
        self::assertMatchesRegularExpression('/liveKeySet: Boolean!/', $type[1]);
        preg_match_all('/^\s*(\w+)[(:]/m', $type[1], $fields);
        foreach ($fields[1] as $field) {
            self::assertDoesNotMatchRegularExpression('/key/i', (string) preg_replace('/KeySet$/', '', $field), 'field ' . $field);
        }
        self::assertMatchesRegularExpression('/input AdminMollieConfigInput \{[^}]*testKey: String[^!]/s', $schema);
        self::assertMatchesRegularExpression('/input AdminMollieConfigInput \{[^}]*expectedVersion: String!/s', $schema);
    }

    public function testQuery_AdminMollieConfig_ReturnsEveryFieldAndNeverTheStoredKeys(): void
    {
        $settings = $this->service(ModuleSettingServiceInterface::class);
        $storedKeys = array_filter(
            [
                $settings->getString('sMollieTestKey', Module::MODULE_ID)->toString(),
                $settings->getString('sMollieLiveKey', Module::MODULE_ID)->toString(),
            ],
            static fn (string $key): bool => strlen($key) >= 8,
        );
        if ($storedKeys === []) {
            self::markTestSkipped('this shop has no Mollie API key stored; the grep needs one to look for');
        }

        $response = AdminSchemaProbe::execute('{ adminMollieConfig { ' . self::CONFIG_FIELDS . ' } }');

        self::assertArrayNotHasKey('errors', $response);
        self::assertArrayHasKey('testKeySet', $response['data']['adminMollieConfig']);
        $body = (string) json_encode($response);
        foreach ($storedKeys as $key) {
            self::assertFalse(str_contains($body, $key), 'a stored API key appears in the response');
        }
    }

    public function testQuery_TheSchemaOffersNoWayToAskForAKeyByName(): void
    {
        $response = AdminSchemaProbe::execute('{ adminMollieConfig { testKey liveKey } }');

        self::assertArrayHasKey('errors', $response, 'there is no field to select a key with');
        self::assertArrayNotHasKey('data', $response);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function service(string $id): object
    {
        return ContainerFactory::getInstance()->getContainer()->get($id);
    }
}
