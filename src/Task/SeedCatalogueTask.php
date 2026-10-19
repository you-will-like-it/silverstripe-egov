<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Task;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\SiteConfig\SiteConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use YouWillLikeIT\EGov\Model\CadastralMunicipality;
use YouWillLikeIT\EGov\Model\FeeItem;
use YouWillLikeIT\EGov\Model\MunicipalService;
use YouWillLikeIT\EGov\Model\NotificationTemplate;
use YouWillLikeIT\EGov\Model\PaymentAccount;
use YouWillLikeIT\EGov\Model\ProcedureDefinition;
use YouWillLikeIT\EGov\Model\RequiredDocument;

/**
 * Imports the tenant preset (YAML) – services, accounts, k.o., procedures,
 * fees and documents. Idempotent: existing records are kept unless --update.
 *
 *   vendor/bin/sake tasks:egov-seed
 *   vendor/bin/sake tasks:egov-seed --update
 */
class SeedCatalogueTask extends BuildTask
{
    protected static string $commandName = 'egov-seed';

    protected string $title = 'e-Uprava: uvoz kataloga procedura (preset općine)';

    protected static string $description = 'Kreira službe, račune, k.o., procedure, takse i dokumente iz YAML preseta.';

    /**
     * @config
     * @var array<string, mixed>
     */
    private static array $preset = [];

