# silverstripe-egov – stanje projekta i nastavak razvoja

> Sažetak svega što je definisano i napisano do 19.10.2026. Služi za nastavak testiranja i razvoja
> lokalno (VS Code / Claude Code). Detalji za korisnike/administratore su u `README.md`, scenarij
> prezentacije u `docs/PREZENTACIJA.md`.

---

## 1. Šta je ovo

Modularni e-Gov sistem za općine u BiH (FBiH, prvo Tuzlanski kanton), fokus na manje
općine/gradove (Srebrenik, Lukavac, Banovići …). **Jedna instalacija = jedna općina.** Pilot: **Grad Srebrenik**.

| | |
|---|---|
| Paket | `youwilllikeit/silverstripe-egov` (`type: silverstripe-vendormodule`, licenca proprietary) |
| Namespace | `YouWillLikeIT\EGov\` → `src/` (testovi `YouWillLikeIT\EGov\Tests\` → `tests/php/`) |
| Repo | `https://github.com/you-will-like-it/silverstripe-egov` (lokalni commit `7e7f07c` na `main`, **još nije pushovan**) |
| Platforma | Silverstripe CMS **^6.2**, PHP **^8.3**, `declare(strict_types=1)` svuda |
| Jezik | Kod engleski; CMS, portal, emailovi, PDF bosanski (ijekavica, FBiH terminologija) |
| Zavisnosti | framework ^6.2, admin ^3.2, asset-admin ^3.2, assets ^3.2, siteconfig ^6.1, mimevalidator ^4.0, **multiform (vlastiti fork, 4.x-dev)**, **kalakotra/silverstripe-aigateway**, mpdf ^8.2; suggest `smalot/pdfparser` |
| Frontend | Vite 6 + Tailwind 4 + Alpine 3, build u `client/dist` (`egov.css`, `egov.js`, `egov-admin.css`), `extra.expose: client/dist` |

---

## 2. Ključne odluke (dogovoreno)

**Uloge i kanali**
- Građani/pravna lica koriste **samo portal** `/e-uprava` – **bez logina**. Email služi za double-opt-in i obavještenja o statusu (samo email, bez SMS-a).
- Status se provjerava na portalu: **kod predmeta + JMBG/ID** podnosioca (rate limit).
- **Referenti rade isključivo u CMS-u.** Referent vidi **samo svoju službu**; može biti član više službi (grupe).
- **Šalter (prijem u CMS-u):** broj protokola **obavezan**, email **opcionalan**; štampa se PDF zahtjeva za potpis i **Potvrda o prijemu** (kod predmeta + adresa za praćenje).
- **Broj protokola je ručni** (unosi se iz postojeće knjige/sistema).

**Obrada i zaključavanje**
- „**Pokreni obradu**“ može kliknuti bilo koji referent službe → predmet zaključan na njega („🔒 U obradi – Ime“), ostali read-only.
- Odluke donosi **samo referent koji obrađuje**. **Nadzor** (supervisor) može otključati u svakom trenutku, ali **ne može završiti/odlučiti**.
- Odlazak na dopunu / čekanje uplate **otključava** predmet; po dopuni ga preuzima bilo koji referent službe.
- Prosljeđivanje drugoj službi: **nije u MVP-u**. Grupne radnje: **nisu u MVP-u**.

**Dopuna („anti-šikaniranje“)**
- Prema građaninu **nema slobodnog teksta**. Samo dokumenti iz kataloga procedure + razlog sa **zatvorene liste**:
  `Missing` (samo za nepriložen), `Illegible`, `WrongDocument`, `Expired`, `WrongPayment` (samo za dokaz o uplati).
- Interne napomene dozvoljene (vidi samo služba).
- Rok za dopunu default 8 dana; ZUP rok rješavanja **miruje** tokom dopune i **kreće ispočetka** nakon dopune.
- Istek roka za dopunu → **Odbačen** („smatra se nepodnesenim“): ručno dugme; **cron auto-odbacivanje opcionalno** (config, default isključeno).

