# youwilllikeit/silverstripe-egov

e-Gov platforma za općine i gradove u BiH (FBiH / Tuzlanski kanton). Jedna instalacija služi jednoj općini.
Pilot je Grad Srebrenik.

- Silverstripe CMS **^6.2**, PHP **8.3+**, `declare(strict_types=1)` u svakom fajlu
- Kod je na engleskom. CMS, portal, emailovi i PDF su na bosanskom (ijekavica).
- Namespace: `YouWillLikeIT\EGov\` (PSR-4 → `src/`)
- **Referenti rade isključivo u CMS-u.** Portal `/e-uprava` je samo za građane i pravna lica.

---

## 1. Instalacija

### 1.1 Zavisnosti koje nisu na Packagistu

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:<VAŠ-ORG>/silverstripe-multiform.git" },
    { "type": "vcs", "url": "git@github.com:kalakotra/silverstripe-aigateway.git" }
],
"require": {
    "silverstripe/multiform": "dev-<grana-forka> as 4.0.x-dev",
    "kalakotra/silverstripe-aigateway": "dev-main",
    "youwilllikeit/silverstripe-egov": "^1.0"
}
```

`silverstripe/multiform` instalira se iz vlastitog forka. Modul je pisan protiv SS6 API-ja (`MultiForm`,
`MultiFormStep`). Ako fork promijeni potpise, mijenjaju se samo `src/Form/RequestMultiForm.php` i
`src/Form/Step/*`.

### 1.2 Build, preset općine i tekstovi

```bash
composer install
cp vendor/youwilllikeit/silverstripe-egov/presets/srebrenik.yml app/_config/egov-preset.yml
vendor/bin/sake db:build --flush
vendor/bin/sake tasks:egov-seed      # službe, računi, procedure, takse, dokumenti + tekstovi obavještenja
```

Opcije:
- `--update` prepisuje katalog iz preseta.
- `--reset-templates` vraća tekstove emailova na zadane, a izmjene iz CMS-a se gube.

### 1.3 Grupe i dozvole (Security → Groups)

| Grupa | Dozvole | Napomena |
|---|---|---|
| Referenti <služba> (po jedna grupa po službi) | `EGOV_REFERENT`, pristup sekciji „e-Zahtjevi“ | Grupa se veže na službu u *e-Uprava: postavke → Službe*. Jedan referent može biti u više grupa. |
| Nadzor | `EGOV_SUPERVISOR`, `EGOV_REPORTS`, `EGOV_AUDIT` | Vidi sve predmete, može otključati predmet, ali ne donosi odluke |
| Administrator e-Uprave / pravna služba | `EGOV_CONFIG` | Procedure, takse, tekstovi emailova i pravne formulacije |

> Referentima **ne** dodjeljivati `CMS_ACCESS_LeftAndMain` (pristup svim sekcijama).

### 1.4 OCR alati

```bash
apt install poppler-utils tesseract-ocr tesseract-ocr-bos tesseract-ocr-hrv tesseract-ocr-srp-latn
```

### 1.5 Cron

```cron
*/5 * * * *  cd /var/www && vendor/bin/sake tasks:egov-verify-attachments --limit=20
0 7 * * 1-5  cd /var/www && vendor/bin/sake tasks:egov-deadlines
30 3 * * *   cd /var/www && vendor/bin/sake tasks:MultiFormPurgeTask
```

### 1.6 Demo podaci (prezentacija, obuka referenata)

```bash
vendor/bin/sake tasks:egov-demo                          # ~15 scenarij-predmeta + 30 završenih (izvještaji) + 4 demo korisnika
vendor/bin/sake tasks:egov-demo --reset --password='Demo-2026!'
vendor/bin/sake tasks:egov-demo --remove                 # briše sve demo podatke
```

- Task radi samo kad okruženje **nije** `live`. Treba mu preset (`tasks:egov-seed`).
- Svaki predmet prolazi kroz pravi `ApplicationWorkflow` s pomjerenim satom. Zato su historija, rokovi,
  tekstovi dopune i revizijski trag isti kao u stvarnom radu.
- Dok task radi, **ne šalje se nijedan email**.
- Osobe, JMBG-ovi (sa ispravnom kontrolnom cifrom), adrese i dokumenti su izmišljeni. Na svakom PDF-u piše
  „PRIMJER – IZMIŠLJENI PODACI (DEMO)“.
- Demo predmeti imaju internu napomenu `[DEMO]`. Demo korisnici imaju adrese `@demo.e-uprava.test`.
  Demo grupe imaju kod `egov-demo-*`.
- Scenarij prezentacije: [`docs/PREZENTACIJA.md`](docs/PREZENTACIJA.md).

