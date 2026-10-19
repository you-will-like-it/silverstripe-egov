<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: dejavusans; font-size: 10.5pt; color: #111; }
    .lines { font-size: 9pt; line-height: 1.35; font-weight: bold; }
    h1 { font-size: 14pt; text-align: center; margin: 16pt 0 10pt 0; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data td { padding: 5pt 6pt; border-bottom: 0.2mm solid #ccc; vertical-align: top; }
    table.data td.label { width: 40%; color: #444; }
    .code { font-family: dejavusansmono; font-size: 16pt; font-weight: bold; letter-spacing: 1pt; }
    .box { border: 0.5mm solid #000; padding: 10pt 12pt; margin-top: 16pt; }
    .small { font-size: 8.5pt; color: #444; }
    .sign td { padding-top: 30pt; font-size: 9pt; }
    .line { border-top: 0.3mm solid #000; padding-top: 2pt; text-align: center; }
</style>
</head>
<body>
<table width="100%"><tr>
    <% if $LogoDataUri %><td width="16%"><img src="$LogoDataUri" style="height:20mm;"></td><% end_if %>
    <td class="lines"><% loop $LetterheadLines %>$Line<br><% end_loop %><span style="font-weight:normal;">$Request.Service.Title</span></td>
</tr></table>

<h1>POTVRDA O PRIJEMU ZAHTJEVA</h1>

<table class="data">
    <tr><td class="label">Broj protokola</td><td><strong>$Request.ProtocolNumber</strong> od $ProtocolDate</td></tr>
    <tr><td class="label">Procedura</td><td>$Request.RegistryCode – $Request.Procedure.Title</td></tr>
    <tr><td class="label">Podnosilac</td><td>$Request.ApplicantFullName</td></tr>
    <tr><td class="label">Zaprimljeno</td><td>$SubmittedDate<% if $IntakeBy %> (zaprimio/la: $IntakeBy)<% end_if %></td></tr>
    <% if $Request.DecisionDueDate %><tr><td class="label">Zakonski rok za rješavanje</td><td>$Request.DecisionDueFormatted</td></tr><% end_if %>
</table>

<div class="box">
    <p>Kod predmeta za praćenje statusa:</p>
    <p class="code">$Request.TrackingCode</p>
    <p>Status zahtjeva provjeravate na adresi <strong>$StatusUrl</strong> unosom koda predmeta i Vašeg JMBG/ID broja.
       <% if $Request.ApplicantEmail %>Obavještenja o promjeni statusa stižu na {$Request.ApplicantEmail}.<% else %>Obavještenja emailom se ne šalju jer email adresa nije navedena.<% end_if %></p>
    <p class="small">Čuvajte ovu potvrdu. Kod predmeta zajedno sa JMBG/ID brojem omogućava uvid u status zahtjeva.</p>
</div>

<table width="100%" class="sign"><tr>
    <td width="45%"><div class="line">Potpis službenika</div></td>
    <td width="10%" style="text-align:center;">M.P.</td>
    <td width="45%"><div class="line">Potpis podnosioca</div></td>
</tr></table>

<p class="small" style="margin-top:12pt;">Dokument generisan: $PrintedAt</p>
</body>
</html>