**Odluke / statusi**
- Razdvojeno **Odbijen (Rejected)** – meritorno, obrazloženje u rješenju (ne ide emailom) i **Odbačen (Dismissed)** – razlog sa zatvorene liste: `SupplementNotProvided`, `NotCompetent`, `Withdrawn`, `Untimely`.
- Upload izdatog akta: **opcionalan** (`require_result_document_for_pickup: false`) – odlučiti s referentima.
- Original akta se **preuzima lično**; pri preuzimanju stranka potpisuje PDF zahtjeva.

**Rokovi** – default po ZUP FBiH, mijenjaju se po proceduri u YAML-u: Simple 30, Examination 60, Certificate 5, dopuna 8 dana; neradni dan → sljedeći radni dan; praznici FBiH u configu.

**Tekstovi** – svi emailovi uredivi u CMS-u (Uvod / Pravna napomena / Završetak). Pravna napomena je
„**polufiksna**“: uređuje je pravna služba općine (`EGOV_CONFIG`), ne referent po predmetu.

**AI** – *nice-to-have*. Ide preko **AIGateway** (tekst-only `ask()`), uključuje se **po proceduri** (`AiCheckEnabled`, default off).
Provjera uplatnice (račun, vrsta prihoda, šifra općine, budžetska org., iznos) je **deterministička i uvijek uključena**.
JMBG i email se maskiraju prije slanja AI-ju.

**Sigurnost**
- Umjesto zajedničke dnevne htpasswd lozinke: **lični jednokratni kod na email** (OTP) nakon lozinke + **IP ograničenje** na web serveru (nginx/Apache primjer u README §3.2; opcionalno i htpasswd).
- Lock-out nakon 5 pogrešnih lozinki (15 min), session timeout 60 min.
- Revizijski trag (append-only, ne briše se s predmetom).
- Prilozi su protected asseti; preuzimanje samo kroz `SecureFileController` (assets 3.2 / CVE-2026-24749).

**Izvještaji** – obavezni, **PDF po granama**: mjeseci, službe, procedure, referenti, ili kompletan.

**Retencija** – cron za brisanje starih predmeta dolazi **kasnije**; `cascade_deletes` je definisan (prilozi, historija, stavke dopune; audit izuzet).
Predmeti s brojem protokola se nikad ne brišu; brišu se samo nepotvrđeni portal-nacrti bez protokola (14 dana).

---

## 3. Arhitektura

### 3.1 Model podataka

```
ApplicationRequest (root, EGov_ApplicationRequest)  ← sve zajedničko: meta predmeta, podnosilac,
 │                                                      lokacija, FormPayload (JSON), hash/kod,
 │                                                      lock (AssignedTo), rokovi, AI agregat
 ├─ UrbanConsentRequest        (08-01; ObjectType = varijanta takse, površina, spratnost)
 ├─ CadastralPlanCopyRequest   (03-38; ParcelCount → 11 KM + 4 KM/parcela, Purpose)
 └─ (jednostavne procedure ostaju na root klasi + YAML payload_fields)

 has_many: ApplicationAttachment (Kind: Citizen | Staff | Result; semafor, izvještaj provjere)
           ApplicationStatusChange (historija; ActorType citizen/staff/system; CitizenNotified)
           SupplementItem (RequiredDocument + SupplementReason + Round + Member)
 has_one:  ProcedureDefinition, MunicipalService, CadastralMunicipality (novi/stari premjer),
           AssignedTo (Member), IntakeBy (Member)

ProcedureDefinition ── FeeItem (račun, vrsta prihoda, iznos/raspon, AdditionalUnitAmount, VariantKey)
                    └─ RequiredDocument (DocumentKey, IsMandatory, IsPaymentProof, ObtainedExOfficio, AiHint)
MunicipalService ── many_many Groups (referenti)
PaymentAccount, CadastralMunicipality, NotificationTemplate, AuditLogEntry (append-only, snapshot polja)
```

Extension pointi podklasa: `getProcedureFormFields()`, `getProcedureRequiredFields()`,
`saveProcedureFormData()`, `getProcedureSummary()`, `getFeeVariantKey()`, `getFeeUnitCount()`,
`getSubjectLine()`, `getPdfIntroText()`.

### 3.2 Status mašina (`Enum\RequestStatus` + `Workflow\ApplicationWorkflow`)

