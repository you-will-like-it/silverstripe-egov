<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Task;

use RuntimeException;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;
use YouWillLikeIT\EGov\Enum\ApplicantType;
use YouWillLikeIT\EGov\Enum\DismissalReason;
use YouWillLikeIT\EGov\Enum\RequestStatus;
use YouWillLikeIT\EGov\Model\ApplicationAttachment;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\AuditLogEntry;
use YouWillLikeIT\EGov\Model\CadastralMunicipality;
use YouWillLikeIT\EGov\Model\FeeItem;
use YouWillLikeIT\EGov\Model\MunicipalService;
use YouWillLikeIT\EGov\Model\PaymentAccount;
use YouWillLikeIT\EGov\Model\ProcedureDefinition;
use YouWillLikeIT\EGov\Model\RequiredDocument;
use YouWillLikeIT\EGov\Pdf\PdfRendererInterface;
use YouWillLikeIT\EGov\Service\AttachmentUploadService;
use YouWillLikeIT\EGov\Service\NotificationService;
use YouWillLikeIT\EGov\Tenant\TenantConfig;
use YouWillLikeIT\EGov\Verification\AiDocumentVerificationService;
use YouWillLikeIT\EGov\Verification\TextExtraction\ChainTextExtractor;
use YouWillLikeIT\EGov\Workflow\ApplicationWorkflow;
use YouWillLikeIT\EGov\Workflow\TransitionContext;

/**
 * Demo data for presentations and referent training.
 *
 * Every case goes through the real ApplicationWorkflow (history, deadlines,
 * supplement notices, audit trail) with a mocked clock, so the CMS shows
 * realistic dates, overdue cases and expired supplement deadlines.
 * NO emails are sent while seeding. All persons and ID numbers are fictional.
 *
 *   vendor/bin/sake tasks:egov-demo                    # kreira demo predmete + 4 demo korisnika
 *   vendor/bin/sake tasks:egov-demo --reset            # briše prethodne demo podatke i kreira nove
 *   vendor/bin/sake tasks:egov-demo --remove           # samo briše demo podatke
 *   vendor/bin/sake tasks:egov-demo --history=40 --password='Demo-2026!'
 *
 * Requires: tasks:egov-seed (preset with procedures 08-01, 03-38, 05-16).
 * Refuses to run in live mode.
 */
class DemoDataTask extends BuildTask
{
    protected static string $commandName = 'egov-demo';

    protected string $title = 'e-Uprava: demo podaci za prezentaciju';

    protected static string $description = 'Kreira izmišljene predmete u svim statusima i demo korisnike (bez slanja emailova). Ne radi u live okruženju.';

    public const MARKER = '[DEMO]';
    public const EMAIL_DOMAIN = 'demo.e-uprava.test';
    private const GROUP_PREFIX = 'egov-demo-';

    /**
     * Demo staff. Services by code.
     *
     * @var array<string, array{first: string, last: string, services: list<string>, supervisor?: bool}>
     */
    private const STAFF = [
        'amra' => ['first' => 'Amra', 'last' => 'Hodžić', 'services' => ['08']],
        'emir' => ['first' => 'Emir', 'last' => 'Mehić', 'services' => ['08', '03']],
        'selma' => ['first' => 'Selma', 'last' => 'Begić', 'services' => ['05', '03']],
        'nermin' => ['first' => 'Nermin', 'last' => 'Salihović', 'services' => [], 'supervisor' => true],
    ];

    private const FIRST_M = ['Adnan', 'Haris', 'Kenan', 'Mirza', 'Senad', 'Jasmin', 'Almir', 'Edin', 'Damir', 'Nedim', 'Tarik', 'Elvir', 'Samir', 'Mehmed', 'Zlatan'];
    private const FIRST_F = ['Lejla', 'Amela', 'Merima', 'Sanela', 'Belma', 'Azra', 'Medina', 'Elma', 'Jasmina', 'Emina', 'Alma', 'Dženana', 'Nermina', 'Ajla', 'Lamija'];
    private const SURNAMES = ['Hasanović', 'Mujkić', 'Osmanović', 'Ahmetović', 'Husić', 'Delić', 'Smajlović', 'Alić', 'Kovačević', 'Imamović', 'Halilović', 'Mešić', 'Bajrić', 'Jusić', 'Zukić', 'Omerović'];
    private const STREETS = ['Ulica Lipa', 'Ulica Jasenova', 'Put za Brezik', 'Ulica Javorova', 'Ulica Proljetna', 'Ulica Kestenova', 'Ulica Sunčana', 'Ulica Lješnjakova'];
    private const PLACES = ['Srebrenik', 'Špionica', 'Tinja', 'Ćehaje', 'Podorašje', 'Lisovići'];

    private ApplicationWorkflow $workflow;
    private AttachmentUploadService $uploads;
    private AiDocumentVerificationService $verifier;
    private PdfRendererInterface $pdf;
    private PolyOutput $output;
    private int $realNow;
    private int $lastTs = 0;
    /** @var (NotificationService&object{count: int})|null */
    private ?NotificationService $silentNotifications = null;

    /** @var array<string, Member> */
    private array $staff = [];
    /** @var array<string, ProcedureDefinition> */
    private array $procedures = [];
    /** @var array<string, int> */
    private array $protocolSeq = [];
    /** @var array<string, ApplicationRequest> */
    private array $created = [];