### 1.7 Frontend

Fajlovi su već buildani u `client/dist`. Za rebuild: `cd client && npm install && npm run build`
(Vite + Tailwind 4 + Alpine). Tema može zamijeniti svaki template iz `templates/YouWillLikeIT/EGov/**`.

---

## 2. Tok rada referenta (CMS → e-Zahtjevi)

```
Portal:  Nacrt ─(potvrda emailom)─┐
Šalter:  Prijem (broj protokola) ─┴─► Podnesen ─[Pokreni obradu]─► U obradi – Mujo Mujić 🔒
                                                                     │
        ┌─────────────────────────────────────────────────────────────┼───────────────┐
        ▼                         ▼                                    ▼               ▼
  Potrebna dopuna / Čeka uplatu   Spreman za preuzimanje ─[Preuzimanje]─► Završen   Odbijen / Odbačen
  (otključano; rok miruje)
        │ dopuna stranke → Podnesen (rok kreće ispočetka)
        │ rok istekao    → Odbačen („smatra se nepodnesenim“), ručno ili cron
```

- **Pokreni obradu** može kliknuti svaki referent službe. Predmet se tada zaključava na njega: ostali ga
  vide samo za čitanje, a u listi stoji „🔒 U obradi – Ime Prezime“. Odluke donosi isključivo taj referent.
- **Otpusti predmet** vraća predmet ostalima. **Nadzor** može otključati predmet u svakom trenutku, ali ga
  ne može završiti.
- Kad predmet ode na **dopunu** ili **čeka uplatu**, otključava se. Nakon dopune ga ponovo preuzima bilo
  koji referent te službe.
- **Prijem na šalteru** je dugme „Prijem zahtjeva na šalteru“ u listi. Broj protokola je obavezan, a email
  opcionalan. Nakon prijema štampa se **PDF zahtjeva za potpis** i **Potvrda o prijemu**, koja sadrži kod
  predmeta i adresu za provjeru statusa. Bez emaila stranka ne dobija obavještenja, nego status provjerava
  kodom predmeta i JMBG-om. Referent koji je zaprimio zahtjev može dopunjavati podatke obrasca i
  skenirane priloge sve dok obrada ne počne.
- **Prilozi koje dodaje služba** (skenirani dokumenti stranke i primjerak izdatog akta) dodaju se dugmetom
  „Dodaj skenirani prilog / izdati akt“, koje otvara novu karticu. Izdati akt se uploaduje kao gotov
  Word/PDF fajl, bez skeniranja. Da li je za status „Spreman za preuzimanje“ obavezan primjerak akta,
  podešava se sa `require_result_document_for_pickup` (zadano: ne).

### 2.1 Zatvorena dopuna („anti-šikaniranje“)

- Referent ne piše slobodan tekst. Za svaki dokument **iz kataloga procedure** bira razlog sa fiksne liste:
  - dokument koji nije priložen ima samo razlog „Nije priložen“;
  - priloženi dokument može dobiti „Nečitljiv“, „Pogrešan dokument“, „Istekao rok važenja“, a za dokaz o
    uplati i „Uplata nije prema propisanim podacima“.
- Tekst za stranku sastavlja se iz šablona „Poziv za dopunu“. Šablon ima uvod, listu dokumenata, propisane
  podatke za uplatu i pravnu napomenu općine. Ta verzija teksta sprema se uz predmet.
- Svaka stavka dopune čuva se s imenom referenta. U izvještaju „Po referentima“ prikazani su broj stavki
  dopune i broj odbijanja/odbacivanja.
- Automatska provjera priloga samo **predodabire** stavke, a referent potvrđuje.

### 2.2 Napomene

- **Interna bilješka** i napomena uz promjenu statusa vidljive su samo službi.
- Stranka dobija **isključivo** tekstove iz šablona. Razlog odbijanja nikad ne ide emailom, jer je u
  rješenju. Odbacivanje navodi samo razlog iz zatvorene liste: nedostaci nisu otklonjeni u roku,
  nenadležnost, zahtjev povučen ili neblagovremen.

### 2.3 Rokovi (ZUP FBiH, mijenjaju se po proceduri u YAML-u)

`_config/deadlines.yml`: Simple 30 dana, Examination 60, Certificate 5, dopuna 8 dana. Ako zadnji dan
roka pada u neradni dan, rok ističe idućeg radnog dana.

```yaml
YouWillLikeIT\EGov\Service\DeadlineCalculator:
  procedure_overrides:
    '05-16': { decision_days: 10, supplement_days: 5 }
```

