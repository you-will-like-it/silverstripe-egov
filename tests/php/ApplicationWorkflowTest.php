<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Tests;

use LogicException;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use YouWillLikeIT\EGov\Enum\DismissalReason;
use YouWillLikeIT\EGov\Enum\RequestStatus;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\AuditLogEntry;
use YouWillLikeIT\EGov\Model\FeeItem;
use YouWillLikeIT\EGov\Model\RequiredDocument;
use YouWillLikeIT\EGov\Model\SupplementItem;
use YouWillLikeIT\EGov\Task\DeadlineMonitorTask;
use YouWillLikeIT\EGov\Workflow\ApplicationWorkflow;
use YouWillLikeIT\EGov\Workflow\TransitionContext;
use YouWillLikeIT\EGov\Workflow\TransitionException;

final class ApplicationWorkflowTest extends SapphireTest
{
    protected static $fixture_file = 'ApplicationWorkflowTest.yml';

    protected function setUp(): void
    {
        parent::setUp();
        DBDatetime::set_mock_now('2026-10-05 09:00:00'); // Monday
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    private function wf(): ApplicationWorkflow
    {
        return ApplicationWorkflow::singleton();
    }

    private function member(string $id): Member
    {
        return $this->objFromFixture(Member::class, $id);
    }

    private function draft(): ApplicationRequest
    {
        return $this->objFromFixture(ApplicationRequest::class, 'draft');
    }

    private function submitted(): ApplicationRequest
    {
        $r = $this->draft();
        $this->wf()->requestConfirmation($r);
        $this->wf()->confirmSubmission($r);
        return $r;
    }

    private function inProcess(string $by = 'mujo', string $protocol = '08-23-1/26'): ApplicationRequest
    {
        $r = $this->submitted();
        $r->ProtocolNumber = $protocol;
        $r->write();
        $this->wf()->startProcessing($r, $this->member($by));
        return $r;
    }

    public function testIdentifiersAreGenerated(): void
    {
        $r = $this->draft();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $r->PublicHash);
        self::assertMatchesRegularExpression('/^[A-Z]{1,5}-[2-9A-HJKMNP-Z]{8}$/', (string) $r->TrackingCode);
        self::assertSame('08-01', $r->RegistryCode);
    }

    public function testStatusCannotBeWrittenDirectly(): void
    {
        $r = $this->draft();
        $r->Status = RequestStatus::Completed->value;
        $this->expectException(LogicException::class);
        $r->write();
    }

    public function testDoubleOptInStartsZupDeadline(): void
    {
        $r = $this->submitted();
        self::assertSame(RequestStatus::Submitted, $r->getStatusEnum());
        self::assertSame('2026-12-04', $r->DecisionDueDate); // 60 days (Examination)
        $this->assertEmailSent('gradjanin@example.ba');
    }

    public function testStartProcessingLocksForOthers(): void
    {
        $r = $this->inProcess('mujo');
        self::assertSame((int) $this->member('mujo')->ID, (int) $r->AssignedToID);
        self::assertTrue($r->canEdit($this->member('mujo')));
        self::assertFalse($r->canEdit($this->member('haso')));
        self::assertFalse($r->canStartProcessing($this->member('haso')));
        self::assertStringContainsString('Mujo', $r->getStatusLabel());

        $this->expectException(TransitionException::class);
        $this->wf()->transition($r, RequestStatus::Completed, TransitionContext::staff($this->member('haso')));
    }

    public function testSupervisorUnlocksButCannotDecide(): void
    {
        $r = $this->inProcess('mujo');
        $boss = $this->member('boss');
        self::assertTrue($r->canView($boss));
        self::assertFalse($r->canDecide($boss));

        $this->wf()->unlock($r, $boss);
        self::assertSame(0, (int) $r->AssignedToID);
        self::assertTrue(AuditLogEntry::get()->filter(['Action' => AuditLogEntry::ACTION_UNLOCK, 'RequestID' => $r->ID])->exists());

        $this->expectException(TransitionException::class);
        $this->wf()->transition($r, RequestStatus::Completed, TransitionContext::staff($boss));
    }

    public function testSupplementOnlyFromCatalogueAndUnlocks(): void
    {
        $r = $this->inProcess('mujo');
        $plan = $this->objFromFixture(RequiredDocument::class, 'plan');

        $this->wf()->transition($r, RequestStatus::Incomplete, new TransitionContext(
            member: $this->member('mujo'),
            supplementItems: [$plan->ID => 'Missing'],
            supplementDays: 8,
        ));
        self::assertSame(RequestStatus::Incomplete, $r->getStatusEnum());
        self::assertSame(0, (int) $r->AssignedToID, 'unlocked during supplement round');
        self::assertNull($r->DecisionDueDate);
        self::assertSame('2026-10-13', $r->SupplementDueDate);
        self::assertStringContainsString('Kopija katastarskog plana', (string) $r->SupplementRequestText);
        self::assertSame(1, SupplementItem::get()->filter('RequestID', $r->ID)->count());

        DBDatetime::set_mock_now('2026-10-12 09:00:00');
        $this->wf()->submitSupplement($r);
        self::assertSame('2026-12-11', $r->DecisionDueDate); // restarted
    }

    public function testSupplementRejectsForeignDocumentAndFreeReasons(): void
    {
        $r = $this->inProcess('mujo');
        $this->expectException(TransitionException::class);
        $this->wf()->transition($r, RequestStatus::Incomplete, new TransitionContext(
            member: $this->member('mujo'),
            supplementItems: [999999 => 'Missing'],
        ));
    }