    public function getOptions(): array
    {
        return [
            new InputOption('reset', null, InputOption::VALUE_NONE, 'Obriši prethodne demo podatke prije kreiranja'),
            new InputOption('remove', null, InputOption::VALUE_NONE, 'Samo obriši demo podatke (predmete, priloge, revizijske zapise, demo korisnike)'),
            new InputOption('history', null, InputOption::VALUE_REQUIRED, 'Broj dodatnih završenih predmeta za izvještaje (zadnja 4 mjeseca)', '30'),
            new InputOption('password', null, InputOption::VALUE_REQUIRED, 'Zajednička lozinka za demo korisnike (zadano: nasumična, ispisuje se na kraju)'),
            new InputOption('seed', null, InputOption::VALUE_REQUIRED, 'Seed za generator (isti seed = isti podaci)', '2026'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $this->output = $output;

        if (Director::isLive()) {
            $output->writeln('<error>Demo podaci se ne mogu kreirati u live okruženju (SS_ENVIRONMENT_TYPE=live).</error>');
            return Command::FAILURE;
        }

        if ($input->getOption('remove') || $input->getOption('reset')) {
            $this->removeDemoData();
            if ($input->getOption('remove')) {
                return Command::SUCCESS;
            }
        } elseif (ApplicationRequest::get()->filter('InternalNote:StartsWith', self::MARKER)->exists()) {
            $output->writeln('<comment>Demo podaci već postoje. Pokrenite sa --reset za ponovno kreiranje.</comment>');
            return Command::FAILURE;
        }

        mt_srand((int) $input->getOption('seed'));
        $this->realNow = DBDatetime::now()->getTimestamp();

        try {
            $this->loadProcedures();
            $password = (string) ($input->getOption('password') ?: $this->randomPassword());
            $this->ensureStaff($password);
            $this->ensureCadastralMunicipality();
            $this->bootServices();
            $this->preflightTextExtraction();

            foreach ($this->scenario() as $key => $case) {
                $this->runCase($key, $case);
            }
            $history = max(0, (int) $input->getOption('history'));
            for ($i = 1; $i <= $history; $i++) {
                $this->runCase('historija-' . $i, $this->randomHistoricCase());
            }
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } finally {
            DBDatetime::clear_mock_now();
        }

        $this->printSummary($password);
        return Command::SUCCESS;
    }

    // =====================================================================
    // Scenario
    // =====================================================================

    /**
     * Presentation cases. "day" = days ago (0 = today), steps chronological.
     *
     * @return array<string, array<string, mixed>>
     */
    private function scenario(): array
    {
        $okSlip = ['slip' => []];

        return [
            // --- 08-01 Urbanistička saglasnost --------------------------------
            'novi-portal' => [
                'title' => 'Novi zahtjev s portala – demonstracija „Pokreni obradu“',
                'procedure' => '08-01',
                'applicant' => $this->person('Lejla', 'Hasanović', 'F', '1987-03-14', 'Mujo'),
                'payload' => ['ObjectType' => 'Residential', 'ObjectCharacter' => 'Permanent', 'ObjectDescription' => 'Individualni stambeni objekat sa garažom', 'GrossFloorArea' => 142.5, 'FloorCount' => 'P+1'],
                'location' => ['Srebrenik', 'Ulica Lipa', '1234/5'],
                'docs' => ['kopija-katastarskog-plana' => [], 'dokaz-o-uplati' => ['slip' => ['amount' => 120.0]], 'obrazlozenje-idejni-projekat' => []],
                'steps' => [[1, 'submit'], [1, 'verify']],
            ],
            'zakljucan' => [
                'title' => 'U obradi kod Emira – drugi referent vidi „samo čitanje“, nadzor otključava',
                'procedure' => '08-01',
                'applicant' => $this->person('Haris', 'Osmanović', 'M', '1979-11-02', 'Salih'),
                'payload' => ['ObjectType' => 'ResidentialCommercial', 'ObjectCharacter' => 'Permanent', 'ObjectDescription' => 'Stambeno-poslovni objekat sa lokalom u prizemlju', 'GrossFloorArea' => 310.0, 'FloorCount' => 'P+2'],
                'location' => ['Špionica', 'Put za Brezik', '877/2'],
                'docs' => ['kopija-katastarskog-plana' => [], 'dokaz-o-uplati' => ['slip' => ['amount' => 200.0]], 'obrazlozenje-idejni-projekat' => []],
                'steps' => [[4, 'submit'], [4, 'verify'], [3, 'start', 'emir']],
            ],
            'dopuna-u-toku' => [
                'title' => 'Dopuna u toku – nečitak sken (građanin dostavlja dopunu preko linka)',
                'procedure' => '08-01',
                'applicant' => $this->person('Kenan', 'Ahmetović', 'M', '1991-06-21', 'Hasan'),
                'payload' => ['ObjectType' => 'Residential', 'ObjectCharacter' => 'Permanent', 'ObjectDescription' => 'Dogradnja postojećeg stambenog objekta', 'GrossFloorArea' => 64.0, 'FloorCount' => 'P'],
                'location' => ['Tinja', 'Ulica Jasenova', '455/1'],
                'docs' => ['kopija-katastarskog-plana' => ['illegible' => true], 'dokaz-o-uplati' => ['slip' => ['amount' => 70.0]], 'obrazlozenje-idejni-projekat' => []],
                'steps' => [[6, 'submit'], [6, 'verify'], [5, 'start', 'amra'], [5, 'supplement', 'amra', ['kopija-katastarskog-plana' => 'Illegible']]],
            ],
            'dopuna-istekla' => [
                'title' => 'Rok za dopunu istekao – demonstracija „Odbaci – rok za dopunu istekao“',
                'procedure' => '08-01',
                'applicant' => $this->company('Gradnja-Invest d.o.o.', 'Senad Husić'),
                'payload' => ['ObjectType' => 'Residential', 'ObjectCharacter' => 'Permanent', 'ObjectDescription' => 'Stambeni objekat – dvojna kuća', 'GrossFloorArea' => 220.0, 'FloorCount' => 'P+1+Pk'],
                'location' => ['Srebrenik', 'Ulica Javorova', '2011/3'],
                'docs' => ['kopija-katastarskog-plana' => [], 'dokaz-o-uplati' => ['slip' => ['amount' => 50.0]], 'obrazlozenje-idejni-projekat' => []],
                'steps' => [[21, 'submit'], [21, 'verify'], [19, 'start', 'amra'], [19, 'supplement', 'amra', ['dokaz-o-uplati' => 'WrongPayment']]],
            ],
            'spreman-za-preuzimanje' => [
                'title' => 'Spreman za preuzimanje – demonstracija „Evidentiraj preuzimanje“',
                'procedure' => '08-01',
                'applicant' => $this->person('Amela', 'Delić', 'F', '1983-01-30', 'Ibro'),
                'payload' => ['ObjectType' => 'Residential', 'ObjectCharacter' => 'Permanent', 'ObjectDescription' => 'Individualni stambeni objekat', 'GrossFloorArea' => 118.0, 'FloorCount' => 'P+Pk'],
                'location' => ['Ćehaje', 'Ulica Proljetna', '96/4'],
                'docs' => ['kopija-katastarskog-plana' => [], 'dokaz-o-uplati' => ['slip' => ['amount' => 100.0]], 'obrazlozenje-idejni-projekat' => []],
                'steps' => [[30, 'submit'], [30, 'verify'], [28, 'start', 'amra'], [3, 'result', 'amra'], [3, 'status', 'amra', 'ReadyForPickup']],
            ],
            'zavrsen-nakon-dopune' => [
                'title' => 'Završen nakon jedne dopune – kompletan tok predmeta',
                'procedure' => '08-01',
                'applicant' => $this->person('Mirza', 'Smajlović', 'M', '1975-08-09', 'Ramo'),
                'payload' => ['ObjectType' => 'Residential', 'ObjectCharacter' => 'Permanent', 'ObjectDescription' => 'Stambeni objekat sa pomoćnim objektom', 'GrossFloorArea' => 156.0, 'FloorCount' => 'P+1'],
                'location' => ['Podorašje', 'Ulica Kestenova', '312/7'],
                'docs' => ['kopija-katastarskog-plana' => [], 'dokaz-o-uplati' => ['slip' => ['amount' => 150.0]], 'obrazlozenje-idejni-projekat' => ['wrong' => true]],
                'steps' => [
                    [45, 'submit'], [45, 'verify'], [44, 'start', 'emir'],
                    [44, 'supplement', 'emir', ['obrazlozenje-idejni-projekat' => 'WrongDocument']],
                    [40, 'citizen_supplement', 'obrazlozenje-idejni-projekat'], [40, 'verify'],
                    [39, 'start', 'emir'], [25, 'result', 'emir'], [25, 'status', 'emir', 'ReadyForPickup'], [22, 'pickup', 'amra'],
                ],
            ],
            'prekoracen-rok' => [
                'title' => 'Prekoračen zakonski rok – crvena oznaka u listi i u dnevnom pregledu rokova',
                'procedure' => '08-01',
                'applicant' => $this->person('Merima', 'Imamović', 'F', '1969-04-17', 'Mehmed'),
                'payload' => ['ObjectType' => 'Commercial', 'ObjectCharacter' => 'Permanent', 'ObjectDescription' => 'Poslovni objekat – skladište', 'GrossFloorArea' => 480.0, 'FloorCount' => 'P'],
                'location' => ['Lisovići', 'Ulica Sunčana', '1502/1'],
                'docs' => ['kopija-katastarskog-plana' => [], 'dokaz-o-uplati' => ['slip' => ['amount' => 300.0]], 'obrazlozenje-idejni-projekat' => []],
                'steps' => [[68, 'submit'], [68, 'verify'], [66, 'start', 'amra']],
            ],
            'odbijen' => [
                'title' => 'Odbijen zahtjev (meritorna odluka)',
                'procedure' => '08-01',
                'applicant' => $this->person('Jasmin', 'Halilović', 'M', '1988-12-05', 'Fikret'),
                'payload' => ['ObjectType' => 'Residential', 'ObjectCharacter' => 'Temporary', 'ObjectDescription' => 'Privremeni montažni objekat', 'GrossFloorArea' => 36.0, 'FloorCount' => 'P'],
                'location' => ['Srebrenik', 'Ulica Lješnjakova', '640/2'],
                'docs' => ['kopija-katastarskog-plana' => [], 'dokaz-o-uplati' => ['slip' => ['amount' => 70.0]], 'obrazlozenje-idejni-projekat' => []],
                'steps' => [[50, 'submit'], [50, 'verify'], [49, 'start', 'emir'], [20, 'status', 'emir', 'Rejected', 'Planirani objekat nije u skladu sa važećom planskom dokumentacijom (obrazloženje u rješenju).']],
            ],

            // --- 03-38 Kopija katastarskog plana -------------------------------
            'salter-bez-emaila' => [
                'title' => 'Prijem na šalteru, bez emaila – potvrda o prijemu sa kodom predmeta',
                'procedure' => '03-38',
                'applicant' => $this->person('Sanela', 'Mešić', 'F', '1958-09-23', 'Hamid', email: false),
                'payload' => ['ParcelCount' => 3, 'Purpose' => 'Legalisation'],
                'location' => ['Srebrenik', '', '1201/1, 1201/2, 1203'],
                'counter' => 'selma',
                'docs' => ['dokaz-o-uplati' => ['slip' => ['amount' => 19.0]]],
                'steps' => [[0, 'counter', 'selma'], [0, 'verify']],
            ],
            'ceka-uplatu' => [
                'title' => 'Čeka uplatu – uplaćeno 11 KM umjesto 19 KM (3 parcele)',
                'procedure' => '03-38',
                'applicant' => $this->person('Edin', 'Bajrić', 'M', '1990-02-11', 'Asim'),
                'payload' => ['ParcelCount' => 3, 'Purpose' => 'Construction'],
                'location' => ['Tinja', '', '88/1, 88/2, 89'],
                'docs' => ['dokaz-o-uplati' => ['slip' => ['amount' => 11.0]]],
                'steps' => [[3, 'submit'], [3, 'verify'], [2, 'start', 'emir'], [2, 'supplement', 'emir', ['dokaz-o-uplati' => 'WrongPayment'], 'AwaitingPayment']],
            ],
            'pogresan-racun' => [
                'title' => 'Novi zahtjev – uplata na pogrešan račun (🔴) – demonstracija provjere i zatvorene dopune',
                'procedure' => '03-38',
                'applicant' => $this->person('Belma', 'Jusić', 'F', '1995-07-03', 'Nihad'),
                'payload' => ['ParcelCount' => 1, 'Purpose' => 'Sale'],
                'location' => ['Ćehaje', '', '415/3'],
                'docs' => ['dokaz-o-uplati' => ['slip' => ['amount' => 11.0, 'account' => '1321500299280097', 'revenue' => '722131']]],
                'steps' => [[0, 'submit'], [0, 'verify']],
            ],
            'katastar-zavrsen' => [
                'title' => 'Šalter, sa emailom – završen',
                'procedure' => '03-38',
                'applicant' => $this->person('Damir', 'Zukić', 'M', '1972-05-28', 'Šaban'),
                'payload' => ['ParcelCount' => 2, 'Purpose' => 'Court'],
                'location' => ['Srebrenik', '', '730/1, 731'],
                'counter' => 'emir',
                'docs' => ['dokaz-o-uplati' => ['slip' => ['amount' => 15.0]]],
                'steps' => [[15, 'counter', 'emir'], [15, 'verify'], [14, 'start', 'emir'], [13, 'result', 'emir'], [13, 'status', 'emir', 'Completed']],
            ],

            // --- 05-16 Arhiva -----------------------------------------------
            'arhiva-rok-istice' => [
                'title' => 'Rok ističe za par dana (žuta oznaka)',
                'procedure' => '05-16',
                'applicant' => $this->person('Azra', 'Omerović', 'F', '1964-10-12', 'Muhamed'),
                'payload' => ['RequestedDocument' => 'Prepis rješenja o dodjeli građevinskog zemljišta iz 1998. godine (k.č. 905, k.o. Srebrenik).', 'Purpose' => 'Sudski postupak'],
                'docs' => ['dokaz-o-uplati' => ['slip' => ['amount' => 10.0]]],
                'steps' => [[8, 'submit'], [8, 'verify'], [7, 'start', 'selma']],
            ],
            'arhiva-odbacen' => [
                'title' => 'Odbačen – nenadležnost (stranka upućena na nadležni organ)',
                'procedure' => '05-16',
                'applicant' => $this->person('Nedim', 'Kovačević', 'M', '1985-03-19', 'Esad'),
                'payload' => ['RequestedDocument' => 'Kopija gruntovnog izvatka za parcelu u Tuzli.', 'Purpose' => 'Kupoprodaja'],
                'docs' => ['dokaz-o-uplati' => ['slip' => ['amount' => 10.0]]],
                'steps' => [[6, 'submit'], [6, 'verify'], [5, 'start', 'selma'], [5, 'status', 'selma', 'Dismissed', 'NotCompetent']],
            ],
            'arhiva-zavrsen' => [
                'title' => 'Završen – preuzet na šalteru',
                'procedure' => '05-16',
                'applicant' => $this->person('Emina', 'Alić', 'F', '1999-11-27', 'Senad'),
                'payload' => ['RequestedDocument' => 'Kopija odobrenja za upotrebu objekta iz 2006. godine.', 'Purpose' => 'Banka – kredit'],
                'docs' => ['dokaz-o-uplati' => ['slip' => ['amount' => 10.0]]],
                'steps' => [[40, 'submit'], [40, 'verify'], [39, 'start', 'selma'], [37, 'result', 'selma'], [37, 'status', 'selma', 'ReadyForPickup'], [35, 'pickup', 'selma']],
            ],
        ];
    }

    /**
     * Finished case in the last ~4 months – feeds the PDF reports.
     *
     * @return array<string, mixed>
     */
    private function randomHistoricCase(): array
    {
        $code = $this->pick(['08-01', '08-01', '03-38', '03-38', '03-38', '05-16', '05-16']);
        $referents = ['08-01' => ['amra', 'emir'], '03-38' => ['emir', 'selma'], '05-16' => ['selma']][$code];
        $who = $this->pick($referents);
        $start = mt_rand(12, 120);
        $taken = $start - mt_rand(0, 2);
        $female = mt_rand(0, 1) === 1;
        $applicant = $this->person(
            $this->pick($female ? self::FIRST_F : self::FIRST_M),
            $this->pick(self::SURNAMES),
            $female ? 'F' : 'M',
            sprintf('%04d-%02d-%02d', mt_rand(1950, 2003), mt_rand(1, 12), mt_rand(1, 28)),
            $this->pick(self::FIRST_M),
            email: mt_rand(0, 9) > 1,
        );
        $counter = $applicant['email'] === '' || mt_rand(0, 4) === 0;

        $case = [
            'title' => 'Historija',
            'procedure' => $code,
            'applicant' => $applicant,
            'steps' => [[$start, $counter ? 'counter' : 'submit', $who], [$start, 'verify'], [$taken, 'start', $who]],
        ];
        if ($counter) {
            $case['counter'] = $who;
        }

        switch ($code) {
            case '08-01':
                $type = $this->pick(['Residential', 'Residential', 'ResidentialCommercial']);
                $area = mt_rand(60, 320);
                $case['payload'] = ['ObjectType' => $type, 'ObjectCharacter' => 'Permanent', 'ObjectDescription' => $type === 'Residential' ? 'Individualni stambeni objekat' : 'Stambeno-poslovni objekat', 'GrossFloorArea' => $area, 'FloorCount' => $this->pick(['P', 'P+1', 'P+Pk', 'P+1+Pk'])];
                $case['location'] = [$this->pick(self::PLACES), $this->pick(self::STREETS), mt_rand(100, 2500) . '/' . mt_rand(1, 9)];
                $case['docs'] = ['kopija-katastarskog-plana' => [], 'dokaz-o-uplati' => ['slip' => ['amount' => $type === 'Residential' ? 100.0 : 200.0]], 'obrazlozenje-idejni-projekat' => []];
                $decided = max(2, $taken - mt_rand(5, 40));
                $roll = mt_rand(1, 100);
                if ($roll <= 10) {
                    $case['steps'][] = [$decided, 'status', $who, 'Rejected', 'Nisu ispunjeni uslovi iz planske dokumentacije.'];
                } elseif ($roll <= 16) {
                    $case['steps'][] = [$decided, 'status', $who, 'Dismissed', 'Withdrawn'];
                } else {
                    $case['steps'][] = [$decided, 'result', $who];
                    $case['steps'][] = [$decided, 'status', $who, 'ReadyForPickup'];
                    $case['steps'][] = [max(1, $decided - mt_rand(1, 5)), 'pickup', $who];
                }
                break;

            case '03-38':
                $parcels = mt_rand(1, 4);
                $case['payload'] = ['ParcelCount' => $parcels, 'Purpose' => $this->pick(['Construction', 'Legalisation', 'Court', 'Sale', 'Other'])];
                $case['location'] = [$this->pick(self::PLACES), '', (string) mt_rand(100, 2500)];
                $case['docs'] = ['dokaz-o-uplati' => ['slip' => ['amount' => 11.0 + 4.0 * ($parcels - 1)]]];
                $decided = max(1, $taken - mt_rand(0, 4));
                $case['steps'][] = [$decided, 'result', $who];
                $case['steps'][] = [$decided, 'status', $who, 'Completed'];
                break;

            default: // 05-16
                $case['payload'] = ['RequestedDocument' => $this->pick(['Prepis rješenja iz arhive.', 'Kopija građevinske dozvole.', 'Ovjerena kopija zapisnika.']), 'Purpose' => $this->pick(['Sud', 'Banka', 'Lične potrebe'])];
                $case['docs'] = ['dokaz-o-uplati' => ['slip' => ['amount' => 10.0]]];
                $decided = max(2, $taken - mt_rand(1, 8));
                $case['steps'][] = [$decided, 'result', $who];
                $case['steps'][] = [$decided, 'status', $who, 'ReadyForPickup'];
                $case['steps'][] = [max(1, $decided - mt_rand(1, 4)), 'pickup', $who];
                break;
        }
        return $case;
    }

    // =====================================================================
    // Execution
    // =====================================================================

    /**
     * @param array<string, mixed> $case
     */
    private function runCase(string $key, array $case): void
    {
        $procedure = $this->procedures[(string) $case['procedure']];
        $this->lastTs = 0;
        $request = null;

        foreach ((array) $case['steps'] as $index => $step) {
            $this->mockTime((int) $step[0], $index, count($case['steps']));
            $action = (string) $step[1];

            if ($action === 'submit' || $action === 'counter') {
                $request = $this->createRequest($procedure, $case);
                if ($action === 'submit') {
                    $this->attachInitialDocuments($request, $case, null);
                    $request->EmailConfirmedAt = $this->now();
                    $request->PrivacyConsentAt = $this->now();
                    $request->write();
                    $this->workflow->transition($request, RequestStatus::Submitted, TransitionContext::citizen('Podnošenje potvrđeno putem emaila.'));
                } else {
                    $member = $this->staff[(string) $step[2]];
                    $this->attachInitialDocuments($request, $case, $member);
                    $this->workflow->registerCounterIntake($request, $member);
                }
                continue;
            }
            if (!$request instanceof ApplicationRequest) {
                throw new RuntimeException(sprintf('Demo predmet „%s“: prvi korak mora biti submit ili counter.', $key));
            }
            $request = ApplicationRequest::get()->byID($request->ID); // fresh state after each step
            $member = isset($step[2]) && is_string($step[2]) ? ($this->staff[$step[2]] ?? null) : null;

            switch ($action) {
                case 'verify':
                    $this->verifier->verifyRequest($request, true);
                    break;

                case 'start':
                    if (trim((string) $request->ProtocolNumber) === '') {
                        $request->ProtocolNumber = $this->nextProtocolNumber($request);
                        $request->write();
                    }
                    $this->workflow->startProcessing($request, $member);
                    break;

                case 'supplement':
                    $items = [];
                    foreach ((array) $step[3] as $docKey => $reason) {
                        $items[(int) $this->document($procedure, (string) $docKey)->ID] = (string) $reason;
                    }
                    $target = RequestStatus::from((string) ($step[4] ?? 'Incomplete'));
                    $this->workflow->transition($request, $target, new TransitionContext(
                        actorType: TransitionContext::ACTOR_STAFF,
                        member: $member,
                        internalNote: 'Pregledani prilozi, tražena dopuna.',
                        supplementItems: $items,
                    ));
                    break;

                case 'citizen_supplement':
                    $document = $this->document($procedure, (string) $step[2]);
                    $this->uploads->storeGenerated($request, $document, $this->renderDocument($request, $document, []), $this->citizenFilename($document, true), ApplicationAttachment::KIND_CITIZEN, null, true);
                    $this->workflow->submitSupplement($request);
                    break;

                case 'result':
                    $this->uploads->storeGenerated($request, null, $this->renderResult($request, $member), 'izdati-akt.pdf', ApplicationAttachment::KIND_RESULT, $member);
                    break;

                case 'status':
                    $target = RequestStatus::from((string) $step[3]);
                    $extra = (string) ($step[4] ?? '');
                    $this->workflow->transition($request, $target, new TransitionContext(
                        actorType: TransitionContext::ACTOR_STAFF,
                        member: $member,
                        internalNote: match ($target) {
                            RequestStatus::ReadyForPickup => 'Akt potpisan, stranka obaviještena.',
                            RequestStatus::Completed => 'Dokument izdat.',
                            default => '',
                        },
                        dismissalReason: $target === RequestStatus::Dismissed ? DismissalReason::from($extra) : null,
                        rejectionNote: $target === RequestStatus::Rejected ? $extra : '',
                    ));
                    break;

                case 'pickup':
                    $this->workflow->registerPickup($request, $member);
                    break;

                default:
                    throw new RuntimeException(sprintf('Nepoznat korak „%s“.', $action));
            }
        }

        if ($request instanceof ApplicationRequest) {
            $this->created[$key] = ApplicationRequest::get()->byID($request->ID);
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    private function createRequest(ProcedureDefinition $procedure, array $case): ApplicationRequest
    {
        $a = (array) $case['applicant'];
        $class = $procedure->getRequestClassName();
        /** @var ApplicationRequest $request */
        $request = $class::create([
            'ProcedureID' => $procedure->ID,
            'IntakeChannel' => isset($case['counter']) ? ApplicationRequest::CHANNEL_COUNTER : ApplicationRequest::CHANNEL_PORTAL,
            'ApplicantType' => $a['type'],
            'ApplicantFullName' => $a['name'],
            'ApplicantParentName' => $a['parent'],
            'ApplicantIdentificationNumber' => $a['id'],
            'ApplicantAddress' => $a['address'],
            'ApplicantPostalCode' => '75350',
            'ApplicantCity' => $a['city'],
            'ApplicantPhone' => $a['phone'],
            'ApplicantEmail' => $a['email'],
            'RepresentativeName' => $a['representative'],
            'InternalNote' => self::MARKER . ' Izmišljeni podaci za prezentaciju.',
        ]);
        if (isset($case['location'])) {
            [$place, $street, $parcel] = $case['location'];
            $request->LocationPlace = $place;
            $request->LocationStreet = $street;
            $request->CadastralParcelNew = $parcel;
            $request->CadastralMunicipalityNewID = (int) CadastralMunicipality::get()->filter('IsActive', true)->sort('ID')->first()?->ID;
        }
        $request->saveProcedureFormData((array) ($case['payload'] ?? []));
        if (isset($case['counter'])) {
            $request->ProtocolNumber = $this->nextProtocolNumber($request);
            $request->IntakeByID = (int) $this->staff[(string) $case['counter']]->ID;
        }
        $request->write();
        return $request;
    }

    /**
     * @param array<string, mixed> $case
     */
    private function attachInitialDocuments(ApplicationRequest $request, array $case, ?Member $staff): void
    {
        $procedure = $request->Procedure();
        foreach ((array) ($case['docs'] ?? []) as $docKey => $spec) {
            $document = $this->document($procedure, (string) $docKey);
            $this->uploads->storeGenerated(
                $request,
                $document,
                $this->renderDocument($request, $document, (array) $spec),
                $staff ? 'sken-salter.pdf' : $this->citizenFilename($document, false),
                $staff ? ApplicationAttachment::KIND_STAFF : ApplicationAttachment::KIND_CITIZEN,
                $staff
            );
        }
    }

    // =====================================================================
    // Generated documents (fictional, clearly marked)
    // =====================================================================

    /**
     * @param array<string, mixed> $spec  slip: [amount, account, revenue, municipality] | illegible | wrong
     */
    private function renderDocument(ApplicationRequest $request, RequiredDocument $document, array $spec): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES);
        $stamp = '<p style="color:#b00;font-weight:bold;border:2px solid #b00;padding:4px 8px;display:inline-block">PRIMJER – IZMIŠLJENI PODACI (DEMO)</p>';

        if (!empty($spec['illegible'])) {
            // practically no text layer → reads as unreadable scan
            return $this->pdf->render('<div style="height:240mm;background:#ddd"></div>', ['title' => 'sken']);
        }

        if ($document->IsPaymentProof) {
            $s = (array) ($spec['slip'] ?? []);
            $fee = $request->getApplicableFees()->filter('PaymentAccountID:GreaterThan', 0)->first();
            $account = (string) ($s['account'] ?? ($fee instanceof FeeItem ? $fee->PaymentAccount()->AccountNumber : ''));
            [$min] = $fee instanceof FeeItem ? $request->expectedRangeFor($fee) : [0.0];
            $amount = (float) ($s['amount'] ?? $min);
            $revenue = (string) ($s['revenue'] ?? ($fee instanceof FeeItem ? $fee->RevenueType : ''));
            $municipality = (string) ($s['municipality'] ?? TenantConfig::singleton()->municipalityCode());
            $period = date('d.m.Y', DBDatetime::now()->getTimestamp());

            $rows = [
                'Uplatilac' => $request->ApplicantFullName . ', ' . $request->ApplicantAddress . ', ' . $request->ApplicantCity,
                'Svrha uplate' => (string) ($fee instanceof FeeItem ? $fee->Title : 'Taksa'),
                'Primalac' => 'Budžet ' . TenantConfig::singleton()->fullName(),
                'Račun primaoca' => PaymentAccount::format($account),
                'Iznos' => number_format($amount, 2, ',', '.') . ' KM',
                'Broj poreznog obveznika' => (string) $request->ApplicantIdentificationNumber,
                'Vrsta uplate' => '0',
                'Vrsta prihoda' => $revenue,
                'Porezni period' => $period . ' - ' . $period,
                'Općina' => $municipality,
                'Budžetska organizacija' => '0000000',
                'Poziv na broj' => '0000000000',
            ];
            $html = '<h3>NALOG ZA UPLATU JAVNIH PRIHODA</h3>' . $stamp . '<table style="width:100%;border-collapse:collapse" cellpadding="6">';
            foreach ($rows as $label => $value) {
                $html .= sprintf('<tr><td style="border:1px solid #999;width:40%%">%s:</td><td style="border:1px solid #999">%s</td></tr>', $e($label), $e($value));
            }
            $html .= '</table><p>Datum uplate: ' . $e($period) . ' · Demo banka d.d., ekspozitura Srebrenik</p>';
            return $this->pdf->render($html, ['title' => 'Uplatnica']);
        }

        $title = !empty($spec['wrong']) ? 'Ponuda za izvođenje radova (pogrešan dokument)' : (string) $document->Title;
        $body = match (true) {
            !empty($spec['wrong']) => '<p>Predmjer i predračun radova – ponuda izvođača. Ovaj dokument nije idejni projekat.</p>',
            str_contains((string) $document->DocumentKey, 'katastar') => sprintf(
                '<p>Kopija katastarskog plana · k.o. %s · k.č. %s · razmjer 1:1000</p><div style="height:90mm;border:1px solid #333;margin:6mm 0"></div><p>Služba za geodetske i imovinsko-pravne poslove</p>',
                $e((string) ($request->CadastralMunicipalityNew()->Title ?: 'Srebrenik')),
                $e((string) $request->CadastralParcelNew)
            ),
            default => sprintf(
                '<p>Tehnički opis: %s, lokacija %s.</p><p>Projektant: Demo projekt d.o.o.</p><div style="height:70mm;border:1px dashed #333;margin:6mm 0"></div>',
                $e($request->getSubjectLine()),
                $e(trim($request->LocationPlace . ' ' . $request->LocationStreet))
            ),
        };
        return $this->pdf->render('<h3>' . $e($title) . '</h3>' . $stamp . $body, ['title' => $title]);
    }

    private function renderResult(ApplicationRequest $request, ?Member $member): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES);
        $title = (string) ($request->Procedure()->ResultDocumentTitle ?: 'Akt');
        return $this->pdf->render(sprintf(
            '<p>%s</p><p>Broj: %s<br>Datum: %s</p><h3 style="text-align:center">%s</h3><p style="color:#b00;font-weight:bold">PRIMJER – IZMIŠLJENI PODACI (DEMO)</p><p>Podnosilac: %s</p><p style="margin-top:30mm">Obradio: %s</p>',
            $e(TenantConfig::singleton()->fullName()),
            $e((string) $request->ProtocolNumber),
            $e(date('d.m.Y.', DBDatetime::now()->getTimestamp())),
            $e(mb_strtoupper($title)),
            $e((string) $request->ApplicantFullName),
            $e((string) $member?->getName())
        ), ['title' => $title]);
    }