**Istek roka za dopunu.** Zakon ne propisuje fiksan broj dana. Rok određuje organ, a posljedica
propuštanja je da se zahtjev **smatra nepodnesenim**, odnosno odbacuje se. Formulaciju treba potvrditi s
pravnom službom.

**Predmeti koji imaju broj protokola nikad se ne brišu**, nego prelaze u status „Odbačen“. Brišu se samo
nepotvrđeni portal-nacrti bez protokola, i to nakon 14 dana. Automatsko odbacivanje nakon roka za dopunu
uključuje se ovako:

```yaml
YouWillLikeIT\EGov\Task\DeadlineMonitorTask:
  auto_dismiss_expired_supplements: true
  auto_dismiss_grace_days: 3        # npr. zbog dostave poštom
```

---

## 3. Sigurnost

### 3.1 Prijava: lozinka i jednokratni kod na email

Nakon lozinke referent dobija **lični jednokratni kod** na svoj email: 6 cifara, važi 10 minuta, najviše 5
pokušaja, novi kod najranije za 60 sekundi. Kod se traži za `/admin`, `/egov-datoteke` i `/egov-izvjestaji`
(`_config/security.yml`).

Zajednička dnevna lozinka namjerno nije korištena: sa njom se ne bi znalo **ko** se prijavio. Uz kod
vrijedi i zaključavanje naloga nakon 5 pogrešnih lozinki (15 minuta) te odjava nakon 60 minuta
neaktivnosti.

### 3.2 Ograničenje po IP-u (web server)

**nginx** (preporučeno):

```nginx
# /etc/nginx/snippets/egov-office-only.conf
allow 185.12.34.56;        # javna IP adresa općine
allow 10.10.0.0/16;        # interna mreža / VPN
deny  all;

server {
    # ...
    location ^~ /admin           { include snippets/egov-office-only.conf; try_files $uri /index.php?$query_string; }
    location ^~ /Security        { include snippets/egov-office-only.conf; try_files $uri /index.php?$query_string; }
    location ^~ /egov-datoteke   { include snippets/egov-office-only.conf; try_files $uri /index.php?$query_string; }
    location ^~ /egov-izvjestaji { include snippets/egov-office-only.conf; try_files $uri /index.php?$query_string; }
    location ^~ /egov-prijava    { include snippets/egov-office-only.conf; try_files $uri /index.php?$query_string; }
    location ^~ /dev             { deny all; }

    # opcionalno: dodatna statična HTTP lozinka ispred CMS-a
    # location ^~ /admin { include snippets/egov-office-only.conf;
    #     auth_basic "e-Uprava"; auth_basic_user_file /etc/nginx/.htpasswd-egov;
    #     try_files $uri /index.php?$query_string; }
}
```

`htpasswd -c /etc/nginx/.htpasswd-egov sluzba`

**Apache** (`.htaccess` u `public/`):

```apache
<If "%{REQUEST_URI} =~ m#^/(admin|Security|egov-datoteke|egov-izvjestaji|egov-prijava)#">
    Require ip 185.12.34.56 10.10.0.0/16
    # opcionalno:
    # AuthType Basic
    # AuthName "e-Uprava"
    # AuthUserFile /etc/apache2/.htpasswd-egov
    # Require valid-user
</If>
```

> Ako općina ima dinamičku IP adresu ili promijeni provajdera, CMS postaje nedostupan dok se IP ne
> ažurira. Prije uvođenja zatražite fiksnu IP adresu.

### 3.3 Revizijski trag (CMS → e-Uprava: revizija, dozvola `EGOV_AUDIT`)

Bilježi se:
- pregled predmeta (ponovljeni pregled istog predmeta bilježi se najviše jednom u 10 minuta),
- preuzimanje priloga i PDF-ova,
- prijem na šalteru i izmjene podataka,
- promjene statusa, zaključavanje i otključavanje,
- upload priloga,
- pretraga po JMBG-u,
- generisanje izvještaja,
- prijave: uspješne, neuspješne i jednokratni kodovi.

Zapisi se ne mogu mijenjati ni brisati i **ne brišu se zajedno s predmetom**. Ime korisnika i kod predmeta
čuvaju se kao snimak u trenutku zapisa.

### 3.4 Podaci građana

- Prilozi su zaštićeni asseti. Preuzimanje ide samo preko `SecureFileController`, uz provjeru prava po
  službi (CVE-2026-24749 / assets 3.2).
- Status se provjerava kodom predmeta i JMBG-om, uz ograničenje broja pokušaja (rate limit).
- Podaci iz multiform sesije brišu se odmah nakon kreiranja zahtjeva.
- Prije slanja AI-ju JMBG i email se maskiraju.

---

## 4. Uredivi tekstovi (CMS → e-Uprava: postavke → Tekstovi obavještenja)

