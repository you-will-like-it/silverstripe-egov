<div class="egov-review">
    <section>
        <h3>Podnosilac</h3>
        <dl class="egov-dl">
            <% loop $ApplicantRows %><dt>$Label</dt><dd>$Value</dd><% end_loop %>
        </dl>
    </section>

    <% if $CadastralSummary || $LocationLine %>
        <section>
            <h3>Lokacija</h3>
            <dl class="egov-dl">
                <% if $LocationLine %><dt>Adresa / mjesto</dt><dd>$LocationLine</dd><% end_if %>
                <% if $CadastralSummary %><dt>Katastar</dt><dd>$CadastralSummary</dd><% end_if %>
            </dl>
        </section>
    <% end_if %>

    <% if $Summary %>
        <section>
            <h3>Podaci o zahtjevu</h3>
            <dl class="egov-dl">
                <% loop $Summary %><dt>$Label</dt><dd>$Value</dd><% end_loop %>
            </dl>
        </section>
    <% end_if %>

    <% if $Payments %>
        <section>
            <h3>Uplata</h3>
            <ul class="egov-list">
                <% loop $Payments %>
                    <li><strong>$Fee.Title</strong>: $AmountLabel – račun $Fee.PaymentAccount.FormattedAccountNumber<% if $Fee.RevenueType %>, vrsta prihoda $Fee.RevenueType<% end_if %></li>
                <% end_loop %>
            </ul>
        </section>
    <% end_if %>

    <section>
        <h3>Prilozi koje ćete priložiti u sljedećem koraku</h3>
        <ul class="egov-list">
            <% loop $Documents %><li>$Title<% if not $IsMandatory %> <span class="egov-muted">(po potrebi)</span><% end_if %></li><% end_loop %>
        </ul>
        <% if $ExOfficioDocuments %>
            <p class="egov-muted">Služba po službenoj dužnosti pribavlja:
                <% loop $ExOfficioDocuments %>$Title<% if not $IsLast %>, <% end_if %><% end_loop %>.
            </p>
        <% end_if %>
    </section>
</div>