```
Portal: Draft ─(email potvrda)─┐
Šalter: (prijem, protokol) ────┴─► Submitted ─[Pokreni obradu]─► InProcess (🔒 referent)
InProcess ─► Incomplete | AwaitingPayment | ReadyForPickup | Completed | Rejected | Dismissed
Incomplete/AwaitingPayment ─(dopuna)─► Submitted      ─(rok istekao)─► Dismissed
ReadyForPickup ─(preuzimanje, bilo koji referent službe)─► Completed
```

- `ApplicationWorkflow` je **jedini** put za promjenu `Status` (`onBeforeWrite` baca `LogicException` inače; `applyStatusFromWorkflow()`).
- Preduslovi u `assertPreconditions()`: prava po statusu (`canStartProcessing/canDecide/canRegisterPickup/canDismissExpiredSupplement`), broj protokola za `require_protocol_for`, potvrđen email za portal, validacija stavki dopune, razlog odbacivanja.
- `TransitionContext` (actorType, member, internalNote, supplementItems, supplementDays, dismissalReason, rejectionNote, notifyCitizen) + `TransitionException`.
- Javne metode: `requestConfirmation`, `confirmSubmission`, `submitSupplement`, `registerCounterIntake`, `startProcessing`, `release`, `unlock`, `registerPickup`, `dismissExpiredSupplement`, `transition`.

### 3.3 Dozvole

| Kod | Ko | Šta |
|---|---|---|
| `EGOV_REFERENT` | grupa po službi (vezana u *Službe*) | inbox svoje službe, obrada, šalter |
| `EGOV_SUPERVISOR` | nadzor | vidi sve, otključava, ne odlučuje |
| `EGOV_REPORTS` | nadzor/rukovodstvo | PDF izvještaji |
| `EGOV_AUDIT` | nadzor | revizijski trag |
| `EGOV_CONFIG` | admin e-Uprave / pravna služba | procedure, takse, tekstovi |

Referentima **ne** davati `CMS_ACCESS_LeftAndMain`.

### 3.4 Rute

| Ruta | Controller | Napomena |
|---|---|---|
| `/e-uprava` | `PortalController` | katalog, `zahtjev/$Code` (multiform), `predmet/$Hash` (upload/dopuna), `potvrda/$Token` (double-opt-in) |
| `/e-uprava/status` | `StatusController` (preko `RequestHandlerMiddlewareAdapter` + `RateLimitMiddleware` 10/min) | kod + JMBG |
| `/egov-datoteke` | `SecureFileController` | `prilog/$ID`, `zahtjev-pdf/$ID`, `potvrda-pdf/$ID`, `upload/$ID` (staff upload) |
| `/egov-izvjestaji` | `ReportController` | PDF izvještaji (CMS meni link iz `_config.php`) |
| `/egov-prijava` | `EmailOtpController` | unos OTP koda |
| `admin/e-zahtjevi` | `ApplicationRequestAdmin` | inbox referenta |
| `admin/e-uprava-postavke` | `EGovSettingsAdmin` | procedure, službe, tekstovi |
| `admin/e-uprava-revizija` | `AuditLogAdmin` | revizija |

### 3.5 Struktura repozitorija

