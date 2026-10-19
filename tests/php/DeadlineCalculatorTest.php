<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Tests;

use DateTimeImmutable;
use DateTimeZone;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use YouWillLikeIT\EGov\Enum\DeadlineCategory;
use YouWillLikeIT\EGov\Service\DeadlineCalculator;

final class DeadlineCalculatorTest extends SapphireTest
{
    protected $usesDatabase = false;

    private function date(string $ymd): DateTimeImmutable
    {
        return new DateTimeImmutable($ymd . ' 10:00:00', new DateTimeZone('Europe/Sarajevo'));
    }

    public function testDefaultsFollowZup(): void
    {
        $calc = DeadlineCalculator::create();
        self::assertSame(30, $calc->decisionDaysFor('99-99', DeadlineCategory::Simple));
        self::assertSame(60, $calc->decisionDaysFor('99-99', DeadlineCategory::Examination));
    }

    public function testProcedureOverride(): void
    {
        Config::modify()->set(DeadlineCalculator::class, 'procedure_overrides', ['05-16' => ['decision_days' => 10, 'supplement_days' => 5]]);
        $calc = DeadlineCalculator::create();
        self::assertSame(10, $calc->decisionDaysFor('05-16', DeadlineCategory::Simple));
        self::assertSame(5, $calc->supplementDaysFor('05-16'));
    }

    public function testDeadlineMovesToNextWorkingDay(): void
    {
        $calc = DeadlineCalculator::create();
        // Fri 2026-10-02 + 8 days = Sat 2026-10-10 → Mon 2026-10-12
        self::assertSame('2026-10-12', $calc->dueDate($this->date('2026-10-02'), 8));
        // 2026-11-17 + 8 = 2026-11-25 (Dan državnosti, Wed) → 2026-11-26
        self::assertSame('2026-11-26', $calc->dueDate($this->date('2026-11-17'), 8));
    }

    public function testDaysUntil(): void
    {
        DBDatetime::set_mock_now('2026-10-17 12:00:00');
        $calc = DeadlineCalculator::create();
        self::assertSame(3, $calc->daysUntil('2026-10-20'));
        self::assertSame(-2, $calc->daysUntil('2026-10-15'));
        DBDatetime::clear_mock_now();
    }
}
