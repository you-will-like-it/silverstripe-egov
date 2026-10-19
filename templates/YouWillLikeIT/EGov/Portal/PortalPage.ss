<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>$Title – e-Uprava $TenantFullName</title>
</head>
<body class="egov">
    <a class="egov-skip" href="#egov-main">Preskoči na sadržaj</a>
    <header class="egov-header">
        <div class="egov-container egov-header__inner">
            <a class="egov-brand" href="$PortalLink">
                <% if $TenantLogo %><img src="$TenantLogo.ScaleHeight(56).URL" alt="" class="egov-brand__logo"><% end_if %>
                <span>
                    <span class="egov-brand__name">$TenantFullName</span>
                    <span class="egov-brand__sub">Elektronski zahtjevi građana i pravnih lica</span>
                </span>
            </a>
            <nav class="egov-nav" aria-label="Glavna navigacija">
                <a href="$PortalLink">Zahtjevi</a>
                <a href="$StatusPageLink">Status zahtjeva</a>
            </nav>
        </div>
    </header>

    <main id="egov-main" class="egov-container egov-main" tabindex="-1">
        $Content
    </main>

    <footer class="egov-footer">
        <div class="egov-container">
            <p><strong>$TenantFullName</strong><% if $TenantAddress %> · $TenantAddress<% end_if %><% if $TenantPhone %> · tel. $TenantPhone<% end_if %><% if $TenantEmail %> · <a href="mailto:$TenantEmail">$TenantEmail</a><% end_if %></p>
        </div>
    </footer>
</body>
</html>
