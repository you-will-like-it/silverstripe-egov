<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Tests;

use PHPUnit\Framework\TestCase;
use YouWillLikeIT\EGov\Enum\RequestStatus;
use YouWillLikeIT\EGov\Enum\SupplementReason;
use YouWillLikeIT\EGov\Enum\VerificationLight;

final class RequestStatusTest extends TestCase
{
    public function testDecisionsOnlyFromInProcess(): void
    {
        self::assertSame([RequestStatus::Submitted], RequestStatus::Draft->allowedTransitions());
        self::assertSame([RequestStatus::InProcess], RequestStatus::Submitted->allowedTransitions());
        self::assertTrue(RequestStatus::InProcess->canTransitionTo(RequestStatus::Rejected));
        self::assertTrue(RequestStatus::InProcess->canTransitionTo(RequestStatus::Dismissed));
        self::assertFalse(RequestStatus::Submitted->canTransitionTo(RequestStatus::Incomplete));
    }

    public function testTerminalStates(): void
    {
        foreach ([RequestStatus::Completed, RequestStatus::Rejected, RequestStatus::Dismissed] as $s) {
            self::assertTrue($s->isTerminal(), $s->value);
        }
        self::assertFalse(RequestStatus::ReadyForPickup->isTerminal());
    }

    public function testSupplementRound(): void
    {
        self::assertTrue(RequestStatus::Incomplete->canTransitionTo(RequestStatus::Submitted));
        self::assertTrue(RequestStatus::AwaitingPayment->canTransitionTo(RequestStatus::Dismissed));
        self::assertTrue(RequestStatus::Incomplete->suspendsDeadline());
        self::assertFalse(RequestStatus::InProcess->suspendsDeadline());
    }

    public function testDbEnumMatchesCases(): void
    {
        self::assertSame(
            'Draft,Submitted,Incomplete,AwaitingPayment,InProcess,ReadyForPickup,Completed,Rejected,Dismissed',
            RequestStatus::dbEnumValues()
        );
    }

    public function testSupplementReasonsAreClosed(): void
    {
        self::assertSame(['Missing'], array_keys(SupplementReason::optionsFor(false, true)));
        self::assertArrayNotHasKey('Missing', SupplementReason::optionsFor(true, false));
        self::assertArrayNotHasKey('WrongPayment', SupplementReason::optionsFor(true, false));
        self::assertArrayHasKey('WrongPayment', SupplementReason::optionsFor(true, true));
    }

    public function testWorstLight(): void
    {
        self::assertSame(VerificationLight::Red, VerificationLight::worstOf([VerificationLight::Green, VerificationLight::Red, VerificationLight::Yellow]));
        self::assertSame(VerificationLight::Pending, VerificationLight::worstOf([]));
    }
}
