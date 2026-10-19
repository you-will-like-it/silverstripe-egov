<div class="egov-ai-report">
    <p class="egov-ai-report__overall">
        <span class="egov-ai-report__light" aria-hidden="true">$LightIcon</span>
        <strong>$LightLabel</strong>
        <% if $CheckedAt %><span class="egov-ai-report__muted">· provjereno $CheckedAt</span><% end_if %>
    </p>
    <p class="egov-ai-report__muted">Automatska provjera je pomoć referentu: provjera uplatnice radi po fiksnim pravilima, prepoznavanje vrste dokumenta (AI) je opcionalno po proceduri. 🟢 znači „nisu uočeni nedostaci“ – odluku donosi referent.</p>

    <% if $MissingDocuments %>
        <div class="alert alert-danger">
            <strong>Nedostaju obavezni prilozi:</strong>
            <ul><% loop $MissingDocuments %><li>$Title</li><% end_loop %></ul>
        </div>
    <% end_if %>

    <% if $SuggestedItems %>
        <div class="alert alert-warning">
            <strong>Prijedlog stavki za dopunu</strong> (predodabrano na kartici „Radnje“):
            <ul><% loop $SuggestedItems %><li>$Title – $Reason</li><% end_loop %></ul>
        </div>
    <% end_if %>

    <% if $Attachments %>
        <% loop $Attachments %>
            <section class="egov-ai-report__item egov-ai-report__item--$VerificationLight">
                <h4><span aria-hidden="true">$LightIcon</span> $DocumentLabel <small>– $OriginalFilename ($KindLabel)</small></h4>
                <% if $ReportSummary %><p>$ReportSummary</p><% end_if %>
                <% if $ReportChecks %>
                    <table class="table table-sm">
                        <thead><tr><th></th><th>Provjera</th><th>Rezultat</th><th>Izvor</th></tr></thead>
                        <tbody>
                        <% loop $ReportChecks %>
                            <tr><td aria-hidden="true">$Icon</td><td>$Label</td><td>$Message</td><td><small>$Source</small></td></tr>
                        <% end_loop %>
                        </tbody>
                    </table>
                <% end_if %>
                <p><a href="$DownloadURL" target="_blank" rel="noopener">Otvori prilog</a></p>
            </section>
        <% end_loop %>
    <% else %>
        <p>Nema priloga.</p>
    <% end_if %>
</div>
