<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Report;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use YouWillLikeIT\EGov\Enum\RequestStatus;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\ApplicationStatusChange;
use YouWillLikeIT\EGov\Model\MunicipalService;
use YouWillLikeIT\EGov\Model\ProcedureDefinition;
use YouWillLikeIT\EGov\Model\SupplementItem;

/**
 * Yearly statistics per branch (months / services / procedures / referents).
 * Volumes of a small municipality (a few thousand requests per year) are
 * aggregated in PHP – portable across MySQL/MariaDB/PostgreSQL.
 */
class ReportBuilder
{
    use Injectable;

    public const BRANCH_MONTHS = 'months';
    public const BRANCH_SERVICES = 'services';
    public const BRANCH_PROCEDURES = 'procedures';
    public const BRANCH_REFERENTS = 'referents';
    public const BRANCH_ALL = 'all';

    private const MONTHS = [1 => 'Januar', 'Februar', 'Mart', 'April', 'Maj', 'Juni', 'Juli', 'August', 'Septembar', 'Oktobar', 'Novembar', 'Decembar'];

    /**
     * @return array<string, string>
     */
    public static function branchOptions(): array
    {
        return [
            self::BRANCH_ALL => 'Kompletan izvještaj (sve grane)',
            self::BRANCH_MONTHS => 'Po mjesecima',
            self::BRANCH_SERVICES => 'Po službama',
            self::BRANCH_PROCEDURES => 'Po procedurama',
            self::BRANCH_REFERENTS => 'Po referentima',
        ];
    }

    /**
     * @param list<int> $serviceIds scope (already permission-filtered)
     * @return ArrayList<ArrayData> sections: Title, Rows, Totals, ShowReferentColumns
     */
    public function build(int $year, string $branch, array $serviceIds): ArrayList
    {
        $rows = $this->loadRows($year, $serviceIds);
        $branches = $branch === self::BRANCH_ALL
            ? [self::BRANCH_MONTHS, self::BRANCH_SERVICES, self::BRANCH_PROCEDURES, self::BRANCH_REFERENTS]
            : [$branch];

        $sections = ArrayList::create();
        foreach ($branches as $b) {
            $sections->push(match ($b) {
                self::BRANCH_MONTHS => $this->section('Po mjesecima', $this->groupBy($rows, static fn (array $r): string => (string) $r['month'], array_map('strval', array_keys(self::MONTHS)), static fn (string $k): string => self::MONTHS[(int) $k])),
                self::BRANCH_SERVICES => $this->section('Po službama', $this->groupBy($rows, static fn (array $r): string => (string) $r['service'], null, fn (string $k): string => $this->serviceTitle((int) $k))),
                self::BRANCH_PROCEDURES => $this->section('Po procedurama', $this->groupBy($rows, static fn (array $r): string => (string) $r['procedure'], null, fn (string $k): string => $this->procedureTitle((int) $k))),
                default => $this->referentSection($year, $rows, $serviceIds),
            });
        }
        return $sections;
    }