```
_config.php                     CMSMenu link za izvještaje
_config/
  egov.yml                      SiteConfig ext, Injector (PdfRenderer, extractori, AIGateway), upload limiti,
                                workflow (require_protocol_for, require_result_document_for_pickup),
                                notify_citizen_on, DeadlineMonitorTask, AuditLogger, OCR
  routes.yml                    rute + rate limit statusa
  security.yml                  OTP middleware (After #coresecurity), lock-out, EmailOtpService, session timeout
  deadlines.yml                 ZUP rokovi, praznici, procedure_overrides
  procedures.yml                ProcedureRegistry (portal_url_segment: e-uprava, procedures: {})
  notification-templates.yml    default tekstovi (NotificationTemplate.defaults_by_key)
presets/srebrenik.yml           preset općine (kopira se u app/_config/egov-preset.yml)
src/
  Admin/        ApplicationRequestAdmin, ApplicationRequestItemRequest (CMS akcije), RequestFieldsBuilder,
                EGovSettingsAdmin, AuditLogAdmin
  Controller/   PortalController, StatusController, SecureFileController
  Enum/         RequestStatus, ApplicantType, VerificationLight, DeadlineCategory, DismissalReason, SupplementReason
  Form/         RequestMultiForm, Step/{Abstract,Applicant,Location,Details,Review}Step, Field/IdentificationNumberField
  Model/        (vidi 3.1)
  Pdf/          PdfRendererInterface, MpdfRenderer (DejaVu), RequestPdfBuilder (zahtjev, potvrda)
  Procedure/    ProcedureRegistry, PayloadFieldFactory (YAML payload polja)
  Report/       ReportBuilder, ReportController
  Security/     EmailOtpService, EmailOtpMiddleware, EmailOtpController, EGovMemberExtension (login audit)
  Service/      DeadlineCalculator, NotificationService, AttachmentUploadService, AuditLogger, IdentifierGenerator
  Task/         SeedCatalogueTask, VerifyAttachmentsTask, DeadlineMonitorTask, DemoDataTask
  Tenant/       TenantConfig, TenantSiteConfigExtension (Settings → e-Uprava)
  Validation/   IdentificationNumberValidator (JMBG mod-11 + datum; ID pravnog lica počinje s 4)
  Verification/ AiDocumentVerificationService, PaymentSlipParser/Data, SupplementNoticeBuilder,
                CheckResult, VerificationResult, TextExtraction/{Chain,Pdf,TesseractOcr}Extractor, ProcessRunner
  Workflow/     ApplicationWorkflow, TransitionContext, TransitionException
templates/YouWillLikeIT/EGov/   Portal/*, Email/*, Pdf/*, Admin/*, Includes/ReviewSummary
client/                         Vite izvor + dist
tests/php/                      5 test klasa, 32 testa + ApplicationWorkflowTest.yml fixture
docs/                           PREZENTACIJA.md, HANDOFF.md
```

### 3.6 Taskovi (sake)

| Komanda | Šta | Kada |
|---|---|---|
| `tasks:egov-seed [--update] [--reset-templates]` | uvoz preseta (službe, računi, k.o., procedure, takse, dokumenti) + tekstovi | instalacija / izmjena kataloga |
| `tasks:egov-verify-attachments --limit=20` | provjera priloga (pravila + opcionalno AI) | cron `*/5 * * * *` |
| `tasks:egov-deadlines` | dnevni pregled rokova službama, opcionalno auto-odbacivanje, brisanje starih nacrta | cron `0 7 * * 1-5` |
| `tasks:MultiFormPurgeTask` | čišćenje multiform sesija | cron `30 3 * * *` |
| `tasks:egov-demo [--reset] [--remove] [--history=N] [--password=…] [--seed=N]` | demo podaci (ne radi u `live`) | prezentacija/obuka |

### 3.7 Silverstripe 6.2 specifičnosti koje kod poštuje

`SilverStripe\Model\List\ArrayList`, `SilverStripe\Model\ArrayData`, `SilverStripe\Core\Validation\{ValidationResult,ValidationException}`,
`SilverStripe\Forms\Validation\RequiredFieldsValidator`, `Extension` umjesto `DataExtension`,
`BuildTask::execute(InputInterface, PolyOutput): int` + `protected static string $commandName`,
`FormField::validate(): ValidationResult`, `getValue()` (ne `Value()`), `Controller::curr()` (nema `has_curr`),
`FormAction::setIcon()`, bez `getIDList()` (→ `sort(null)->column('ID')`), bez `<% base_tag %>`,
`ModelAdmin::$showImportForm` mora biti `public`, `new ValidationException(...)` (ne `::create`).

---

## 4. Preset Srebrenik (`presets/srebrenik.yml`)

- Tenant: Grad Srebrenik, šifra općine **084**, prefiks koda predmeta **SRB**, lokativ „Srebreniku“.
- Službe: **03** geodetske i imovinsko-pravne, **05** opća uprava, **06** poduzetništvo, **08** prostorno uređenje.
- Računi: `1321500299280097` (gradske adm. takse), `1321000256000080` (naknada premjera i katastra).
- Procedure:
  - **08-01 Urbanistička saglasnost** – `UrbanConsentRequest`, Examination (60 d), lokacija; takse po varijanti: Residential 70–200, ResidentialCommercial 100–300, Commercial **0 = PROVJERITI**; vrsta prihoda 722131; dokumenti: kopija kat. plana, dokaz o uplati, idejni projekat.
  - **03-38 Kopija katastarskog plana** – `CadastralPlanCopyRequest`, Simple; 11 KM + 4 KM/parcela; 722515.
  - **05-16 Izdavanje dokumenata iz arhive** – root klasa + YAML polja (`RequestedDocument`, `Purpose`); 10 KM; rok override 10 dana; račun **PROVJERITI**.
