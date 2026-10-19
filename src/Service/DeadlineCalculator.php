<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Service;

use DateTimeImmutable;
use DateTimeZone;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\FieldType\DBDatetime;
use YouWillLikeIT\EGov\Enum\DeadlineCategory;
use YouWillLikeIT\EGov\Model\ApplicationRequest;

/**
 * Statutory deadlines (Zakon o upravnom postupku FBiH) with per-procedure YAML overrides.
 *
 * Counting rule: the day of the triggering event is not counted; if the last day
 * falls on a day the authority does not work (weekend / holiday), the deadline
 * expires at the end of the next working day.
 */
class DeadlineCalculator
{
    use Injectable;
    use Configurable;

    /**
     * Days for issuing the decision, per category (calendar days).
     * Simple / Examination: čl. 216. ZUP FBiH (30 / 60 dana).
     * Certificate: uvjerenja o činjenicama iz službene evidencije (5 dana).
     *
     * @config
     * @var array<string, int>
     */
    private static array $decision_days = [
        'Simple' => 30,
        'Examination' => 60,
        'Certificate' => 5,
    ];

    /**
     * Default deadline for the citizen to supplement an incomplete request.
     *
     * @config
     */
    private static int $supplement_days = 8;

    /**
     * Days before the due date at which the CMS shows a warning.
     *
     * @config
     */
    private static int $warning_days = 5;

    /**
     * Per-procedure overrides, keyed by procedure code:
     *   '05-16': { decision_days: 10, supplement_days: 5 }
     *
     * @config
     * @var array<string, array<string, int>>
     */
    private static array $procedure_overrides = [];

    /**
     * ISO-8601 weekday numbers (1 = Monday ... 7 = Sunday) on which the authority does not work.
     *
     * @config
     * @var list<int>
     */
    private static array $non_working_weekdays = [6, 7];

    /**
     * Recurring public holidays (MM-DD).
     * FBiH: Nova godina, Dan nezavisnosti, Praznik rada, Dan državnosti.
     *
     * @config
     * @var list<string>
     */
    private static array $holidays_fixed = ['01-01', '01-02', '03-01', '05-01', '05-02', '11-25'];

    /**
     * One-off non-working dates (YYYY-MM-DD), e.g. movable holidays decided by the municipality.
     *
     * @config
     * @var list<string>
     */
    private static array $holidays_dates = [];

    /**
     * @config
     */
    private static string $timezone = 'Europe/Sarajevo';

    public function decisionDaysFor(string $procedureCode, DeadlineCategory $category): int
    {
        $override = $this->override($procedureCode, 'decision_days');
        if ($override !== null) {
            return $override;
        }
        $days = (array) static::config()->get('decision_days');
        return max(1, (int) ($days[$category->value] ?? 30));
    }

    public function supplementDaysFor(string $procedureCode): int
    {
        return $this->override($procedureCode, 'supplement_days')
            ?? max(1, (int) static::config()->get('supplement_days'));
    }

    public function decisionDueDateFor(ApplicationRequest $request, ?DateTimeImmutable $start = null): string
    {
        $days = $this->decisionDaysFor(
            (string) $request->RegistryCode,
            $request->Procedure()->getDeadlineCategoryEnum()
        );
        return $this->dueDate($start ?? $this->now(), $days);
    }

    public function supplementDueDateFor(ApplicationRequest $request, ?int $days = null, ?DateTimeImmutable $start = null): string
    {
        $days ??= $this->supplementDaysFor((string) $request->RegistryCode);
        return $this->dueDate($start ?? $this->now(), max(1, $days));
    }

    /**
     * @return string Y-m-d
     */
    public function dueDate(DateTimeImmutable $start, int $days): string
    {
        $due = $start->setTime(0, 0)->modify(sprintf('+%d days', $days));
        $guard = 0;
        while (!$this->isWorkingDay($due) && $guard++ < 31) {
            $due = $due->modify('+1 day');
        }
        return $due->format('Y-m-d');
    }

    public function isWorkingDay(DateTimeImmutable $date): bool
    {
        $weekday = (int) $date->format('N');
        if (in_array($weekday, array_map('intval', (array) static::config()->get('non_working_weekdays')), true)) {
            return false;
        }
        if (in_array($date->format('m-d'), (array) static::config()->get('holidays_fixed'), true)) {
            return false;
        }
        return !in_array($date->format('Y-m-d'), (array) static::config()->get('holidays_dates'), true);
    }

    /**
     * Calendar days from today until $date (negative = overdue).
     */
    public function daysUntil(string $date): int
    {
        $target = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10), $this->tz());
        if ($target === false) {
            return 0;
        }
        $today = $this->now()->setTime(0, 0);
        return (int) $today->diff($target)->format('%r%a');
    }

    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . DBDatetime::now()->getTimestamp()))->setTimezone($this->tz());
    }

    private function tz(): DateTimeZone
    {
        return new DateTimeZone((string) static::config()->get('timezone') ?: 'Europe/Sarajevo');
    }

    private function override(string $code, string $key): ?int
    {
        $overrides = (array) static::config()->get('procedure_overrides');
        $value = $overrides[$code][$key] ?? null;
        return is_numeric($value) ? max(1, (int) $value) : null;
    }
}