Svaka poruka ima **Uvod**, **Pravnu napomenu** i **Završetak**. Pravna napomena je „polufiksna“: uređuje je
pravna služba općine (`EGOV_CONFIG`), a referent je ne mijenja po predmetu.

Lista dokumenata, podaci za uplatu i dugmad ubacuju se automatski. Dostupne oznake: `{Ime}`,
`{KodPredmeta}`, `{BrojProtokola}`, `{Procedura}`, `{Sluzba}`, `{Status}`, `{DatumPodnosenja}`,
`{RokRjesavanja}`, `{RokZaDopunu}`, `{BrojDanaDopuna}`, `{RazlogOdbacivanja}`, `{Opcina}`, `{LinkStatusa}`.

---

## 5. Izvještaji (CMS meni → e-Uprava: izvještaji, dozvola `EGOV_REPORTS`)

PDF za izabranu godinu. Mogu se birati pojedinačne grane (mjeseci, službe, procedure, referenti) ili
kompletan izvještaj. Kolone:
- ukupno, portal, šalter,
- završeno, odbijeno, odbačeno, u toku,
- prekoračen rok, riješeno nakon roka, sa dopunom,
- prosječno trajanje,
- kod referenata još i broj stavki dopune te broj odbijanja/odbacivanja.

Nadzor vidi sve službe, referent samo svoje.

---

## 6. Provjera priloga

- **Provjera uplatnice** (račun, vrsta prihoda, šifra općine, budžetska organizacija, iznos) radi po
  fiksnim pravilima i uvijek je uključena.
- **AI prepoznavanje vrste dokumenta** je opcionalno po proceduri (`AiCheckEnabled`, zadano isključeno) i
  ide preko AIGateway-a.
- 🟢 znači „nisu uočeni nedostaci“. To je pomoć referentu, a odluku donosi referent.

---

## 7. Arhitektura modela (kratko)

`ApplicationRequest` je root klasa za sve što je zajedničko svim procedurama. Specifične procedure su
podklase (`UrbanConsentRequest`, `CadastralPlanCopyRequest`). Jednostavna uvjerenja ostaju na root klasi,
uz YAML šemu za dodatna polja.

Povezani modeli:
- `ApplicationAttachment` (izvor: portal, šalter ili izdati akt)
- `ApplicationStatusChange`
- `SupplementItem`
- `AuditLogEntry`
- `NotificationTemplate`
- `ProcedureDefinition` sa `FeeItem` i `RequiredDocument`
- `MunicipalService`, `PaymentAccount`, `CadastralMunicipality`

`cascade_deletes` je definisan za priloge (s fajlovima), historiju statusa i stavke dopune. Revizijski
trag je namjerno izuzet. Cron za brisanje predmeta starijih od X godina dolazi kasnije.

Silverstripe 6 specifičnosti koje modul poštuje:
- `SilverStripe\Model\List\ArrayList`, `SilverStripe\Core\Validation\*`, `RequiredFieldsValidator`
- `Extension` umjesto `DataExtension`
- `BuildTask::execute(InputInterface, PolyOutput)`
- `FormField::validate(): ValidationResult`, `FormField::getValue()`
- `FormAction::setIcon()`
- bez `getIDList()` i `<% base_tag %>`

---

## 8. PROVJERITI prije produkcije

1. Pravni osnovi, tarifni brojevi i iznosi u presetu potiču iz registra procedura iz 2012. Treba ih uskladiti
   s važećom Odlukom o administrativnim taksama.
2. Za 08-01 nije poznata taksa za poslovni objekat, a za 05-16 nisu potvrđeni račun i vrsta prihoda.
3. Nedostaje lista katastarskih općina, email adrese službi i fiksna IP adresa općine.
4. Pravna služba treba odobriti pravne napomene u tekstovima obavještenja: posljedicu propuštanja roka i
   pouku.
5. Sa referentima treba dogovoriti da li je primjerak akta obavezan za status „Spreman za preuzimanje“ i da
   li se uključuje automatsko odbacivanje nakon roka za dopunu.
6. Rok za uvjerenja (5 dana) treba potvrditi za konkretne službe.

## 9. Testovi

```bash
vendor/bin/phpunit vendor/youwilllikeit/silverstripe-egov/tests/php
```

`ApplicationWorkflowTest` pokriva:
- double-opt-in,
- zaključavanje (drugi referent ne može odlučiti; nadzor otključava, ali ne odlučuje),
- zatvorenu dopunu (dokument van kataloga, nedozvoljen razlog),
- ponovni start roka,
- odbacivanje,
- prijem na šalteru (protokol obavezan, email opcionalan).