- Katastarske općine: prazno (unijeti).

---

## 5. Trenutno stanje

### Urađeno
- Kompletan modul (72 PHP klase, 17 templatea, YAML, frontend build, testovi, README, scenarij prezentacije, demo task).
- Provjere u izolovanom okruženju (bez Packagista):
  - `php -l` svih fajlova ✔
  - PHPStan level 5 protiv izvora SS 6.2 ✔ (preostali nalazi benigni: config statike „never read“, `DBField` vs `DBHTMLText` return tipovi, magic relacije SiteConfig-a)
  - Svi templatei kompajliraju SS6 template parserom ✔
  - Stub harness čiste logike: 34 provjere ✔
  - Generator JMBG-ova u demo tasku: 2000 primjera prolazi validator ✔
  - YAML validan ✔

### NIJE urađeno (prvo na redu lokalno)
- **Nijednom pokrenut na pravoj SS6 instalaciji** (`composer install`, `db:build`).
- **PHPUnit nije izvršen.**
- Nema klik-testa CMS-a i portala u browseru.
- Kod nije pushovan na GitHub (GitHub nije bio povezan u cloud sesiji).

### Rizična mjesta – provjeriti prva
1. **`ApplicationRequestItemRequest`** – custom akcije na detail formi, `saveFormIntoRecord` override (ClassName swap kod prijema na šalteru preko `ProcedureDefinition::getRequestClassName()`), ponovno omogućavanje akcija na readonly formi (`ALWAYS_ENABLED`).
2. **Multiform fork** – `RequestMultiForm` nadjačava `prev()` (upstream koristi najnoviji korak) i `getNextStep()`; potpisi forka moraju odgovarati SS6 4.x-dev API-ju.
3. **`EmailOtpMiddleware`** – redoslijed Director middlewarea (`After: '#coresecurity'`).
4. **Rate-limited status ruta** (`RequestHandlerMiddlewareAdapter` kao Director rule).
5. **`SecureFileController`** – streaming protected fajlova (assets 3.2), PDF inline bez CSP sandboxa.
6. **`AttachmentUploadService::storeGenerated()`** (novo, za demo task) – `File::setFromString()` sa `visibility: protected` + `Folder::find_or_make()`.
7. **`DemoDataTask`** – `DBDatetime::set_mock_now()`, `Member::changePassword()`, `Permission::grant()`, anonimna podklasa `NotificationService` za utišane emailove.
8. **`phpunit.xml.dist`** – bootstrap `../../silverstripe/framework/tests/bootstrap.php` važi samo kad je modul u `vendor/youwilllikeit/`. Kod path-repository **symlinka** pokretati testove iz root-a projekta (vidi §6.4).
9. Tesseract/pdftotext nisu obavezni – bez njih provjera uplatnica daje 🟡 „nečitko“.

---

## 6. Lokalno postavljanje

### 6.1 Projekat

```bash
composer create-project silverstripe/installer egov-srebrenik ^6.2
cd egov-srebrenik
```

`composer.json` projekta:

```json
"repositories": [
    { "type": "path", "url": "../silverstripe-egov", "options": { "symlink": true } },
    { "type": "vcs",  "url": "git@github.com:<VAŠ-ORG>/silverstripe-multiform.git" },
    { "type": "vcs",  "url": "git@github.com:kalakotra/silverstripe-aigateway.git" }
],
"require": {
    "youwilllikeit/silverstripe-egov": "@dev",
    "silverstripe/multiform": "dev-<grana-forka> as 4.0.x-dev",
    "kalakotra/silverstripe-aigateway": "dev-main"
},
"require-dev": { "phpunit/phpunit": "^11.5" }
```

```bash
composer update
cp vendor/youwilllikeit/silverstripe-egov/presets/srebrenik.yml app/_config/egov-preset.yml
vendor/bin/sake db:build --flush
vendor/bin/sake tasks:egov-seed
```

### 6.2 `.env` (dev)