    /**
     * @param list<int> $serviceIds
     * @return list<array<string, mixed>>
     */
    private function loadRows(int $year, array $serviceIds): array
    {
        $list = ApplicationRequest::get()
            ->exclude('Status', RequestStatus::Draft->value)
            ->filter([
                'ServiceID' => $serviceIds ?: [0],
                'SubmittedAt:GreaterThanOrEqual' => sprintf('%d-01-01 00:00:00', $year),
                'SubmittedAt:LessThan' => sprintf('%d-01-01 00:00:00', $year + 1),
            ]);

        $today = date('Y-m-d', DBDatetime::now()->getTimestamp());
        $out = [];
        foreach ($list as $r) {
            /** @var ApplicationRequest $r */
            $status = $r->getStatusEnum();
            $submitted = strtotime((string) $r->SubmittedAt) ?: 0;
            $completed = $r->CompletedAt ? (strtotime((string) $r->CompletedAt) ?: null) : null;
            $due = $r->DecisionDueDate ? (string) $r->DecisionDueDate : null;
            $out[] = [
                'month' => (int) date('n', $submitted),
                'service' => (int) $r->ServiceID,
                'procedure' => (int) $r->ProcedureID,
                'referent' => (int) $r->AssignedToID,
                'counter' => $r->isCounterIntake(),
                'completed' => $status === RequestStatus::Completed,
                'rejected' => $status === RequestStatus::Rejected,
                'dismissed' => $status === RequestStatus::Dismissed,
                'open' => !$status->isTerminal(),
                'overdue' => !$status->isTerminal() && $due !== null && $due < $today,
                'late' => $status->isTerminal() && $due !== null && $completed !== null && date('Y-m-d', $completed) > $due,
                'days' => ($status->isTerminal() && $completed !== null) ? max(0, (int) round(($completed - $submitted) / 86400)) : null,
                'supplemented' => (int) $r->SupplementRound > 0,
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>|null $fixedKeys ordered keys to always show (months)
     * @return array<string, array<string, mixed>> label => metrics
     */
    private function groupBy(array $rows, callable $key, ?array $fixedKeys, callable $label): array
    {
        $groups = [];
        foreach ($fixedKeys ?? [] as $k) {
            $groups[$k] = [];
        }
        foreach ($rows as $row) {
            $groups[(string) $key($row)][] = $row;
        }
        $out = [];
        foreach ($groups as $k => $groupRows) {
            $out[$label((string) $k)] = $this->metrics($groupRows);
        }
        if ($fixedKeys === null) {
            uasort($out, static fn (array $a, array $b): int => $b['Total'] <=> $a['Total']);
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function metrics(array $rows): array
    {
        $count = static fn (string $flag): int => count(array_filter($rows, static fn (array $r): bool => (bool) $r[$flag]));
        $days = array_values(array_filter(array_column($rows, 'days'), static fn ($d): bool => $d !== null));
        return [
            'Total' => count($rows),
            'Counter' => $count('counter'),
            'Portal' => count($rows) - $count('counter'),
            'Completed' => $count('completed'),
            'Rejected' => $count('rejected'),
            'Dismissed' => $count('dismissed'),
            'Open' => $count('open'),
            'Overdue' => $count('overdue'),
            'Late' => $count('late'),
            'Supplemented' => $count('supplemented'),
            'AvgDays' => $days === [] ? '—' : number_format(array_sum($days) / count($days), 1, ',', '.'),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $groups
     */
    private function section(string $title, array $groups, array $extraTotals = []): ArrayData
    {
        $max = max([1, ...array_map(static fn (array $m): int => (int) $m['Total'], $groups)]);
        $rows = ArrayList::create();
        $totals = array_fill_keys(['Total', 'Counter', 'Portal', 'Completed', 'Rejected', 'Dismissed', 'Open', 'Overdue', 'Late', 'Supplemented', 'SupplementItems', 'Decisions'], 0);
        foreach ($groups as $label => $m) {
            $rows->push(ArrayData::create(array_merge($m, [
                'Label' => $label,
                'BarPct' => (int) round(((int) $m['Total'] / $max) * 100),
            ])));
            foreach ($totals as $k => $v) {
                $totals[$k] = $v + (int) ($m[$k] ?? 0);
            }
        }
        return ArrayData::create([
            'Title' => $title,
            'Rows' => $rows,
            'Totals' => ArrayData::create(array_merge($totals, $extraTotals)),
            'ShowReferentColumns' => false,
        ]);
    }

    /**
     * Per referent: processed cases plus supplement requests and negative decisions
     * (transparency against misuse of supplement requests).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<int> $serviceIds
     */
    private function referentSection(int $year, array $rows, array $serviceIds): ArrayData
    {
        $groups = $this->groupBy(
            array_values(array_filter($rows, static fn (array $r): bool => $r['referent'] > 0)),
            static fn (array $r): string => (string) $r['referent'],
            null,
            static fn (string $k): string => $k
        );

        $from = sprintf('%d-01-01 00:00:00', $year);
        $to = sprintf('%d-01-01 00:00:00', $year + 1);
        $requestIds = ApplicationRequest::get()->filter('ServiceID', $serviceIds ?: [0])->sort(null)->column('ID') ?: [0];

        $suppByMember = [];
        foreach (SupplementItem::get()->filter(['RequestID' => $requestIds, 'Created:GreaterThanOrEqual' => $from, 'Created:LessThan' => $to]) as $item) {
            $suppByMember[(int) $item->MemberID] = ($suppByMember[(int) $item->MemberID] ?? 0) + 1;
        }
        $decByMember = [];
        foreach (ApplicationStatusChange::get()->filter([
            'RequestID' => $requestIds,
            'ActorType' => 'Staff',
            'ToStatus' => [RequestStatus::Rejected->value, RequestStatus::Dismissed->value],
            'Created:GreaterThanOrEqual' => $from,
            'Created:LessThan' => $to,
        ]) as $change) {
            $decByMember[(int) $change->MemberID] = ($decByMember[(int) $change->MemberID] ?? 0) + 1;
        }

        $memberIds = array_unique(array_merge(array_map('intval', array_keys($groups)), array_keys($suppByMember), array_keys($decByMember)));
        $named = [];
        foreach ($memberIds as $id) {
            $metrics = $groups[(string) $id] ?? $this->metrics([]);
            $metrics['SupplementItems'] = $suppByMember[$id] ?? 0;
            $metrics['Decisions'] = $decByMember[$id] ?? 0;
            $member = Member::get()->byID($id);
            $named[$member ? (string) $member->getName() : 'Korisnik #' . $id] = $metrics;
        }
        uasort($named, static fn (array $a, array $b): int => $b['Total'] <=> $a['Total']);

        $section = $this->section('Po referentima', $named);
        $section->setField('ShowReferentColumns', true);
        return $section;
    }

    private function serviceTitle(int $id): string
    {
        $s = MunicipalService::get()->byID($id);
        return $s ? $s->getTitleWithCode() : '—';
    }

    private function procedureTitle(int $id): string
    {
        $p = ProcedureDefinition::get()->byID($id);
        return $p ? $p->getTitleWithCode() : '—';
    }
}
