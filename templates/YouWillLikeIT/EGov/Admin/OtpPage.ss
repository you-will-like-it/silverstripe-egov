<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>$Title – $TenantFullName</title>
</head>
<body class="egov">
<main class="egov-container egov-main" style="max-width:28rem;">
    <section class="egov-card">
        <h1>$Title</h1>
        <p>Na Vašu adresu <strong>$MaskedEmail</strong><% if $SentNow %> upravo je poslan<% else %> je poslan<% end_if %> jednokratni kod. Unesite ga za pristup e-Upravi.</p>
        $Form
        <p class="egov-muted">Kod važi ograničeno vrijeme i može se iskoristiti samo jednom. Svaka prijava se evidentira.</p>
        <p><a href="/Security/logout">Odjava</a></p>
    </section>
</main>
</body>
</html>
