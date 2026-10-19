<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: dejavusans; font-size: 10pt; color: #111; }
    .letterhead td { vertical-align: middle; }
    .letterhead .lines { font-size: 9pt; line-height: 1.35; font-weight: bold; }
    .muted { color: #555; }
    h1 { font-size: 12.5pt; text-align: center; margin: 14pt 0 6pt 0; }
    h2 { font-size: 10.5pt; margin: 12pt 0 4pt 0; border-bottom: 0.3mm solid #999; padding-bottom: 2pt; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data td { padding: 3pt 4pt; border-bottom: 0.2mm solid #ddd; vertical-align: top; }
    table.data td.label { width: 38%; color: #444; }
    .meta { width: 100%; border: 0.3mm solid #444; border-collapse: collapse; margin-top: 8pt; }
    .meta td { padding: 4pt 6pt; border: 0.3mm solid #444; font-size: 9pt; }
    .subject { margin-top: 10pt; }
    .body { text-align: justify; line-height: 1.5; margin-top: 6pt; }
    .sign { width: 100%; margin-top: 18pt; }
    .sign td { vertical-align: bottom; padding-top: 26pt; font-size: 9pt; }
    .line { border-top: 0.3mm solid #000; padding-top: 2pt; text-align: center; }
    .box { border: 0.4mm solid #000; padding: 8pt 10pt; margin-top: 14pt; page-break-inside: avoid; }
    .small { font-size: 8pt; }
    .code { font-family: dejavusansmono; }
</style>
</head>
<body>

<table class="letterhead" width="100%">
    <tr>
        <% if $LogoDataUri %><td width="18%"><img src="$LogoDataUri" style="height:22mm;"></td><% end_if %>
        <td class="lines">
            <% loop $LetterheadLines %>$Line<br><% end_loop %>
            <span style="font-weight:normal;">$Service.Title</span>
        </td>
    </tr>
</table>

<table class="meta">
    <tr>
        <td>Broj protokola: <strong><% if $Request.ProtocolNumber %>$Request.ProtocolNumber<% else %>____________________<% end_if %></strong></td>
        <td>Datum: <% if $Request.ProtocolNumber %>$ProtocolDate<% else %>____________<% end_if %></td>
        <td>Kod predmeta: <span class="code">$Request.TrackingCode</span></td>
    </tr>
    <tr>
        <td>Šifra procedure: $Request.RegistryCode</td>
        <td colspan="2">Elektronski podnesen: $SubmittedDate<% if $Request.ServiceClass %> · Klasifikacijska oznaka: $Request.ServiceClass<% end_if %></td>
    </tr>
</table>

<h2>Podnosilac zahtjeva</h2>
<table class="data">
    <tr><td class="label">Vrsta podnosioca</td><td>$ApplicantTypeLabel</td></tr>
    <tr><td class="label"><% if $IsCompany %>Naziv<% else %>Ime (ime jednog roditelja) i prezime<% end_if %></td>
        <td>$Request.ApplicantFullName<% if $Request.ApplicantParentName %> ($Request.ApplicantParentName)<% end_if %></td></tr>
    <tr><td class="label">$IdLabel</td><td class="code">$Request.ApplicantIdentificationNumber</td></tr>
    <tr><td class="label">Kontakt adresa</td><td>$Request.ApplicantAddress<% if $Request.ApplicantPostalCode || $Request.ApplicantCity %>, $Request.ApplicantPostalCode $Request.ApplicantCity<% end_if %></td></tr>
    <tr><td class="label">Kontakt telefon</td><td>$Request.ApplicantPhone</td></tr>
    <tr><td class="label">Email</td><td>$Request.ApplicantEmail</td></tr>
    <% if $Request.RepresentativeName %><tr><td class="label">Zastupnik / punomoćnik</td><td>$Request.RepresentativeName</td></tr><% end_if %>
</table>

<p class="subject"><strong>$TenantFullNameUpper</strong><br>$Service.Title</p>
<p class="subject"><strong>PREDMET:</strong> $SubjectLine</p>

<% if $IntroText %>
    <p class="body">$IntroText</p>
<% else %>
    <p class="body">Obraćam/o se nadležnoj službi zahtjevom za provođenje postupka „$Procedure.Title“ prema podacima navedenim u nastavku.</p>
<% end_if %>

<% if $CadastralSummary || $Request.LocationPlace %>
    <h2>Lokacija</h2>
    <table class="data">
        <% if $Request.LocationPlace %><tr><td class="label">Mjesto / ulica</td><td>$Request.LocationPlace<% if $Request.LocationStreet %>, $Request.LocationStreet<% end_if %></td></tr><% end_if %>
        <% if $CadastralSummary %><tr><td class="label">Katastarski podaci</td><td>$CadastralSummary</td></tr><% end_if %>
    </table>
<% end_if %>

<% if $Summary %>
    <h2>Podaci o zahtjevu</h2>
    <table class="data">
        <% loop $Summary %><tr><td class="label">$Label</td><td>$Value</td></tr><% end_loop %>
    </table>
<% end_if %>

<h2>Uz zahtjev priloženo (elektronski)</h2>
<% if $Attachments %>
    <table class="data">
        <% loop $Attachments %>
            <tr><td class="label">{$Pos}. $RequiredDocument.Title</td><td>$OriginalFilename<% if $IsSupplement %> <span class="muted">(dopuna)</span><% end_if %></td></tr>
        <% end_loop %>
    </table>
<% else %>
    <p class="muted">Nema elektronskih priloga.</p>
<% end_if %>

<% if $Payments %>
    <h2>Propisane takse / naknade</h2>
    <table class="data">
        <% loop $Payments %>
            <tr><td class="label">$Fee.Title<% if $Fee.TariffNumber %> (tar. br. $Fee.TariffNumber)<% end_if %></td>
                <td>$AmountLabel · račun <span class="code">$Fee.PaymentAccount.FormattedAccountNumber</span><% if $Fee.RevenueType %> · vrsta prihoda $Fee.RevenueType<% end_if %></td></tr>
        <% end_loop %>
    </table>
<% end_if %>

<p class="small muted" style="margin-top:10pt;">Podnosilac je prilikom elektronskog podnošenja izjavio da su navedeni podaci tačni i da je upoznat sa obradom ličnih podataka
<% if $Request.PrivacyConsentAt %>($Request.PrivacyConsentAt.Format('dd.MM.y. HH:mm'))<% end_if %>. Email adresa potvrđena: <% if $Request.EmailConfirmedAt %>$Request.EmailConfirmedAt.Format('dd.MM.y. HH:mm')<% else %>—<% end_if %>.</p>

<div class="box">
    <strong>POTVRDA O PREUZIMANJU I POTPIS PODNOSIOCA</strong>
    <p class="small" style="margin-top:6pt;">Svojim potpisom potvrđujem da sam lično (ili putem ovlaštenog punomoćnika) podnio/la ovaj zahtjev elektronskim putem,
       da su podaci u zahtjevu tačni, te da sam preuzeo/la akt: <strong><% if $Procedure.ResultDocumentTitle %>$Procedure.ResultDocumentTitle<% else %>____________________________<% end_if %></strong>.</p>
    <table class="sign">
        <tr>
            <td width="45%">Identitet utvrđen uvidom u ličnu kartu / pasoš br.:<br><br>______________________________</td>
            <td width="10%"></td>
            <td width="45%">U {$TenantPlaceLocative}, dana _______________ godine</td>
        </tr>
        <tr>
            <td><div class="line">Potpis službenika koji je predao akt</div></td>
            <td style="text-align:center;">M.P.</td>
            <td><div class="line">Potpis podnosioca / punomoćnika</div></td>
        </tr>
    </table>
</div>

<p class="small muted" style="margin-top:8pt;">Dokument generisan: $PrintedAt</p>
</body>
</html>