```dotenv
SS_ENVIRONMENT_TYPE="dev"
SS_DATABASE_CLASS="MySQLDatabase"
SS_DATABASE_SERVER="127.0.0.1"
SS_DATABASE_USERNAME="root"
SS_DATABASE_PASSWORD=""
SS_DATABASE_NAME="egov_srebrenik"
SS_DEFAULT_ADMIN_USERNAME="admin"
SS_DEFAULT_ADMIN_PASSWORD="admin"
MAILER_DSN="smtp://127.0.0.1:1025"     # Mailpit
```

### 6.3 Dev config (`app/_config/dev.yml`)

```yaml
---
Name: app-egov-dev
Only:
  environment: dev
---
YouWillLikeIT\EGov\Security\EmailOtpService:
  enabled: false            # ili ostaviti true i čitati kodove u Mailpitu
```

Alati: `apt install poppler-utils tesseract-ocr tesseract-ocr-bos tesseract-ocr-hrv tesseract-ocr-srp-latn`
(ili `composer require smalot/pdfparser` za PDF tekst bez binarija).

### 6.4 Testovi

Root `phpunit.xml.dist` projekta (preporučeno, radi i sa symlinkom):

```xml
<phpunit bootstrap="vendor/silverstripe/framework/tests/bootstrap.php" colors="true">
    <testsuites>
        <testsuite name="egov"><directory>vendor/youwilllikeit/silverstripe-egov/tests/php</directory></testsuite>
    </testsuites>
</phpunit>
```

```bash
vendor/bin/phpunit --testsuite egov
vendor/bin/phpunit --filter ApplicationWorkflowTest
```

SapphireTest kreira privremenu bazu – korisnik iz `.env` mora imati CREATE DATABASE.

| Test | Pokriva |
|---|---|
| `IdentificationNumberValidatorTest` | JMBG kontrolna cifra, datum, ID pravnog lica |
| `PaymentSlipParserTest` | račun 3-3-4-3-3, vrsta prihoda, općina, iznos, OCR greške O/l/I |
| `RequestStatusTest` | dozvoljeni prelazi, terminalni statusi, suspenzija roka |
| `DeadlineCalculatorTest` | radni dani, praznici, override po proceduri |
| `ApplicationWorkflowTest` (+ `.yml`) | double-opt-in, lock (drugi referent ne odlučuje, nadzor otključava ali ne odlučuje), zatvorena dopuna, restart roka, odbacivanje, šalter (protokol obavezan, email opcionalan) |

### 6.5 Demo i ručni test

```bash
vendor/bin/sake tasks:egov-demo --reset --password='Demo-2026!'
```

Kreira 4 korisnika (`amra.hodzic@`, `emir.mehic@`, `selma.begic@`, `nermin.salihovic@demo.e-uprava.test`),
15 scenarij-predmeta + 30 historijskih (za izvještaje), bez slanja emailova. Ispis sadrži kodove predmeta,
JMBG-ove i link za dopunu. Brisanje: `--remove`. Demo marker: interna napomena `[DEMO]`, grupe `egov-demo-*`.

Checklist ručnog testa (redom):
1. Portal: katalog → 03-38 → JMBG validacija → izračun takse → upload → email potvrda (Mailpit) → status stranica (kod + JMBG).
2. CMS (Amra): inbox filtrira samo službu 08; Pokreni obradu; unos protokola; Provjeri priloge; Potrebna dopuna (zatvorena lista) → email.
3. Lock: Emir otvara predmet koji Amra obrađuje → read-only; Nermin otključava; Nermin nema „Primijeni odluku“.
4. Šalter (Emir): Prijem na šalteru (03-38, bez emaila) → PDF zahtjeva + Potvrda o prijemu.
5. Građanin: link `predmet/$Hash` → dopuna → predmet ponovo „Podnesen“, rok restartovan.
6. `dopuna-istekla` → „Odbaci – rok za dopunu istekao“; `spreman-za-preuzimanje` → „Evidentiraj preuzimanje“.
7. Izvještaji (Nermin): PDF po granama. Revizija: pregledi, JMBG pretrage, prijave.
8. OTP uključen: prijava → kod u Mailpitu → pristup `/admin`, `/egov-datoteke`, `/egov-izvjestaji`.
9. `tasks:egov-deadlines` i `tasks:egov-verify-attachments` ručno.

