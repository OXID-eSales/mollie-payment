<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionBoundsInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionRefusals;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Mollie\Admin\MollieReleaseAction;
use OxidEsales\Payments\Mollie\Admin\ReleaseActionInterface;
use OxidEsales\Payments\Mollie\Admin\ReleaseRule;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieReleaseAuthorizationRequestEvent;
use OxidEsales\Payments\Mollie\Service\FieldValidationFailure;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Mollie\Service\UserDataValidatorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * MOL-32 S6 / PAY-5 - the Mollie-only release of what is left of an authorization after partial captures. The one
 * place that decides and runs it, for the classic Payment tab and for the admin API (payment-base's money runner does
 * not know this action).
 */
final class MollieReleaseActionTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contracts;
    private AdminActionBoundsInterface&MockObject $bounds;
    private UserDataValidatorInterface&MockObject $validator;
    private AdminValidationFeedbackInterface&MockObject $feedback;
    private MessageFormatterInterface&MockObject $textFormatter;
    private EventDispatcherInterface&MockObject $events;

    protected function setUp(): void
    {
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->bounds = $this->createMock(AdminActionBoundsInterface::class);
        $this->validator = $this->createMock(UserDataValidatorInterface::class);
        $this->feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $this->textFormatter = $this->createMock(MessageFormatterInterface::class);
        $this->events = $this->createMock(EventDispatcherInterface::class);
    }

    public function testItIsTheReleaseAction(): void
    {
        self::assertInstanceOf(ReleaseActionInterface::class, $this->action());
    }

    public function testItDispatchesTheReleaseEventWithTheContractAndTheReason(): void
    {
        $contract = $this->releasableContract();
        $this->validator->method('validateFieldMap')->willReturn([]);
        $this->events->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (EventInterface $event) use ($contract): EventInterface {
                self::assertInstanceOf(MollieReleaseAuthorizationRequestEvent::class, $event);
                self::assertSame($contract, $event->contract);
                self::assertSame('done capturing', $event->reason);
                $event->setResult(true);

                return $event;
            }
        );

        $this->action()->run('order-1', 'done capturing');
    }

    public function testWhenNothingMayBeReleasedItRefusesWithoutCallingMollie(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getCapturedAmount')->willReturn(null); // nothing captured: release would cancel the hold
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('isAuthorizedHold')->willReturn(true);
        $this->bounds->method('captureBound')->willReturn(100.0);
        $this->events->expects(self::never())->method('dispatch');

        try {
            $this->action()->run('order-1', null);
            self::fail('a release that is not possible must be refused');
        } catch (AdminActionRefusedException $refusal) {
            self::assertSame('not_releasable', $refusal->refusalCode());
            self::assertSame('MOLLIE_ADMIN_RELEASE_NOT_POSSIBLE', $refusal->getMessage());
            self::assertFalse($refusal->feedbackRecorded(), 'nothing was recorded for the classic tab, so it shows the sentence');
        }
    }

    public function testAnOrderWithoutAContractIsNotReleasable(): void
    {
        $this->contracts->method('findByOrderId')->willReturn(null);
        $this->events->expects(self::never())->method('dispatch');

        $this->expectException(AdminActionRefusedException::class);

        $this->action()->run('order-1', null);
    }

    public function testWhenMollieFailsItRefusesWithTheRecordedSentence(): void
    {
        $this->releasableContract();
        $this->validator->method('validateFieldMap')->willReturn([]);
        $this->events->method('dispatch')->willReturnCallback(static function (EventInterface $event): EventInterface {
            $event->setResult(false, 'release_failed', 'no');

            return $event;
        });

        try {
            $this->action()->run('order-1', null);
            self::fail('a failed release must be refused');
        } catch (AdminActionRefusedException $refusal) {
            self::assertSame('release_failed', $refusal->refusalCode());
            self::assertSame('MOLLIE_ADMIN_RELEASE_FAILED', $refusal->getMessage());
            self::assertTrue($refusal->feedbackRecorded(), 'the release handler recorded it for the classic tab already');
        }
    }

    public function testAnInvalidReasonIsRefusedWithoutCallingMollie(): void
    {
        $this->releasableContract();
        $this->validator->method('validateFieldMap')
            ->willReturn([new FieldValidationFailure('captureReason', 'admin', 'blocked_character', ';', null)]);
        $this->textFormatter->method('format')->willReturn('Not valid');
        $this->feedback->expects(self::once())->method('rejectWithMessage')->with('order-1', 'Not valid');
        $this->events->expects(self::never())->method('dispatch');

        try {
            $this->action()->run('order-1', 'a;b');
            self::fail('an invalid reason must be refused');
        } catch (AdminActionRefusedException $refusal) {
            self::assertSame('blocked_character', $refusal->refusalCode());
            self::assertSame('Not valid', $refusal->getMessage());
            self::assertTrue($refusal->feedbackRecorded());
        }
    }

    private function action(): MollieReleaseAction
    {
        $translator = $this->createStub(LanguageTranslatorInterface::class);
        $translator->method('translateString')->willReturnArgument(0);

        return new MollieReleaseAction(
            $this->contracts,
            new ReleaseRule($this->bounds),
            $this->validator,
            new AdminActionRefusals($this->feedback, $this->textFormatter, $translator),
            $this->events,
        );
    }

    private function releasableContract(): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getCapturedAmount')->willReturn(40.0);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('isAuthorizedHold')->willReturn(true);
        $this->bounds->method('captureBound')->willReturn(60.0);

        return $contract;
    }
}
