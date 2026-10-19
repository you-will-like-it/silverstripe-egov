# Scenarij prezentacije – e-Uprava (pilot Grad Srebrenik)

Prezentacija traje oko **35 minuta** i ima 6 blokova. Publika: gradonačelnik/kabinet, šefovi službi, referenti,
pravna služba i IT. Poruka: **„Ništa se ne mijenja u zakonu i nadležnosti. Mijenja se to da građanin ne dolazi
dva puta, a referent ima sve na jednom mjestu.“**

---

## 0. Priprema (dan prije)

| # | Šta | Komanda / mjesto |
|---|---|---|
| 1 | Svježa baza i preset | `sake db:build --flush` → `sake tasks:egov-seed` |
| 2 | Demo podaci | `sake tasks:egov-demo --reset --password='…'` – **sačuvati ispis** (kodovi predmeta, JMBG-ovi, link za dopunu) |
| 3 | Mail catcher | Mailpit/MailHog: SMTP u `.env`, web UI otvoren u posebnom tabu |
| 4 | OTP za demo isključiti | `app/_config/demo.yml`: `YouWillLikeIT\EGov\Security\EmailOtpService: { enabled: false }` |
| 5 | Grb i podaci općine | *Settings → e-Uprava* (grb, adresa, telefon, email) – vidi se na PDF-u i u emailu |
| 6 | Tri browser profila | **A** Amra (referent 08), **B** Emir (referent 08+03), **N** Nermin (nadzor). Plus prozor *incognito* za građanina |
| 7 | Generalna proba | Proći cijeli scenarij jednom, pa `--reset` |
| 8 | Rezerva | Snimak ekrana (video) cijelog scenarija, za slučaj da mreža ili server zakažu |

> Na probi treba provjeriti uplatnice. Ako pored predmeta `pogresan-racun` piše 🟡 „nečitko“ umjesto 🔴, na
> serveru nedostaje `poppler-utils` (`pdftotext`).

Demo korisnici: `amra.hodzic@`, `emir.mehic@`, `selma.begic@`, `nermin.salihovic@demo.e-uprava.test`.

---

## 1. Problem i cilj (3 min, bez demo)

- Danas građanin dolazi na šalter, pa ga vraćaju po uplatnicu, pa dođe opet po rješenje.
- Referent ne zna koji mu rok ističe. Šef ne zna koliko predmeta kasni.
- Cilj ima tri dijela:
  - zahtjev se podnosi online ili na šalteru, a na isto mjesto stiže;
  - nepotpun zahtjev se vraća **jednom**, sa tačnim spiskom;
  - rokovi po ZUP-u se računaju sami.

## 2. Građanin podnosi zahtjev (7 min) – prozor *incognito*

1. Otvoriti `/e-uprava` i pokazati katalog procedura (takse, potrebni dokumenti, rokovi, pravni osnov).
2. Izabrati **Kopija katastarskog plana** i popuniti formular:
   - JMBG: pokazati da sistem odbija pogrešnu kontrolnu cifru;
   - 2 parcele: pokazati da se taksa sama računa (11 + 4 KM);
   - priložiti PDF.
3. Na koraku **Pregled** pokazati podatke za uplatu.
4. Pošalje se email. U mail catcheru otvoriti „Potvrdite zahtjev“ i kliknuti → zahtjev je podnesen.
   - **Poruka:** nema lažnih zahtjeva, jer svaki zahtjev ima potvrđen email.
5. Pokazati `/e-uprava/status`: unijeti kod predmeta i JMBG → status i tok predmeta.
   - Bez logina i lozinke. Bez ispravnog JMBG-a nema ni podataka.

## 3. Referent – inbox i obrada (10 min) – profil **A** (Amra)

1. Otvoriti **e-Zahtjevi**. Amra vidi **samo službu 08**. Pokazati kolone: status, rok (boja), semafor priloga, kanal.
   - `prekoracen-rok` je crven, jer je prekoračen zakonski rok.
   - Filtriranje: po statusu, proceduri, „Obrađuje“, kanalu.
2. Otvoriti `novi-portal` i kliknuti **Pokreni obradu**. Predmet je sada zaključan na Amru.
   - Unijeti broj protokola (ostaje ručno, kao i danas).
   - Pokazati priloge i izvještaj **Provjeri priloge**: šta je pravilo provjerilo, a šta ostaje za čovjeka.
3. Otvoriti `zakljucan`. Piše „U obradi – Emir Mehić“, a Amra predmet može samo čitati.
   - **Poruka:** dva referenta ne mogu raditi isti predmet.
4. **Dopuna – zatvorena lista („anti-šikaniranje“).** Na `novi-portal`, pod „Novi status“, izabrati **Potrebna dopuna** i kliknuti **Primijeni odluku**:
   - uz svaki dokument razlog iz padajuće liste (nedostaje / nečitko / pogrešan dokument / istekao / pogrešna uplata);
   - **nema slobodnog teksta prema građaninu**, samo interna napomena;
   - u mail catcheru pokazati email sa tačnim spiskom, rokom za dopunu (8 dana) i pravnom poukom.
   - **Poruka:** građanin dobije sve što nedostaje odjednom, a pravnu formulaciju je odredila pravna služba (*e-Uprava: postavke → Tekstovi obavještenja*).