    private function citizenFilename(RequiredDocument $document, bool $supplement): string
    {
        $base = $document->IsPaymentProof ? 'uplatnica' : (RequiredDocument::slug((string) $document->DocumentKey) ?: 'dokument');
        return $base . ($supplement ? '-dopuna' : '') . '.pdf';
    }

    // =====================================================================
    // Setup
    // =====================================================================

    private function bootServices(): void
    {
        $this->silentNotifications = new class extends NotificationService {
            public int $count = 0;

            protected function send(string $to, string $subject, array $data): bool
            {
                $this->count++;
                return true; // pretend delivery – history shows "obaviješten" as in production
            }
        };
        $this->workflow = new ApplicationWorkflow($this->silentNotifications);
        $this->uploads = AttachmentUploadService::singleton();
        $this->verifier = AiDocumentVerificationService::singleton();
        $this->pdf = Injector::inst()->get(PdfRendererInterface::class);
    }

    private function loadProcedures(): void
    {
        foreach (['08-01', '03-38', '05-16'] as $code) {
            $procedure = ProcedureDefinition::getByCode($code);
            if (!$procedure instanceof ProcedureDefinition || !$procedure->Service()->exists()) {
                throw new RuntimeException(sprintf('Procedura %s ne postoji. Prvo kopirajte preset (presets/srebrenik.yml) i pokrenite tasks:egov-seed.', $code));
            }
            $this->procedures[$code] = $procedure;
        }
    }