---

## 7. Kako dodati novu proceduru

**A) Samo CMS** (većina uvjerenja/potvrda): *e-Uprava: postavke → Procedure*: šifra `SS-NN`, služba,
Tip zahtjeva = „Zahtjev (opći)“, kategorija roka, „Zahtijeva lokaciju“, takse (račun, vrsta prihoda,
iznos/raspon, po jedinici, VariantKey), potrebni dokumenti, „Dostupna na portalu“.

**B) Dodatna polja bez PHP-a** (`app/_config/egov-preset.yml`, pa `flush`):

```yaml
YouWillLikeIT\EGov\Procedure\ProcedureRegistry:
  procedures:
    '05-17':
      payload_fields:
        Svrha: { type: dropdown, label: 'Svrha uvjerenja', required: true, options: { posao: 'Zaposlenje', sud: 'Sud' } }
        Napomena: { type: textarea, label: 'Napomena', max_length: 2000 }
YouWillLikeIT\EGov\Service\DeadlineCalculator:
  procedure_overrides:
    '05-17': { decision_days: 5 }
```

Tipovi: `text`, `textarea`, `number` (`scale`), `date`, `email`, `dropdown` (`options`), `checkbox`.
Ključevi: `label`, `required`, `description`, `options`, `max_length`. Polja se pojavljuju na portalu, u CMS-u, PDF-u i pregledu.
Za sve općine: dodati u preset (`procedures:` sekcija) i `tasks:egov-seed --update`.

**C) Podklasa** (taksa ovisi o podacima, filtriranje/izvještaji po polju, poseban tekst PDF-a):
`class XRequest extends ApplicationRequest` sa vlastitim `$table_name`, `$db` i potrebnim hookovima
(vidi `UrbanConsentRequest`, `CadastralPlanCopyRequest`), `db:build --flush`, u CMS-u izabrati klasu kao Tip zahtjeva.

---

## 8. Otvoreno / sljedeći koraci

**Odmah (tehnički)**
1. Push na `you-will-like-it/silverstripe-egov`.
2. Lokalna instalacija + `db:build` → popraviti greške koje iskoče (vidi §5 rizična mjesta).
3. PHPUnit zelen.
4. Ručni test po checklisti §6.5 i generalna proba prezentacije (`docs/PREZENTACIJA.md`).

**PROVJERITI s općinom prije produkcije**
1. Pravni osnovi, tarifni brojevi i iznosi (preset iz registra 2012) – uskladiti s važećom Odlukom o administrativnim taksama.
2. Taksa 08-01 za poslovni objekat; račun i vrsta prihoda za 05-16.
3. Lista katastarskih općina, email adrese službi, **fiksna IP adresa** općine.
4. Pravne napomene u tekstovima obavještenja (posljedica propuštanja roka, pouka) – pravna služba.
5. Da li je primjerak akta obavezan za „Spreman za preuzimanje“; da li uključiti auto-odbacivanje.
6. Rok za uvjerenja (5 dana) po službama.

**Kasnije (van MVP-a)**
- Prosljeđivanje predmeta drugoj službi.
- Grupne radnje u inboxu.
- Retencija/brisanje starih predmeta (cron).
- Multimodalna AI provjera (kad AIGateway podrži slike) – `ChainTextExtractor` je za to već pripremljen.
- Šema dodatnih polja u bazi (da ih admin općine uređuje bez deploya).
- Proširenje na druge općine TK (novi preset po općini).

---

## 9. Konvencije za nastavak

- Status mijenjati **isključivo** kroz `ApplicationWorkflow`.
- Novi tekst prema građaninu = novi ključ u `notification-templates.yml` + `NotificationTemplate` (uredivo u CMS-u), nikad hardkodiran slobodan tekst.
- Sve radnje nad ličnim podacima logirati kroz `AuditLogger` (nikad ne smije prekinuti rad).
- Fajlovi građana samo kao protected asseti, preuzimanje samo kroz `SecureFileController` uz `canView()`.
- Konfiguracija po općini ide u preset/YAML ili Settings → e-Uprava, ne u kod.
- UI tekstovi bosanski (ijekavica), kod/komentari engleski.
