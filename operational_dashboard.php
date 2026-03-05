<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.dashboard.view');

$period = isset($_GET['period']) ? (string)$_GET['period'] : 'month';

$allowedPeriods = ['day', 'week', 'month', 'year'];
if (!in_array($period, $allowedPeriods, true)) {
    $period = 'month';
}

$dateFilter = '';
switch ($period) {
    case 'day':
        $dateFilter = 'DATE(created_at) = CURDATE()';
        break;
    case 'week':
        $dateFilter = 'YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)';
        break;
    case 'month':
        $dateFilter = 'YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())';
        break;
    case 'year':
        $dateFilter = 'YEAR(created_at) = YEAR(CURDATE())';
        break;
}

$db = db();

// 1. Atendimentos Recebidos (demandas/cards criados no período)
$stmt = $db->query("SELECT COUNT(*) FROM demands WHERE $dateFilter");
$atendimentosRecebidos = (int)$stmt->fetchColumn();

// 2. Atendimentos Realizados (formulários confirmados = professional_documentations aprovados no período)
$stmt = $db->query(
    "SELECT COUNT(*) FROM professional_documentations WHERE status = 'approved' AND " .
    str_replace('created_at', 'updated_at', $dateFilter)
);
$atendimentosRealizados = (int)$stmt->fetchColumn();

// 3. Taxa de Conversão (demandas que resultaram em atendimento confirmado)
$stmt = $db->query("SELECT COUNT(*) FROM demands WHERE status = 'admitido' AND $dateFilter");
$demandasAdmitidas = (int)$stmt->fetchColumn();
$taxaConversao = $atendimentosRecebidos > 0 ? round(($demandasAdmitidas / $atendimentosRecebidos) * 100, 2) : 0;

// 4. Profissionais Ativos (com agendamentos no período)
$stmt = $db->query(
    "SELECT COUNT(DISTINCT professional_user_id) FROM appointments WHERE " .
    str_replace('created_at', 'first_at', $dateFilter)
);
$profissionaisAtivos = (int)$stmt->fetchColumn();

// 5. Pendências em Aberto (todos os tipos)
$stmt = $db->query("SELECT COUNT(*) FROM pending_items WHERE status IN ('open','in_progress')");
$pendenciasAbertas = (int)$stmt->fetchColumn();

// 6. Detalhamento de pendências por tipo
$stmt = $db->query(
    "SELECT type, COUNT(*) AS total FROM pending_items WHERE status IN ('open','in_progress') GROUP BY type ORDER BY total DESC LIMIT 10"
);
$pendenciasPorTipo = $stmt->fetchAll();

view_header('Dashboard Operacional');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Dashboard Operacional</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Indicadores operacionais (Módulo 11.1).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/finance_dashboard.php">Dashboard Financeiro</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/operational_dashboard.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="period">';
$periodLabels = ['day' => 'Hoje', 'week' => 'Esta semana', 'month' => 'Este mês', 'year' => 'Este ano'];
foreach ($periodLabels as $k => $label) {
    $sel = ($period === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';
echo '<button class="btn btnPrimary" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col4">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:8px">Atendimentos Recebidos</div>';
echo '<div style="font-size:28px;font-weight:900;color:hsl(var(--primary))">' . $atendimentosRecebidos . '</div>';
echo '<div style="margin-top:4px;font-size:12px;color:hsl(var(--muted-foreground))">Demandas/cards criados no período</div>';
echo '</section>';

echo '<section class="card col4">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:8px">Atendimentos Realizados</div>';
echo '<div style="font-size:28px;font-weight:900;color:hsl(var(--primary))">' . $atendimentosRealizados . '</div>';
echo '<div style="margin-top:4px;font-size:12px;color:hsl(var(--muted-foreground))">Formulários confirmados no período</div>';
echo '</section>';

echo '<section class="card col4">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:8px">Taxa de Conversão</div>';
echo '<div style="font-size:28px;font-weight:900;color:hsl(var(--primary))">' . $taxaConversao . '%</div>';
echo '<div style="margin-top:4px;font-size:12px;color:hsl(var(--muted-foreground))">Demandas que resultaram em atendimento</div>';
echo '</section>';

echo '<section class="card col6">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:8px">Profissionais Ativos</div>';
echo '<div style="font-size:28px;font-weight:900">' . $profissionaisAtivos . '</div>';
echo '<div style="margin-top:4px;font-size:12px;color:hsl(var(--muted-foreground))">Com agendamentos no período</div>';
echo '</section>';

echo '<section class="card col6">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:8px">Pendências em Aberto</div>';
echo '<div style="font-size:28px;font-weight:900;color:hsl(var(--destructive))">' . $pendenciasAbertas . '</div>';
echo '<div style="margin-top:4px;font-size:12px;color:hsl(var(--muted-foreground))">Cards sem ação, formulários atrasados, docs vencidos</div>';
echo '<div style="margin-top:8px"><a class="btn" href="/pending_items_list.php" style="font-size:12px;padding:6px 12px">Ver todas</a></div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:12px">Pendências por Tipo (Top 10)</div>';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>Tipo</th><th style="text-align:right">Total</th>';
echo '</tr></thead><tbody>';
foreach ($pendenciasPorTipo as $pt) {
    echo '<tr>';
    echo '<td style="font-weight:700">' . h((string)$pt['type']) . '</td>';
    echo '<td style="text-align:right">' . (int)$pt['total'] . '</td>';
    echo '</tr>';
}
if (count($pendenciasPorTipo) === 0) {
    echo '<tr><td colspan="2" class="pill" style="display:table-cell;padding:12px">Sem pendências abertas.</td></tr>';
}
echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
