<h1>Status zahtjeva</h1>
<p>Unesite kod predmeta (dobili ste ga emailom nakon potvrde zahtjeva) i JMBG/ID broj podnosioca.</p>

<div class="egov-layout">
    <div class="egov-layout__main">
        <% if $NotFound %>
            <p class="egov-alert egov-alert--warning" role="alert">Predmet sa unesenim podacima nije pronađen. Provjerite kod predmeta i JMBG/ID broj.</p>
        <% end_if %>

        <% if $Case %>
            <section class="egov-card" aria-live="polite">
                <h2 class="egov-card__title">$Case.Procedure.Title</h2>
                <dl class="egov-dl">
                    <dt>Kod predmeta</dt><dd class="egov-code">$Case.TrackingCode</dd>
                    <% if $Case.ProtocolNumber %><dt>Broj protokola</dt><dd>$Case.ProtocolNumber</dd><% end_if %>
                    <dt>Status</dt><dd><span class="egov-badge egov-badge--$StatusTone">$StatusLabel</span></dd>
                    <dt>Podnesen</dt><dd>$Case.SubmittedAtFormatted</dd>
                    <% if $Case.DecisionDueDate %><dt>Zakonski rok za rješavanje</dt><dd>$Case.DecisionDueFormatted</dd><% end_if %>
                </dl>

                <% if $IsIncomplete %>
                    <div class="egov-alert egov-alert--warning">
                        <strong>Potrebna je dopuna<% if $Case.SupplementDueDate %> do $Case.SupplementDueFormatted<% end_if %>.</strong>
                        <div class="egov-pre">$Case.SupplementRequestText</div>
                        <p>Dopunu dostavljate preko linka iz emaila ili lično na šalteru.</p>
                    </div>
                <% end_if %>
                <% if $IsAwaitingPayment %>
                    <p class="egov-alert egov-alert--warning">Zahtjev čeka uplatu takse. Podatke za uplatu ste dobili emailom.</p>
                <% end_if %>
                <% if $IsClosedNegative %>
                    <p class="egov-alert egov-alert--danger">Postupak je okončan. Akt sa obrazloženjem i poukom o pravnom lijeku preuzimate na šalteru.</p>
                <% end_if %>
                <% if $IsReadyForPickup %>
                    <p class="egov-alert egov-alert--success">Dokument je spreman. Preuzmite ga lično na šalteru uz ličnu kartu – tom prilikom potpisujete zahtjev.</p>
                <% end_if %>

                <% if $History %>
                    <h3>Tok predmeta</h3>
                    <ol class="egov-timeline">
                        <% loop $History %>
                            <li><time>$Created.Format('dd.MM.y. HH:mm')</time> – $ToLabel</li>
                        <% end_loop %>
                    </ol>
                <% end_if %>
            </section>
        <% end_if %>

        $Form
    </div>
</div>
