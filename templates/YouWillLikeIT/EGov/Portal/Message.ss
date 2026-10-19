<section class="egov-message">
    <h1>$Title</h1>
    <div class="egov-alert egov-alert--$Tone" role="status">
        <p>$Message</p>
        <% if $TrackingCode %>
            <p class="egov-tracking">Kod predmeta: <strong class="egov-code" data-egov-copy>$TrackingCode</strong></p>
        <% end_if %>
    </div>
    <p class="egov-row">
        <% if $CaseLink %><a class="egov-btn egov-btn--primary" href="$CaseLink">Otvori predmet</a><% end_if %>
        <% if $StatusLink %><a class="egov-btn egov-btn--ghost" href="$StatusLink">Status zahtjeva</a><% end_if %>
        <a class="egov-btn egov-btn--link" href="$PortalLink">Svi zahtjevi</a>
    </p>
</section>
