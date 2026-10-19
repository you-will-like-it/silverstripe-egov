<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Task;

use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use YouWillLikeIT\EGov\Enum\RequestStatus;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\MunicipalService;
use YouWillLikeIT\EGov\Service\DeadlineCalculator;
use YouWillLikeIT\EGov\Service\NotificationService;
use YouWillLikeIT\EGov\Workflow\ApplicationWorkflow;

/**
 * Cron (daily, working days 07:00): deadline digest per service and purge of
 * unconfirmed drafts (data minimisation – drafts contain JMBG and documents).
 *
 *   0 7 * * 1-5 vendor/bin/sake tasks:egov-deadlines
 */
class DeadlineMonitorTask extends BuildTask
{
    protected static string $commandName = 'egov-deadlines';

    protected string $title = 'e-Uprava: praćenje rokova i čišćenje nepotvrđenih zahtjeva';

    protected static string $description = 'Šalje službama pregled rokova (ZUP) i briše nepotvrđene nacrte starije od N dana.';

    /**
     * @config
     */
    private static int $purge_unconfirmed_after_days = 14;

    /**
     * Automatically dismiss ("smatra se nepodnesenim") requests whose supplement deadline
     * expired. Off by default – a referent can always do it manually in the CMS.
     * Cases with a protocol number are NEVER deleted, only closed.
     *
     * @config
     */
    private static bool $auto_dismiss_expired_supplements = false;

    /**
     * Extra days after SupplementDueDate before automatic dismissal (e.g. postal delivery).
     *
     * @config
     */
    private static int $auto_dismiss_grace_days = 3;

    public function getOptions(): array
    {
        return [
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Samo prikaži, bez slanja emailova i brisanja'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $calc = DeadlineCalculator::singleton();
        $warnDays = (int) DeadlineCalculator::config()->get('warning_days');
        $today = $calc->now()->format('Y-m-d');
        $warnUntil = $calc->now()->modify(sprintf('+%d days', $warnDays))->format('Y-m-d');

        $open = [
            RequestStatus::Submitted->value,
            RequestStatus::InProcess->value,
        ];

        foreach (MunicipalService::get() as $service) {
            $base = ApplicationRequest::get()->filter(['ServiceID' => $service->ID]);

            $overdue = $base->filter(['Status' => $open, 'DecisionDueDate:LessThan' => $today])->sort('DecisionDueDate')->toArray();
            $dueSoon = $base->filter([
                'Status' => $open,
                'DecisionDueDate:GreaterThanOrEqual' => $today,
                'DecisionDueDate:LessThanOrEqual' => $warnUntil,
            ])->sort('DecisionDueDate')->toArray();
            $supplementExpired = $base->filter([
                'Status' => [RequestStatus::Incomplete->value, RequestStatus::AwaitingPayment->value],
                'SupplementDueDate:LessThan' => $today,
            ])->sort('SupplementDueDate')->toArray();

            $output->writeln(sprintf(
                '%s: prekoračeno %d, uskoro %d, istekao rok za dopunu %d',
                $service->Code,
                count($overdue),
                count($dueSoon),
                count($supplementExpired)
            ));

            if (!$dryRun) {
                NotificationService::singleton()->sendDeadlineDigest($service, $dueSoon, $overdue, $supplementExpired);
            }
        }

        $this->autoDismiss($dryRun, $output);
        $this->purgeDrafts($dryRun, $output);

        return Command::SUCCESS;
    }

    private function autoDismiss(bool $dryRun, PolyOutput $output): void
    {
        if (!static::config()->get('auto_dismiss_expired_supplements')) {
            return;
        }
        $grace = max(0, (int) static::config()->get('auto_dismiss_grace_days'));
        $cutoff = DeadlineCalculator::singleton()->now()->modify(sprintf('-%d days', $grace))->format('Y-m-d');
        $cases = ApplicationRequest::get()->filter([
            'Status' => [RequestStatus::Incomplete->value, RequestStatus::AwaitingPayment->value],
            'SupplementDueDate:LessThan' => $cutoff,
        ]);
        $count = 0;
        foreach ($cases as $case) {
            $count++;
            if ($dryRun) {
                continue;
            }
            try {
                ApplicationWorkflow::singleton()->dismissExpiredSupplement($case);
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>%s: %s</error>', $case->TrackingCode, $e->getMessage()));
            }
        }
        $output->writeln(sprintf('Automatski odbačeno (istekao rok za dopunu + %d d.): %d %s', $grace, $count, $dryRun ? '(dry-run)' : ''));
    }

    private function purgeDrafts(bool $dryRun, PolyOutput $output): void
    {
        $days = max(1, (int) static::config()->get('purge_unconfirmed_after_days'));
        $cutoff = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() - $days * 86400);
        $drafts = ApplicationRequest::get()->filter([
            'Status' => RequestStatus::Draft->value,
            'Created:LessThan' => $cutoff,
            'IntakeChannel' => 'Portal',
            'ProtocolNumber' => [null, ''],
        ]);
        $count = 0;
        foreach ($drafts as $draft) {
            $count++;
            if ($dryRun) {
                continue;
            }
            foreach ($draft->Attachments() as $attachment) {
                $attachment->File()->deleteFile();
            }
            $draft->delete(); // cascade_deletes: attachments (+files), status changes
        }
        $output->writeln(sprintf('Nepotvrđeni nacrti stariji od %d dana: %d %s', $days, $count, $dryRun ? '(dry-run)' : 'obrisano'));
    }
}
