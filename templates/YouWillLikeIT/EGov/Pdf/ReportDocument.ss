<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: dejavusans; font-size: 8.5pt; color: #111; }
    .lines { font-size: 8.5pt; line-height: 1.3; font-weight: bold; }
    h1 { font-size: 14pt; margin: 10pt 0 2pt 0; }
    h2 { font-size: 11pt; margin: 14pt 0 4pt 0; border-bottom: 0.4mm solid #0b3d6e; color: #0b3d6e; padding-bottom: 2pt; }
    .meta { color: #555; margin-bottom: 6pt; }
    table.r { width: 100%; border-collapse: collapse; }
    table.r th { background: #eef2f7; text-align: right; padding: 3pt 3pt; border-bottom: 0.3mm solid #999; font-weight: bold; }
    table.r th.l, table.r td.l { text-align: left; }
    table.r td { padding: 2.5pt 3pt; border-bottom: 0.15mm solid #ddd; text-align: right; }
    table.r tr.t td { font-weight: bold; border-top: 0.4mm solid #444; background: #f7f7f7; }
    .bar { background: #0b3d6e; height: 5pt; }
    .warn { color: #b45309; font-weight: bold; }
    .bad { color: #b91c1c; font-weight: bold; }
    .legend { font-size: 7.5pt; color: #555; margin-top: 8pt; }
</style>
</head>
<body>
<table width="100%"><tr><td class="lines"><% loop $LetterheadLines %>$Line<br><% end_loop %></td></tr></table>

<h1>Izvještaj e-Uprave za $Year. godinu</h1>
<p class="meta">$BranchLabel · $ScopeLabel · generisao: $GeneratedBy, $GeneratedAt</p>

<% loop $Sections %>
    <h2>$Title</h2>
    <table class="r">
        <thead>
            <tr>
                <th class="l" width="24%"><% if $ShowReferentColumns %>Referent<% else %>Grupa<% end_if %></th>
                <th class="l" width="13%"></th>
                <th>Ukupno</th>
                <th>Portal</th>
                <th>Šalter</th>
                <th>Završeno</th>
                <th>Odbijeno</th>
                <th>Odbačeno</th>
                <th>U toku</th>
                <th>Prekoračen rok</th>
                <th>Riješeno nakon roka</th>
                <th>Sa dopunom</th>
                <th>Prosj. dana</th>
                <% if $ShowReferentColumns %><th>Stavki dopune</th><th>Odbijanja / odbacivanja</th><% end_if %>
            </tr>
        </thead>
        <tbody>
        <% loop $Rows %>
            <tr>
                <td class="l">$Label</td>
                <td class="l"><% if $BarPct %><div class="bar" style="width:{$BarPct}%;"></div><% end_if %></td>
                <td>$Total</td>
                <td>$Portal</td>
                <td>$Counter</td>
                <td>$Completed</td>
                <td>$Rejected</td>
                <td>$Dismissed</td>
                <td>$Open</td>
                <td<% if $Overdue %> class="bad"<% end_if %>>$Overdue</td>
                <td<% if $Late %> class="warn"<% end_if %>>$Late</td>
                <td>$Supplemented</td>
                <td>$AvgDays</td>
                <% if $Up.ShowReferentColumns %><td>$SupplementItems</td><td>$Decisions</td><% end_if %>
            </tr>
        <% end_loop %>
        <% with $Totals %>
            <tr class="t">
                <td class="l">Ukupno</td><td></td>
                <td>$Total</td><td>$Portal</td><td>$Counter</td><td>$Completed</td><td>$Rejected</td><td>$Dismissed</td>
                <td>$Open</td><td>$Overdue</td><td>$Late</td><td>$Supplemented</td><td></td>
                <% if $Up.ShowReferentColumns %><td>$SupplementItems</td><td>$Decisions</td><% end_if %>
            </tr>
        <% end_with %>
        </tbody>
    </table>
<% end_loop %>

<p class="legend">
    <strong>Napomene:</strong> Uključeni su predmeti podneseni u $Year. godini (portal nakon potvrde emaila, šalter nakon prijema).
    „Prekoračen rok“ = otvoreni predmeti čiji je zakonski rok istekao; „Riješeno nakon roka“ = završeni nakon isteka zakonskog roka.
    „Prosj. dana“ = prosječno trajanje od podnošenja do okončanja (kalendarski dani, uključuje vrijeme čekanja na dopunu).
    Kod referenata se predmet vodi na zadnjeg referenta koji ga je obrađivao.
</p>
</body>
</html>
