<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use PHPUnit\Framework\TestCase;

/**
 * PAY-5: the admin API offers (and enforces) the reasons and limits of the Payment tab's form. They are declared once
 * in MollieDefinitions; this pins the Twig template to them, so a reason added to the form is a failing test until
 * the API knows it.
 */
final class AdminActionOptionsTemplateParityTest extends TestCase
{
    private string $template;

    protected function setUp(): void
    {
        $this->template = (string) file_get_contents(dirname(__DIR__, 3) . '/views/twig/admin/panel/mollie_panel.html.twig');
    }

    public function testRefundReasons_AreTheOptionsOfTheRefundSelect(): void
    {
        self::assertSame(MollieDefinitions::ADMIN_REFUND_REASONS, $this->optionsOf('refund_reason'));
    }

    public function testVoidReasons_AreTheOptionsOfTheCancelSelect(): void
    {
        self::assertSame(MollieDefinitions::ADMIN_VOID_REASONS, $this->optionsOf('cancel_reason'));
    }

    public function testTheFreeTextInputs_AllStopAtTheSameLength(): void
    {
        foreach (['capture_reason', 'release_reason', 'refund_description'] as $input) {
            self::assertSame(MollieDefinitions::ADMIN_TEXT_MAX_LENGTH, $this->maxLengthOf($input), $input);
        }
    }

    public function testTheRefundHasADescriptionInput(): void
    {
        self::assertStringContainsString('name="refund_description"', $this->template);
    }

    /**
     * @return list<string>
     */
    private function optionsOf(string $select): array
    {
        self::assertSame(1, preg_match('/<select[^>]*name="' . $select . '".*?<\/select>/s', $this->template, $block), $select);
        preg_match_all('/<option value="([a-z_]+)"/', $block[0], $values);

        return $values[1];
    }

    private function maxLengthOf(string $input): int
    {
        self::assertSame(1, preg_match('/<input[^>]*name="' . $input . '"[^>]*maxlength="(\d+)"/s', $this->template, $match), $input);

        return (int) $match[1];
    }
}