    public function testWrongPaymentOnlyForPaymentProof(): void
    {
        $r = $this->inProcess('mujo');
        $plan = $this->objFromFixture(RequiredDocument::class, 'plan');
        $this->expectException(TransitionException::class);
        // plan was not attached → only "Missing" is allowed
        $this->wf()->transition($r, RequestStatus::Incomplete, new TransitionContext(
            member: $this->member('mujo'),
            supplementItems: [$plan->ID => 'WrongPayment'],
        ));
    }

    public function testAwaitingPaymentRequiresAssessedAmountForRangeFee(): void
    {
        $r = $this->inProcess('mujo');
        $slip = $this->objFromFixture(RequiredDocument::class, 'slip');
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessageMatches('/Utvrdite iznos takse/');
        $this->wf()->transition($r, RequestStatus::AwaitingPayment, new TransitionContext(
            member: $this->member('mujo'),
            supplementItems: [$slip->ID => 'Missing'],
        ));
    }

    public function testAssessedAmountMustBeInsideCatalogueRange(): void
    {
        $r = $this->inProcess('mujo');
        $slip = $this->objFromFixture(RequiredDocument::class, 'slip');
        $fee = $this->objFromFixture(FeeItem::class, 'rangeFee');
        $this->expectException(TransitionException::class);
        $this->wf()->transition($r, RequestStatus::AwaitingPayment, new TransitionContext(
            member: $this->member('mujo'),
            supplementItems: [$slip->ID => 'Missing'],
            assessedFees: [$fee->ID => 500.0],
        ));
    }

    public function testAssessedAmountIsUsedInNoticeAndPayments(): void
    {
        $r = $this->inProcess('mujo');
        $slip = $this->objFromFixture(RequiredDocument::class, 'slip');
        $fee = $this->objFromFixture(FeeItem::class, 'rangeFee');

        $this->wf()->transition($r, RequestStatus::AwaitingPayment, new TransitionContext(
            member: $this->member('mujo'),
            supplementItems: [$slip->ID => 'Missing'],
            assessedFees: [$fee->ID => 276.0],
        ));

        self::assertSame(RequestStatus::AwaitingPayment, $r->getStatusEnum());
        self::assertSame([(int) $fee->ID => 276.0], $r->getAssessedFees());
        self::assertSame([276.0, 276.0], $r->expectedRangeFor($fee));
        self::assertStringContainsString('276,00 KM', (string) $r->SupplementRequestText);
        self::assertStringNotContainsString('300,00 KM', (string) $r->SupplementRequestText);
        self::assertSame(276.0, $r->getExpectedPayments()->first()->AmountMin);
    }

    public function testDismissRequiresReason(): void
    {
        $r = $this->inProcess('mujo');
        $this->expectException(TransitionException::class);
        $this->wf()->transition($r, RequestStatus::Dismissed, TransitionContext::staff($this->member('mujo')));
    }

    public function testAutoDismissAfterSupplementDeadline(): void
    {
        $r = $this->inProcess('mujo');
        $plan = $this->objFromFixture(RequiredDocument::class, 'plan');
        $this->wf()->transition($r, RequestStatus::Incomplete, new TransitionContext(
            member: $this->member('mujo'),
            supplementItems: [$plan->ID => 'Missing'],
            supplementDays: 8,
        ));

        Config::modify()->set(DeadlineMonitorTask::class, 'auto_dismiss_expired_supplements', true);
        Config::modify()->set(DeadlineMonitorTask::class, 'auto_dismiss_grace_days', 0);
        DBDatetime::set_mock_now('2026-10-20 07:00:00');

        $this->wf()->dismissExpiredSupplement($r);
        self::assertSame(RequestStatus::Dismissed, $r->getStatusEnum());
        self::assertSame(DismissalReason::SupplementNotProvided, $r->getDismissalReasonEnum());
        self::assertTrue(ApplicationRequest::get()->filter('ID', $r->ID)->exists(), 'protocolled cases are never deleted');
    }

    public function testCounterIntake(): void
    {
        $mujo = $this->member('mujo');
        $r = ApplicationRequest::create([
            'ProcedureID' => $this->objFromFixture(\YouWillLikeIT\EGov\Model\ProcedureDefinition::class, 'urbanConsent')->ID,
            'IntakeChannel' => ApplicationRequest::CHANNEL_COUNTER,
            'ApplicantFullName' => 'Šalter Stranka',
            'ApplicantParentName' => 'Otac',
            'ApplicantIdentificationNumber' => '1503985175123',
            'ProtocolNumber' => '08-23-77/26',
        ]);
        $r->write(); // email optional at the counter
        $this->wf()->registerCounterIntake($r, $mujo);

        self::assertSame(RequestStatus::Submitted, $r->getStatusEnum());
        self::assertSame((int) $mujo->ID, (int) $r->IntakeByID);
        self::assertTrue($r->procedureFieldsEditable($mujo));
        self::assertFalse($r->procedureFieldsEditable($this->member('haso')));
    }

    public function testCounterIntakeRequiresProtocol(): void
    {
        $r = ApplicationRequest::create([
            'ProcedureID' => $this->objFromFixture(\YouWillLikeIT\EGov\Model\ProcedureDefinition::class, 'urbanConsent')->ID,
            'IntakeChannel' => ApplicationRequest::CHANNEL_COUNTER,
            'ApplicantFullName' => 'Bez Protokola',
            'ApplicantIdentificationNumber' => '1503985175123',
        ]);
        self::assertFalse($r->validate()->isValid());
    }
}