    public function getOptions(): array
    {
        return [
            new InputOption('update', null, InputOption::VALUE_NONE, 'Ažuriraj postojeće zapise vrijednostima iz preseta'),
            new InputOption('reset-templates', null, InputOption::VALUE_NONE, 'Vrati tekstove obavještenja na YAML zadane vrijednosti (prepisuje izmjene iz CMS-a!)'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $this->seedNotificationTemplates((bool) $input->getOption('reset-templates'), $output);

        $preset = (array) static::config()->get('preset');
        if ($preset === []) {
            $output->writeln('<comment>Preset općine nije konfigurisan (SeedCatalogueTask.preset) – uvezeni su samo tekstovi obavještenja.</comment>');
            return Command::SUCCESS;
        }
        $update = (bool) $input->getOption('update');

        $this->seedTenant((array) ($preset['tenant'] ?? []), $update, $output);
        $services = $this->seedServices((array) ($preset['services'] ?? []), $update, $output);
        $this->seedAccounts((array) ($preset['accounts'] ?? []), $update, $output);
        $this->seedCadastral((array) ($preset['cadastral_municipalities'] ?? []), $output);
        $this->seedProcedures((array) ($preset['procedures'] ?? []), $services, $update, $output);

        $output->writeln('<info>Gotovo.</info>');
        return Command::SUCCESS;
    }

    private function seedNotificationTemplates(bool $reset, PolyOutput $output): void
    {
        $defaults = (array) NotificationTemplate::config()->get('defaults_by_key');
        $created = 0;
        foreach ($defaults as $key => $row) {
            $record = NotificationTemplate::get()->filter('TemplateKey', (string) $key)->first();
            if ($record && !$reset) {
                continue;
            }
            $record ??= NotificationTemplate::create(['TemplateKey' => (string) $key]);
            $record->update([
                'Title' => (string) ($row['title'] ?? $key),
                'Subject' => (string) ($row['subject'] ?? ''),
                'Intro' => (string) ($row['intro'] ?? ''),
                'LegalNotice' => (string) ($row['legal'] ?? ''),
                'Outro' => (string) ($row['outro'] ?? ''),
            ]);
            $record->write();
            $created++;
        }
        $output->writeln(sprintf('Tekstovi obavještenja: %d %s.', $created, $reset ? 'vraćeno na zadano' : 'kreirano'));
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function seedTenant(array $tenant, bool $update, PolyOutput $output): void
    {
        if ($tenant === []) {
            return;
        }
        $site = SiteConfig::current_site_config();
        $map = [
            'type' => 'EGovMunicipalityType',
            'name' => 'EGovMunicipalityName',
            'code' => 'EGovMunicipalityCode',
            'id_number' => 'EGovMunicipalityIdNumber',
            'canton' => 'EGovCanton',
            'entity' => 'EGovEntity',
            'tracking_prefix' => 'EGovTrackingPrefix',
            'address' => 'EGovAddress',
            'phone' => 'EGovPhone',
            'email' => 'EGovEmail',
            'from_email' => 'EGovFromEmail',
        ];
        $changed = false;
        foreach ($map as $key => $field) {
            if (!array_key_exists($key, $tenant)) {
                continue;
            }
            if ($update || trim((string) $site->getField($field)) === '') {
                $site->setField($field, (string) $tenant[$key]);
                $changed = true;
            }
        }
        if ($changed) {
            $site->write();
            $output->writeln('Parametri općine postavljeni.');
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, MunicipalService> keyed by code
     */
    private function seedServices(array $rows, bool $update, PolyOutput $output): array
    {
        $out = [];
        foreach ($rows as $i => $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $service = MunicipalService::get()->filter('Code', $code)->first() ?? MunicipalService::create(['Code' => $code]);
            $isNew = !$service->isInDB();
            if ($isNew || $update) {
                $service->Title = (string) ($row['title'] ?? $code);
                $service->Department = (string) ($row['department'] ?? '');
                $service->Email = (string) ($row['email'] ?? $service->Email);
                $service->Phone = (string) ($row['phone'] ?? $service->Phone);
                $service->Sort = (int) ($row['sort'] ?? $i);
                $service->write();
                $output->writeln(sprintf('%s službu %s – %s', $isNew ? 'Kreirana' : 'Ažurirana', $code, $service->Title));
            }
            $out[$code] = $service;
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function seedAccounts(array $rows, bool $update, PolyOutput $output): void
    {
        foreach ($rows as $row) {
            $number = PaymentAccount::normalize((string) ($row['account'] ?? ''));
            if (strlen($number) !== 16) {
                $output->writeln(sprintf('<comment>Preskočen neispravan račun: %s</comment>', (string) ($row['account'] ?? '')));
                continue;
            }
            $account = PaymentAccount::get()->filter('AccountNumber', $number)->first() ?? PaymentAccount::create(['AccountNumber' => $number]);
            $isNew = !$account->isInDB();
            if ($isNew || $update) {
                $account->Title = (string) ($row['title'] ?? $number);
                $account->BankName = (string) ($row['bank'] ?? '');
                $account->Recipient = (string) ($row['recipient'] ?? '');
                $account->IsActive = true;
                $account->write();
                $output->writeln(sprintf('%s račun %s', $isNew ? 'Kreiran' : 'Ažuriran', PaymentAccount::format($number)));
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function seedCadastral(array $rows, PolyOutput $output): void
    {
        foreach ($rows as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $survey = ($row['survey'] ?? 'New') === 'Old' ? CadastralMunicipality::SURVEY_OLD : CadastralMunicipality::SURVEY_NEW;
            if (CadastralMunicipality::get()->filter(['Title' => $title, 'Survey' => $survey])->exists()) {
                continue;
            }
            CadastralMunicipality::create([
                'Title' => $title,
                'Code' => (string) ($row['code'] ?? ''),
                'Survey' => $survey,
                'IsActive' => true,
            ])->write();
            $output->writeln(sprintf('Kreirana k.o. %s', $title));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @param array<string, MunicipalService> $services
     */
    private function seedProcedures(array $rows, array $services, bool $update, PolyOutput $output): void
    {
        foreach ($rows as $code => $row) {
            $code = (string) $code;
            $serviceCode = (string) ($row['service'] ?? substr($code, 0, 2));
            $service = $services[$serviceCode] ?? MunicipalService::get()->filter('Code', $serviceCode)->first();
            if (!$service) {
                $output->writeln(sprintf('<comment>Procedura %s: služba %s ne postoji – preskočeno.</comment>', $code, $serviceCode));
                continue;
            }

            $procedure = ProcedureDefinition::getByCode($code) ?? ProcedureDefinition::create(['Code' => $code]);
            $isNew = !$procedure->isInDB();
            if (!$isNew && !$update) {
                continue;
            }

            $procedure->update([
                'Title' => (string) ($row['title'] ?? $code),
                'Summary' => (string) ($row['summary'] ?? ''),
                'LegalBasis' => (string) ($row['legal_basis'] ?? ''),
                'DeadlineCategory' => (string) ($row['deadline_category'] ?? 'Simple'),
                'RequestClass' => (string) ($row['class'] ?? ''),
                'RequiresLocation' => (bool) ($row['requires_location'] ?? false),
                'AiCheckEnabled' => (bool) ($row['ai_check'] ?? false),
                'IsActive' => (bool) ($row['active'] ?? true),
                'ResultDocumentTitle' => (string) ($row['result_document'] ?? ''),
                'ValidityNote' => (string) ($row['validity'] ?? ''),
                'ServiceClass' => (string) ($row['service_class'] ?? ''),
                'ServiceID' => $service->ID,
            ]);
            $procedure->write();

            if ($update) {
                foreach ($procedure->Fees() as $fee) {
                    $fee->delete();
                }
                foreach ($procedure->RequiredDocuments() as $doc) {
                    $doc->delete();
                }
            }

            foreach ((array) ($row['fees'] ?? []) as $i => $fee) {
                $accountNumber = PaymentAccount::normalize((string) ($fee['account'] ?? ''));
                $account = $accountNumber !== '' ? PaymentAccount::get()->filter('AccountNumber', $accountNumber)->first() : null;
                FeeItem::create([
                    'ProcedureID' => $procedure->ID,
                    'Title' => (string) ($fee['title'] ?? 'Taksa'),
                    'Kind' => (string) ($fee['kind'] ?? 'MunicipalFee'),
                    'LegalBasis' => (string) ($fee['legal_basis'] ?? ''),
                    'TariffNumber' => (string) ($fee['tariff'] ?? ''),
                    'Amount' => (float) ($fee['amount'] ?? 0),
                    'AmountMax' => isset($fee['amount_max']) ? (float) $fee['amount_max'] : 0,
                    'AdditionalUnitAmount' => (float) ($fee['additional_unit_amount'] ?? 0),
                    'AmountNote' => (string) ($fee['amount_note'] ?? ''),
                    'RevenueType' => (string) ($fee['revenue_type'] ?? ''),
                    'BudgetOrganization' => (string) ($fee['budget_organization'] ?? ''),
                    'PaymentReference' => (string) ($fee['reference'] ?? ''),
                    'VariantKey' => (string) ($fee['variant'] ?? ''),
                    'IsMandatory' => (bool) ($fee['mandatory'] ?? true),
                    'PaymentAccountID' => $account?->ID ?? 0,
                    'Sort' => $i,
                ])->write();
            }

            foreach ((array) ($row['documents'] ?? []) as $i => $doc) {
                RequiredDocument::create([
                    'ProcedureID' => $procedure->ID,
                    'DocumentKey' => (string) ($doc['key'] ?? ''),
                    'Title' => (string) ($doc['title'] ?? 'Dokument'),
                    'Issuer' => (string) ($doc['issuer'] ?? ''),
                    'DocumentForm' => (string) ($doc['form'] ?? 'Copy'),
                    'IsMandatory' => (bool) ($doc['mandatory'] ?? true),
                    'IsPaymentProof' => (bool) ($doc['payment_proof'] ?? false),
                    'ObtainedExOfficio' => (bool) ($doc['ex_officio'] ?? false),
                    'AiHint' => (string) ($doc['ai_hint'] ?? ''),
                    'Sort' => $i,
                ])->write();
            }

            $output->writeln(sprintf('%s proceduru %s – %s', $isNew ? 'Kreirana' : 'Ažurirana', $code, $procedure->Title));
        }
    }
}