    private function ensureStaff(string $password): void
    {
        $supervisorGroup = $this->supervisorGroup();
        foreach (self::STAFF as $alias => $row) {
            $email = sprintf('%s.%s@%s', $alias, $this->ascii($row['last']), self::EMAIL_DOMAIN);
            $member = Member::get()->filter('Email', $email)->first();
            if (!$member instanceof Member) {
                $member = Member::create(['FirstName' => $row['first'], 'Surname' => $row['last'], 'Email' => $email]);
                $member->write();
            }
            $result = $member->changePassword($password);
            if (!$result->isValid()) {
                throw new RuntimeException('Lozinka nije prihvaćena: ' . implode(' ', array_column($result->getMessages(), 'message')));
            }
            foreach ($row['services'] as $code) {
                $member->Groups()->add($this->serviceGroup($code));
            }
            if (!empty($row['supervisor'])) {
                $member->Groups()->add($supervisorGroup);
            }
            $this->staff[$alias] = $member;
        }
    }

    private function serviceGroup(string $code): Group
    {
        $service = MunicipalService::get()->filter('Code', $code)->first();
        if (!$service instanceof MunicipalService) {
            throw new RuntimeException(sprintf('Služba %s ne postoji (tasks:egov-seed).', $code));
        }
        $group = $service->Groups()->sort('ID')->first();
        if (!$group instanceof Group) {
            $group = Group::create(['Title' => 'Referenti – ' . $service->Title, 'Code' => self::GROUP_PREFIX . 'referenti-' . $code]);
            $group->write();
            $service->Groups()->add($group);
            $this->output->writeln(sprintf('Kreirana grupa „%s“ i vezana za službu %s.', $group->Title, $code));
        }
        if (!Permission::get()->filter(['GroupID' => $group->ID, 'Code' => ApplicationRequest::PERMISSION_REFERENT])->exists()) {
            Permission::grant($group->ID, ApplicationRequest::PERMISSION_REFERENT);
        }
        return $group;
    }

