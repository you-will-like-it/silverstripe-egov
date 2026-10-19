<section class="egov-hero">
    <h1>Elektronski zahtjevi</h1>
    <p>Odaberite postupak, popunite obrazac i priložite dokumente. Zahtjev potvrđujete putem linka koji stiže na Vaš email,
       a gotov dokument preuzimate lično na šalteru gdje potpisujete zahtjev.</p>
    <p><a class="egov-btn egov-btn--ghost" href="$StatusPageLink">Provjeri status postojećeg zahtjeva</a></p>
</section>

<% if $Groups %>
    <% loop $Groups %>
        <section class="egov-service" aria-labelledby="service-$Service.Code">
            <h2 id="service-$Service.Code">$Service.Title</h2>
            <ul class="egov-procedures">
                <% loop $Procedures %>
                    <li class="egov-procedure">
                        <a href="$PortalLink" class="egov-procedure__link">
                            <span class="egov-procedure__code">$Code</span>
                            <span class="egov-procedure__title">$Title</span>
                        </a>
                        <% if $Summary %><p class="egov-procedure__summary">$Summary</p><% end_if %>
                    </li>
                <% end_loop %>
            </ul>
        </section>
    <% end_loop %>
<% else %>
    <p class="egov-alert egov-alert--info">Trenutno nema dostupnih elektronskih zahtjeva.</p>
<% end_if %>
