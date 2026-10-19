<nav class="egov-breadcrumbs" aria-label="Putanja"><a href="$PortalLink">Zahtjevi</a> › Predmet $Case.TrackingCode</nav>

<h1>$Title</h1>

<div class="egov-case-head">
    <span class="egov-badge egov-badge--$StatusTone">$StatusLabel</span>
    <span>Kod predmeta: <strong class="egov-code">$Case.TrackingCode</strong></span>
    <% if $Case.ProtocolNumber %><span>Broj protokola: <strong>$Case.ProtocolNumber</strong></span><% end_if %>
</div>

<% if $IsDraft %>
    <div class="egov-alert egov-alert--info" role="status">
        <% if $AwaitingConfirmation %>
            <strong>Zahtjev čeka Vašu potvrdu.</strong> Link za potvrdu poslan je na {$Case.ApplicantEmail}.
            Zahtjev se smatra podnesenim tek kada kliknete na link.
        <% else %>
            <strong>Zahtjev još nije podnesen.</strong> Priložite dokumente, a zatim kliknite „Podnesi zahtjev“.
            Sačuvajte adresu ove stranice – preko nje možete nastaviti kasnije.
        <% end_if %>
    </div>
<% end_if %>

<% if $IsSupplementRound %>
    <div class="egov-alert egov-alert--warning" role="status">
        <strong>Služba traži dopunu zahtjeva<% if $Case.SupplementDueDate %> do $Case.SupplementDueFormatted<% end_if %>.</strong>
        <div class="egov-pre">$Case.SupplementRequestText</div>
    </div>
<% end_if %>

<% if $Payments %>
    <section class="egov-card" aria-labelledby="uplate">
        <h2 id="uplate" class="egov-card__title">Podaci za uplatu</h2>
        <table class="egov-table">
            <thead><tr><th>Namjena</th><th>Račun primaoca</th><th>Vrsta prihoda</th><th>Iznos</th></tr></thead>
            <tbody>
            <% loop $Payments %>
                <tr>
                    <td>$Fee.Title</td>
                    <td class="egov-code">$Fee.PaymentAccount.FormattedAccountNumber</td>
                    <td class="egov-code">$Fee.RevenueType</td>
                    <td>$AmountLabel</td>
                </tr>
            <% end_loop %>
            </tbody>
        </table>
        <p class="egov-muted">Na uplatnici kao šifru općine navedite <strong class="egov-code">{$Top.TenantMunicipalityCode}</strong> i kao broj poreznog obveznika JMBG/ID podnosioca.</p>
    </section>
<% end_if %>

<section id="prilozi" class="egov-card" aria-labelledby="prilozi-naslov">
    <h2 id="prilozi-naslov" class="egov-card__title">Prilozi</h2>

    <% if $MissingDocuments %>
        <p class="egov-alert egov-alert--warning">Nedostaju obavezni prilozi:
            <% loop $MissingDocuments %>$Title<% if not $IsLast %>, <% end_if %><% end_loop %>
        </p>
    <% end_if %>

    <ul class="egov-docs">
        <% loop $Documents %>
            <li class="egov-docs__item">
                <div class="egov-docs__title">
                    $Document.Title
                    <% if $Document.IsMandatory %><span class="egov-tag">obavezno</span><% else %><span class="egov-tag egov-tag--muted">po potrebi</span><% end_if %>
                    <span class="egov-muted">· $Document.DocumentFormLabel<% if $Document.Issuer %> · izdaje: $Document.Issuer<% end_if %></span>
                </div>
                <% if $Uploaded %>
                    <ul class="egov-files">
                        <% loop $Uploaded %>
                            <li>
                                <span>$OriginalFilename <span class="egov-muted">($HumanFileSize)</span></span>
                                <% if $Top.AcceptsUploads && $Kind == 'Citizen' %>
                                    <button type="submit" class="egov-btn egov-btn--link" form="Form_CaseActionsForm" name="action_doRemove" value="$ID" formnovalidate>Ukloni</button>
                                <% end_if %>
                            </li>
                        <% end_loop %>
                    </ul>
                <% end_if %>
            </li>
        <% end_loop %>
    </ul>

    <% if $AcceptsUploads %>
        <div class="egov-upload" data-egov-max-mb="$MaxFileSizeMb">
            <h3>Dodaj prilog</h3>
            <p class="egov-muted">Dozvoljeni formati: $AllowedExtensions · najviše $MaxFileSizeMb MB po datoteci. Fotografija mora biti čitljiva (cijeli dokument, bez odsjaja).</p>
            $UploadForm
        </div>
    <% end_if %>
</section>

<% if $AcceptsUploads %>
    <section class="egov-actions">
        $CaseActionsForm
    </section>
<% end_if %>

<p class="egov-muted">Status zahtjeva možete provjeriti i na stranici <a href="$StatusLink">Status zahtjeva</a> uz kod predmeta i JMBG/ID broj.</p>