    private function supervisorGroup(): Group
    {
        $group = Group::get()->filter('Code', self::GROUP_PREFIX . 'nadzor')->first();
        if (!$group instanceof Group) {
            $group = Group::create(['Title' => 'Nadzor e-Uprave (demo)', 'Code' => self::GROUP_PREFIX . 'nadzor']);
            $group->write();
            foreach ([ApplicationRequest::PERMISSION_SUPERVISOR, ApplicationRequest::PERMISSION_REPORTS, ApplicationRequest::PERMISSION_AUDIT] as $code) {
                Permission::grant($group->ID, $code);
            }
        }
        return $group;
    }

    private function ensureCadastralMunicipality(): void
    {
        if (!CadastralMunicipality::get()->filter('IsActive', true)->exists()) {
            CadastralMunicipality::create(['Title' => 'Srebrenik', 'Survey' => CadastralMunicipality::SURVEY_NEW, 'IsActive' => true])->write();
            $this->output->writeln('<comment>Nema katastarskih općina – kreirana k.o. „Srebrenik“ (novi premjer). Dopunite listu u Settings → e-Uprava.</comment>');
        }
    }

    private function preflightTextExtraction(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'egovdemo') ?: '';
        try {
            file_put_contents($path, $this->pdf->render('<p>Vrsta prihoda: 722131 Općina: 084</p>'));
            $text = Injector::inst()->get(ChainTextExtractor::class)->extractFromPath($path, 'application/pdf');
            if (!str_contains($text, '722131')) {
                $this->output->writeln('<comment>Tekst iz PDF-a nije moguće pročitati (nema smalot/pdfparser ni pdftotext). Provjera uplatnica će biti 🟡 „nečitko“ – instalirajte poppler-utils za puni demo.</comment>');
            }
        } catch (Throwable $e) {
            $this->output->writeln('<comment>Provjera čitanja PDF-a nije uspjela: ' . $e->getMessage() . '</comment>');
        } finally {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }
    }

    // =====================================================================
    // Removal
    // =====================================================================

    private function removeDemoData(): void
    {
        $requests = ApplicationRequest::get()->filter('InternalNote:StartsWith', self::MARKER);
        $codes = [];
        $count = 0;
        foreach ($requests as $request) {
            $codes[] = (string) $request->TrackingCode;
            foreach ($request->Attachments() as $attachment) {
                $file = $attachment->File();
                if ($file->exists()) {
                    $file->deleteFile();
                    $file->delete();
                }
            }
            $request->delete(); // cascades attachments, history, supplement items
            $count++;
        }

        $members = Member::get()->filter('Email:EndsWith', '@' . self::EMAIL_DOMAIN);
        $memberIds = array_map('intval', $members->sort(null)->column('ID'));

        $audit = AuditLogEntry::get();
        $audit = $codes !== [] || $memberIds !== []
            ? $audit->filterAny(array_filter(['TrackingCode' => $codes ?: null, 'MemberID' => $memberIds ?: null]))
            : $audit->filter('ID', 0);
        $auditCount = $audit->count();
        foreach ($audit as $entry) {
            $entry->delete();
        }

        $memberCount = $members->count();
        foreach ($members as $member) {
            $member->delete();
        }
        foreach (Group::get()->filter('Code:StartsWith', self::GROUP_PREFIX) as $group) {
            foreach (MunicipalService::get() as $service) {
                $service->Groups()->remove($group);
            }
            $group->delete();
        }

        $this->output->writeln(sprintf('Obrisano: %d demo predmeta, %d revizijskih zapisa, %d demo korisnika.', $count, $auditCount, $memberCount));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Move the clock to "N days ago", 08:15 + 25 min per step, skipping weekends,
     * never into the future and always after the previous step of the case.
     */
    private function mockTime(int $daysAgo, int $index, int $total): void
    {
        $day = (new \DateTimeImmutable('@' . $this->realNow))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->modify(sprintf('-%d days', $daysAgo));
        if ($daysAgo > 0) {
            $dow = (int) $day->format('N');
            if ($dow >= 6) {
                $day = $day->modify(sprintf('-%d days', $dow - 5));
            }
        }
        $ts = $day->setTime(8, 15)->getTimestamp() + $index * 25 * 60;
        if ($ts >= $this->realNow) {
            $ts = $this->realNow - ($total - $index) * 120;
        }
        $ts = max($ts, $this->lastTs + 60);
        $ts = min($ts, $this->realNow);
        $this->lastTs = $ts;
        DBDatetime::set_mock_now(date('Y-m-d H:i:s', $ts));
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp());
    }

    private function nextProtocolNumber(ApplicationRequest $request): string
    {
        $service = (string) $request->Procedure()->Service()->Code;
        $year = date('y', DBDatetime::now()->getTimestamp());
        $this->protocolSeq[$service] ??= 900;
        do {
            $number = sprintf('%s-%d/%s', $service, ++$this->protocolSeq[$service], $year);
        } while (ApplicationRequest::get()->filter('ProtocolNumber', $number)->exists());
        return $number;
    }

    private function document(ProcedureDefinition $procedure, string $key): RequiredDocument
    {
        $doc = $procedure->RequiredDocuments()->filter('DocumentKey', $key)->first();
        if (!$doc instanceof RequiredDocument) {
            throw new RuntimeException(sprintf('Procedura %s nema dokument „%s“ (preset izmijenjen?).', $procedure->Code, $key));
        }
        return $doc;
    }

    /**
     * Fictional individual with a checksum-valid JMBG.
     *
     * @return array<string, string>
     */
    private function person(string $first, string $last, string $sex, string $birth, string $parent, bool $email = true): array
    {
        $street = $this->pick(self::STREETS) . ' ' . mt_rand(1, 60);
        return [
            'type' => ApplicantType::Individual->value,
            'name' => $first . ' ' . $last,
            'parent' => $parent,
            'id' => $this->jmbg($birth, $sex),
            'address' => $street,
            'city' => 'Srebrenik',
            'phone' => sprintf('06%d %03d %03d', mt_rand(0, 2), mt_rand(100, 999), mt_rand(100, 999)),
            'email' => $email ? sprintf('%s.%s.%d@%s', $this->ascii($first), $this->ascii($last), mt_rand(10, 99), self::EMAIL_DOMAIN) : '',
            'representative' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function company(string $name, string $representative): array
    {
        $digits = '4' . str_pad((string) mt_rand(200000000, 299999999), 11, '0', STR_PAD_LEFT);
        return [
            'type' => ApplicantType::Company->value,
            'name' => $name,
            'parent' => '',
            'id' => $digits . $this->checksum($digits),
            'address' => 'Industrijska zona bb',
            'city' => 'Srebrenik',
            'phone' => '035 000 000',
            'email' => sprintf('ured.%s@%s', $this->ascii(strtok($name, ' ') ?: 'firma'), self::EMAIL_DOMAIN),
            'representative' => $representative,
        ];
    }

    private function jmbg(string $birth, string $sex): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $birth));
        $base = sprintf('%02d%02d%03d%02d', $d, $m, $y % 1000, 18);
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $serial = $sex === 'F' ? mt_rand(500, 999) : mt_rand(0, 499);
            $digits = $base . sprintf('%03d', $serial);
            $check = $this->checksum($digits);
            if ($check !== '') {
                return $digits . $check;
            }
        }
        throw new RuntimeException('JMBG nije generisan.');
    }

    /** Mod-11 control digit ('' when the combination is invalid). */
    private function checksum(string $twelve): string
    {
        $weights = [7, 6, 5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += (int) $twelve[$i] * $w;
        }
        $m = 11 - ($sum % 11);
        return match (true) {
            $m === 10 => '',
            $m === 11 => '0',
            default => (string) $m,
        };
    }

    /**
     * @template T
     * @param list<T> $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    private function ascii(string $value): string
    {
        $map = ['č' => 'c', 'ć' => 'c', 'ž' => 'z', 'š' => 's', 'đ' => 'dj', 'Č' => 'c', 'Ć' => 'c', 'Ž' => 'z', 'Š' => 's', 'Đ' => 'dj'];
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower(strtr($value, $map)));
    }

    private function randomPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return 'Demo-' . $out . '!7';
    }

    private function printSummary(string $password): void
    {
        $o = $this->output;
        $o->writeln('');
        $o->writeln(sprintf('<info>Kreirano %d demo predmeta. Emailovi nisu slani (%d preskočeno).</info>', count($this->created), (int) ($this->silentNotifications->count ?? 0)));
        $o->writeln('');
        $o->writeln('Demo korisnici (lozinka za sve: ' . $password . '):');
        foreach (self::STAFF as $alias => $row) {
            $o->writeln(sprintf('  %-18s %-40s %s', $row['first'] . ' ' . $row['last'], $this->staff[$alias]->Email, !empty($row['supervisor']) ? 'nadzor (izvještaji, revizija)' : 'referent službi ' . implode(', ', $row['services'])));
        }
        $o->writeln('');
        $o->writeln('Predmeti za prezentaciju:');
        foreach ($this->created as $key => $request) {
            if (str_starts_with($key, 'historija-')) {
                continue;
            }
            $o->writeln(sprintf(
                '  %-24s %s  %-10s %-22s JMBG/ID %s  %s',
                $key,
                $request->RegistryCode,
                $request->TrackingCode,
                $request->getStatusLabel(),
                $request->ApplicantIdentificationNumber,
                $request->ProtocolNumber ?: '—'
            ));
        }
        $supplement = $this->created['dopuna-u-toku'] ?? null;
        if ($supplement instanceof ApplicationRequest) {
            $o->writeln('');
            $o->writeln('Link građanina za dopunu (dopuna-u-toku): ' . $supplement->getAbsolutePortalLink('predmet'));
        }
        if (\YouWillLikeIT\EGov\Security\EmailOtpService::singleton()->isEnabled()) {
            $o->writeln('');
            $o->writeln('<comment>Kod za prijavu (OTP) je uključen – kodovi za demo korisnike idu na @' . self::EMAIL_DOMAIN . ' (vidljivo samo u mail catcheru). Za prezentaciju: EmailOtpService.enabled: false.</comment>');
        }
    }
}
