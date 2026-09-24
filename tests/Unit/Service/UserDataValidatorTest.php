<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\PaymentBase\Validation\RuleSet;
use OxidEsales\PaymentBase\Validation\ValidationBaseFactory;
use OxidEsales\PaymentBase\Validation\ValidationRuleLoaderInterface;
use OxidEsales\Payments\Mollie\Service\UserDataValidator;
use OxidEsales\Payments\Mollie\Service\UserFieldReaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises UserDataValidator against the REAL payment-base ValidationBase, loading the
 * module's actual src/Resources/validation-rules.php — not a mocked ValidationBaseInterface —
 * so the rule content itself is under test, not just the wiring.
 */
#[CoversClass(UserDataValidator::class)]
final class UserDataValidatorTest extends TestCase
{
    public function testValidateFieldWithBlockedCharacterFailsReportsFieldCodeAndChar(): void
    {
        $validator = $this->validator();
        $reader = $this->readerWith(['firstName' => 'Jane<script>']);

        $failures = $validator->validateForUser($reader);

        self::assertCount(1, $failures);
        self::assertSame('firstName', $failures[0]->field);
        self::assertSame('blocked_character', $failures[0]->code);
        self::assertSame('<', $failures[0]->offendingChar);
        self::assertSame('oxfname', $failures[0]->oxidColumn);
    }

    public function testValidateUnicodeLettersUmlautsPass(): void
    {
        $validator = $this->validator();
        $reader = $this->readerWith([
            'firstName' => 'Björn',
            'lastName' => 'Müller-Łąka',
            'city' => 'Świętokrzyskie',
        ]);

        $failures = $validator->validateForUser($reader);

        self::assertSame([], $failures);
    }

    public function testValidateEmailAllowsAtAndDot(): void
    {
        $validator = $this->validator();
        $reader = $this->readerWith(['email' => 'jane.doe+test@example.com']);

        $failures = $validator->validateForUser($reader);

        self::assertSame([], $failures);
    }

    public function testValidateEmptyValuesAreSkipped(): void
    {
        $validator = $this->validator();
        $reader = $this->readerWith([]);

        self::assertSame([], $validator->validateForUser($reader));
    }

    public function testValidateFieldMapReportsFailuresWithoutOxidColumn(): void
    {
        $validator = $this->validator();

        $failures = $validator->validateFieldMap(['phone' => '+49 (0)30-12345 <injected>']);

        self::assertCount(1, $failures);
        self::assertSame('phone', $failures[0]->field);
        self::assertNull($failures[0]->oxidColumn);
    }

    // ── MOL-15: parity with the Stripe module's use of the payment-base validation system ──────

    public function testValidatesTheDeliveryAddressWhenOneIsSelected(): void
    {
        $reader = $this->readerWith(
            ['firstName' => 'Jane', 'street' => 'Main Street'],
            ['firstName' => 'Jane', 'street' => 'Elm Street <script>'],
        );

        $failures = $this->validator()->validateForUser($reader);

        self::assertCount(1, $failures);
        self::assertSame('street', $failures[0]->field);
        self::assertSame('delivery', $failures[0]->addressKind);
        self::assertSame('oxstreet', $failures[0]->oxidColumn);
    }

    public function testReportsBillingFailuresWithTheBillingAddressKind(): void
    {
        $failures = $this->validator()->validateForUser($this->readerWith(['city' => 'Berlin;']));

        self::assertCount(1, $failures);
        self::assertSame('billing', $failures[0]->addressKind);
    }

    public function testSkipsTheDeliveryPassWhenNoDeliveryAddressIsSelected(): void
    {
        self::assertSame([], $this->validator()->validateForUser($this->readerWith(['firstName' => 'Jane'])));
    }

    public function testKnowsPostalCodeAndThePhoneTrio(): void
    {
        $reader = $this->readerWith([
            'postalCode' => '10117<',
            'cellPhone' => '+49 170 <1>',
            'personalPhone' => '030 ; 1',
            'fax' => '030 | 2',
        ]);

        $fields = array_map(static fn ($failure) => $failure->field, $this->validator()->validateForUser($reader));

        self::assertSame(['postalCode', 'cellPhone', 'personalPhone', 'fax'], $fields);
    }

    public function testValidateFieldMapCarriesTheGivenAddressKind(): void
    {
        $failures = $this->validator()->validateFieldMap(['refundDescription' => 'chargeback <x>'], 'admin');

        self::assertCount(1, $failures);
        self::assertSame('admin', $failures[0]->addressKind);
        self::assertSame('refundDescription', $failures[0]->field);
    }

    private function validator(): UserDataValidator
    {
        $loader = new class implements ValidationRuleLoaderInterface {
            /** @return array<string, RuleSet> */
            public function loadFor(string $pluginModuleId): array
            {
                $rulesFile = dirname(__DIR__, 3) . '/src/Resources/validation-rules.php';
                /** @var array{fields: array<array{field: string, rules: array{allow?: string, block?: string}}>} $data */
                $data = require $rulesFile;

                $map = [];
                foreach ($data['fields'] as $entry) {
                    $map[$entry['field']] = RuleSet::fromArray($entry['rules']);
                }

                return $map;
            }
        };

        return new UserDataValidator(new ValidationBaseFactory($loader));
    }

    /**
     * @param array<string, string> $values
     */
    /**
     * @param array<string, string> $billing
     * @param array<string, string>|null $delivery a selected delivery address, or null for none
     */
    private function readerWith(array $billing, ?array $delivery = null): UserFieldReaderInterface
    {
        return new class ($billing, $delivery) implements UserFieldReaderInterface {
            public function __construct(private readonly array $billing, private readonly ?array $delivery)
            {
            }

            public function readBillingField(string $logicalName): string
            {
                return $this->billing[$logicalName] ?? '';
            }

            public function hasDeliveryAddress(): bool
            {
                return $this->delivery !== null;
            }

            public function readDeliveryField(string $logicalName): string
            {
                return $this->delivery[$logicalName] ?? '';
            }
        };
    }
}
