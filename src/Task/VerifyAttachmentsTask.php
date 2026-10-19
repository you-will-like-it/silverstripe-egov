<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Task;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use YouWillLikeIT\EGov\Enum\RequestStatus;
use YouWillLikeIT\EGov\Enum\VerificationLight;
use YouWillLikeIT\EGov\Model\ApplicationAttachment;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Verification\AiDocumentVerificationService;

/**
 * Cron (every 5 min): verifies pending attachments of submitted requests.
 *
 *   svakih 5 minuta: vendor/bin/sake tasks:egov-verify-attachments --limit=20
 */
class VerifyAttachmentsTask extends BuildTask
{
    protected static string $commandName = 'egov-verify-attachments';

    protected string $title = 'e-Uprava: AI provjera priloga';

    protected static string $description = 'Provjerava priloge sa statusom "Čeka provjeru" (semafor 🟢🟡🔴).';

    /**
     * Also verify drafts (gives citizens feedback before submission, costs AI tokens).
     *
     * @config
     */
    private static bool $verify_drafts = false;

    public function getOptions(): array
    {
        return [
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, 'Maksimalan broj zahtjeva po pokretanju', '20'),
            new InputOption('request', null, InputOption::VALUE_REQUIRED, 'ID jednog zahtjeva (provjerava sve priloge ponovo)'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $service = AiDocumentVerificationService::singleton();

        $single = (int) $input->getOption('request');
        if ($single > 0) {
            $request = ApplicationRequest::get()->byID($single);
            if (!$request) {
                $output->writeln('<error>Zahtjev ne postoji.</error>');
                return Command::FAILURE;
            }
            $light = $service->verifyRequest($request, true);
            $output->writeln(sprintf('%s %s', $request->TrackingCode, $light->icon()));
            return Command::SUCCESS;
        }

        $requestIds = ApplicationAttachment::get()
            ->filter('VerificationLight', VerificationLight::Pending->value)
            ->sort(null)
            ->columnUnique('RequestID');
        if ($requestIds === []) {
            $output->writeln('Nema priloga za provjeru.');
            return Command::SUCCESS;
        }

        $requests = ApplicationRequest::get()->filter('ID', $requestIds)->sort('SubmittedAt', 'ASC');
        if (!static::config()->get('verify_drafts')) {
            $requests = $requests->exclude('Status', RequestStatus::Draft->value);
        }
        $requests = $requests->limit(max(1, (int) $input->getOption('limit')));

        foreach ($requests as $request) {
            $light = $service->verifyRequest($request);
            $output->writeln(sprintf('%s (%s) → %s %s', $request->TrackingCode, $request->RegistryCode, $light->icon(), $light->label()));
        }
        return Command::SUCCESS;
    }
}
