<nav class="egov-breadcrumbs" aria-label="Putanja"><a href="$PortalLink">Zahtjevi</a> › $Procedure.Code</nav>

<h1>$Procedure.Title</h1>
<p class="egov-muted">$Procedure.Service.Title</p>

<div class="egov-layout">
    <div class="egov-layout__main">
        <% if $Steps %>
            <ol class="egov-steps" aria-label="Koraci">
                <% loop $Steps %>
                    <li class="egov-steps__item egov-steps__item--$LinkingMode $ExtraClasses"<% if $LinkingMode == 'current' %> aria-current="step"<% end_if %>>
                        <span class="egov-steps__num">$Pos</span> $Title
                    </li>
                <% end_loop %>
            </ol>
        <% end_if %>
        $Form
    </div>

    <aside class="egov-layout__aside" aria-label="Informacije o postupku">
        <div class="egov-card">
            <h2 class="egov-card__title">Šta je potrebno</h2>
            <% if $Documents %>
                <ul class="egov-list">
                    <% loop $Documents %>
                        <li>$Title<% if not $IsMandatory %> <span class="egov-muted">(po potrebi)</span><% end_if %></li>
                    <% end_loop %>
                </ul>
            <% else %>
                <p>Nisu potrebni prilozi.</p>
            <% end_if %>
        </div>
        <% if $Fees %>
            <div class="egov-card">
                <h2 class="egov-card__title">Takse i naknade</h2>
                <ul class="egov-list">
                    <% loop $Fees %>
                        <li>
                            <strong>$Title</strong>: $AmountLabel
                            <% if $PaymentAccount %><br><span class="egov-muted">Račun $PaymentAccount.FormattedAccountNumber<% if $RevenueType %>, vrsta prihoda $RevenueType<% end_if %></span><% end_if %>
                        </li>
                    <% end_loop %>
                </ul>
            </div>
        <% end_if %>
        <% if $Procedure.ResultDocumentTitle %>
            <div class="egov-card">
                <h2 class="egov-card__title">Rezultat postupka</h2>
                <p>$Procedure.ResultDocumentTitle<% if $Procedure.ValidityNote %> (važenje: $Procedure.ValidityNote)<% end_if %></p>
            </div>
        <% end_if %>
    </aside>
</div>
