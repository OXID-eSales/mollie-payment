<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\PaymentBase\Validation\RuleSet;
use OxidEsales\PaymentBase\Validation\ValidationBase;
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

        return new UserDataValidator(new ValidationBase('oe_payments_mollie', $loader));
    }

    /**
     * @param array<string, string> $values
     */
    private function readerWith(array $values): UserFieldReaderInterface
    {
        return new class ($values) implements UserFieldReaderInterface {
            /** @param array<string, string> $values */
            public function __construct(private readonly array $values)
            {
            }

            public function readBillingField(string $logicalName): string
            {
                return $this->values[$logicalName] ?? '';
            }
        };
    }
}
