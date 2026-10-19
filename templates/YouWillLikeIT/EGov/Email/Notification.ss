<% include YouWillLikeIT\EGov\Email\Includes\EmailLayoutTop %>
<% if $IntroHtml %><p>$IntroHtml</p><% end_if %>

<% if $ShowCaseTable && $Request %>
<table role="presentation" cellpadding="6" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:4px;margin:16px 0;width:100%;font-size:14px;">
    <tr><td style="color:#6b7280;width:45%;">Kod predmeta</td><td><strong style="font-family:monospace;font-size:16px;">$Request.TrackingCode</strong></td></tr>
    <% if $Request.ProtocolNumber %><tr><td style="color:#6b7280;">Broj protokola</td><td>$Request.ProtocolNumber</td></tr><% end_if %>
    <tr><td style="color:#6b7280;">Procedura</td><td>$Request.Procedure.Title</td></tr>
    <tr><td style="color:#6b7280;">Status</td><td>$Request.PublicStatusLabel</td></tr>
    <% if $Request.DecisionDueDate %><tr><td style="color:#6b7280;">Zakonski rok za rješavanje</td><td>$Request.DecisionDueFormatted</td></tr><% end_if %>
</table>
<% end_if %>

<% if $NoticeText %>
<div style="background:#fff7ed;border-left:4px solid #f59e0b;padding:12px 16px;margin:16px 0;white-space:pre-line;">$NoticeText</div>
<% end_if %>

<% if $Payments %>
<p><strong>Podaci za uplatu:</strong></p>
<ul>
    <% loop $Payments %><li>$Fee.Title: $AmountLabel – račun $Fee.PaymentAccount.FormattedAccountNumber<% if $Fee.RevenueType %>, vrsta prihoda $Fee.RevenueType<% end_if %><% if $Top.TenantMunicipalityCode %>, šifra općine $Top.TenantMunicipalityCode<% end_if %></li><% end_loop %>
</ul>
<% end_if %>

<% if $Overdue || $DueSoon || $SupplementExpired %>
    <% if $Overdue %>
        <p style="color:#b91c1c;font-weight:bold;">Prekoračen zakonski rok ($Overdue.Count)</p>
        <ul><% loop $Overdue %><li>$TrackingCode<% if $ProtocolNumber %> / $ProtocolNumber<% end_if %> – $Procedure.Title – rok $DecisionDueFormatted<% if $AssignedTo %> – $AssignedTo.Name<% end_if %></li><% end_loop %></ul>
    <% end_if %>
    <% if $DueSoon %>
        <p style="color:#b45309;font-weight:bold;">Rok ističe uskoro ($DueSoon.Count)</p>
        <ul><% loop $DueSoon %><li>$TrackingCode<% if $ProtocolNumber %> / $ProtocolNumber<% end_if %> – $Procedure.Title – rok $DecisionDueFormatted<% if $AssignedTo %> – $AssignedTo.Name<% end_if %></li><% end_loop %></ul>
    <% end_if %>
    <% if $SupplementExpired %>
        <p style="font-weight:bold;">Istekao rok za dopunu ($SupplementExpired.Count)</p>
        <ul><% loop $SupplementExpired %><li>$TrackingCode<% if $ProtocolNumber %> / $ProtocolNumber<% end_if %> – $Procedure.Title – rok za dopunu $SupplementDueFormatted</li><% end_loop %></ul>
    <% end_if %>
<% end_if %>

<% if $ButtonLink %>
<p style="text-align:center;margin:26px 0;">
    <a href="$ButtonLink" style="background:#0b3d6e;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:4px;font-weight:bold;display:inline-block;">$ButtonLabel</a>
</p>
<p style="font-size:12px;color:#6b7280;word-break:break-all;">$ButtonLink</p>
<% end_if %>
<% if $SmallPrint %><p style="font-size:13px;color:#4b5563;">$SmallPrint</p><% end_if %>

<% if $LegalHtml %><p style="font-size:13px;color:#374151;border-top:1px solid #e5e7eb;padding-top:12px;">$LegalHtml</p><% end_if %>
<% if $OutroHtml %><p>$OutroHtml</p><% end_if %>
<% include YouWillLikeIT\EGov\Email\Includes\EmailLayoutBottom %>
