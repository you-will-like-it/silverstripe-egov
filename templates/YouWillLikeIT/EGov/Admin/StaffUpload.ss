<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>$Title</title>
</head>
<body class="egov">
<main class="egov-container egov-main">
    <h1>$Title</h1>
    <p class="egov-muted">$Case.Procedure.Title · $Case.ApplicantFullName · status: $Case.StatusLabel</p>

    <section class="egov-card">
        <h2 class="egov-card__title">Postojeći prilozi</h2>
        <% if $Attachments %>
            <table class="egov-table">
                <thead><tr><th>Vrsta</th><th>Izvor</th><th>Datoteka</th><th>Priložen</th></tr></thead>
                <tbody>
                <% loop $Attachments %>
                    <tr><td>$DocumentLabel</td><td>$KindLabel</td><td><a href="$DownloadURL" target="_blank" rel="noopener">$OriginalFilename</a></td><td>$Created.Nice</td></tr>
                <% end_loop %>
                </tbody>
            </table>
        <% else %>
            <p>Nema priloga.</p>
        <% end_if %>
    </section>

    <% if $CanUpload %>
        <section class="egov-card">
            <h2 class="egov-card__title">Dodaj prilog</h2>
            <p class="egov-muted">Izdati akt uploadujte kao fajl koji već imate (Word/PDF iz vaše aplikacije) – nije potrebno štampati i skenirati.</p>
            $UploadForm
        </section>
    <% else %>
        <p class="egov-alert egov-alert--info">Priloge može dodati samo referent koji obrađuje predmet (ili referent koji je zaprimio zahtjev na šalteru, dok obrada nije pokrenuta).</p>
    <% end_if %>
    <p><a href="javascript:window.close()">Zatvori karticu</a></p>
</main>
</body>
</html>