5. Otvoriti `dopuna-istekla` i kliknuti **Odbaci – rok za dopunu istekao**.
   - Rok je istekao, a dugme se pojavi tek kad rok istekne.
   - Automatsko odbacivanje je opcija u konfiguraciji i za pilot je isključeno.
6. Otvoriti `spreman-za-preuzimanje` i kliknuti **Evidentiraj preuzimanje**. Predmet je završen.
   - Otvoriti **PDF zahtjeva (za potpis)**: popunjen obrazac sa potpisnim blokom, koji stranka potpisuje kod preuzimanja.
7. Otvoriti `zavrsen-nakon-dopune` i pokazati **Tok predmeta**: podnesen → obrada → dopuna → dopuna dostavljena → spreman → preuzet.
   - Rok je suspendovan za vrijeme dopune i počinje ponovo nakon dopune (ZUP).

## 4. Šalter i provjera uplatnice (6 min) – profil **B** (Emir)

1. Emir vidi i službu 08 i službu 03, jer jedan referent može biti u više službi.
2. Kliknuti **Prijem zahtjeva na šalteru** i popuniti:
   - procedura 03-38; broj protokola je obavezan; email nije obavezan;
   - priložiti skenirani dokument.
   - Odštampati **Potvrdu o prijemu**: kod predmeta + adresa za praćenje statusa.
   - **Poruka:** stariji građanin bez emaila je ravnopravan.
3. Otvoriti `pogresan-racun`, koji ima 🔴 semafor. Izvještaj provjere pokazuje da je uplaćeno na račun gradskih
   administrativnih taksi, a treba na račun naknade za katastar, i da je pogrešna vrsta prihoda.
   - **Poruka:** račun, vrstu prihoda, šifru općine i iznos provjeravaju fiksna pravila, ne AI. AI je opcija po proceduri i samo pomaže.
4. Otvoriti `ceka-uplatu` (status „Čeka uplatu“): uplaćeno 11 KM umjesto 19 KM za 3 parcele.

## 5. Građanin dostavlja dopunu (3 min) – prozor *incognito*

1. Otvoriti link `dopuna-u-toku` iz ispisa taska (stranica predmeta za građanina). Vidi se tačan spisak
   (nečitak sken kopije plana) i rok.
2. Priložiti novi PDF i kliknuti „Dostavi dopunu“. Predmet se vraća službi i služba dobije email.
3. U profilu **A** predmet je ponovo „Podnesen“.

## 6. Nadzor, izvještaji, revizija (5 min) – profil **N** (Nermin)

1. Nermin vidi sve službe. Otvoriti `zakljucan` i kliknuti **Otključaj predmet (nadzor)**.
   - Dugme „Primijeni odluku“ Nermin nema, jer nadzor ne donosi odluke umjesto referenta.
2. Otvoriti **e-Uprava: izvještaji** i preuzeti PDF po mjesecima, službama, procedurama i referentima.
   Demo task je napravio i ~30 završenih predmeta iz prethodnih mjeseci, pa izvještaj nije prazan.
3. Otvoriti **e-Uprava: revizija**: ko je otvorio koji predmet i kada, pretrage po JMBG-u, prijave, promjene statusa.
   - **Poruka:** zaštita ličnih podataka – svaki pregled se bilježi i ne može se izbrisati iz CMS-a.

## 7. Završetak (2 min)

- **Sigurnost:** CMS je dostupan samo iz gradske mreže (IP ograničenje). Uz lozinku ide jednokratni kod na službeni email (za demo isključeno).
  Prilozi nisu javno dostupni.
- **Pilot:** 3 procedure → proširenje po službama. Nova jednostavna procedura se dodaje u CMS-u, bez programiranja.
- **Odluke koje trebamo od grada:**
  - takse i računi (lista PROVJERITI u README §8);
  - pravne formulacije u emailovima;
  - da li „Spreman za preuzimanje“ traži priložen akt;
  - da li se automatski odbacuje kad istekne rok za dopunu.

---

## Pitanja koja će vjerovatno doći

| Pitanje | Odgovor |
|---|---|
| Je li ovo e-potpis / e-rješenje? | Ne. Akt se i dalje potpisuje i preuzima lično. Sistem skraćuje put do akta i vodi evidenciju. |
| Šta ako građanin nema email? | Šalter. Email nije obavezan, a dobije štampanu potvrdu sa kodom. |
| Ko vidi lične podatke? | Samo referenti nadležne službe i nadzor. Svaki pregled je zabilježen u reviziji. |
| Mijenja li se protokol? | Ne. Broj protokola se unosi ručno iz postojeće knjige ili sistema. |
| Šta AI smije? | Samo da predloži. Odluku o dopuni bira referent sa zatvorene liste, a uplatnice provjeravaju fiksna pravila. AI je isključen dok ga grad ne odobri. |
| Gdje su podaci? | Na serveru grada (ili ugovorenom hostingu u BiH). Jedna instalacija = jedna općina. |

## Nakon prezentacije

```bash
vendor/bin/sake tasks:egov-demo --remove
```

Na produkciji demo task ne radi (`live`). Demo podatke nikad ne praviti na produkcijskoj bazi.
