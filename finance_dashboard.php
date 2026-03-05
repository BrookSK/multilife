<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

$period = isset($_GET['period']) ? (string)$_GET['period'] : 'month';
$professionalId = isset($_GET['professional_id']) ? (int)$_GET['professional_id'] : 0;
$specialty = isset($_GET['specialty']) ? trim((string)$_GET['specialty']) : '';
$city = isset($_GET['city']) ? trim((string)$_GET['city']) : '';

$allowedPeriods = ['day', 'week', 'month', 'year'];
if (!in_array($period, $allowedPeriods, true)) {
    $period = 'month';
}

$dateFilter = '';
switch ($period) {
    case 'day':
        $dateFilter = 'DATE(a.first_at) = CURDATE()';
        break;
    case 'week':
        $dateFilter = 'YEARWEEK(a.first_at, 1) = YEARWEEK(CURDATE(), 1)';
        break;
    case 'month':
        $dateFilter = 'YEAR(a.first_at) = YEAR(CURDATE()) AND MONTH(a.first_at) = MONTH(CURDATE())';
        break;
    case 'year':
        $dateFilter = 'YEAR(a.first_at) = YEAR(CURDATE())';
        break;
}

$baseWhere = [$dateFilter];
$params = [];

if ($professionalId > 0) {
    $baseWhere[] = 'a.professional_user_id = :prof_id';
    $params['prof_id'] = $professionalId;
}

if ($specialty !== '') {
    $baseWhere[] = 'a.specialty = :specialty';
    $params['specialty'] = $specialty;
}

if ($city !== '') {
    $baseWhere[] = 'p.address_city = :city';
    $params['city'] = $city;
}

$whereClause = implode(' AND ', $baseWhere);

$db = db();

$stmt = $db->prepare(
    "SELECT COALESCE(SUM(ar.amount), 0) AS total
     FROM finance_accounts_receivable ar
     INNER JOIN appointments a ON a.id = ar.appointment_id
     INNER JOIN patients p ON p.id = ar.patient_id
     WHERE ar.status IN ('recebido') AND $whereClause"
);
$stmt->execute($params);
$faturamentoTotal = (float)$stmt->fetchColumn();

$stmt = $db->prepare(
    "SELECT COALESCE(SUM(ap.amount), 0) AS total
     FROM finance_accounts_payable ap
     INNER JOIN appointments a ON a.id = ap.appointment_id
     INNER JOIN patients p ON p.id = a.patient_id
     WHERE ap.status IN ('pago') AND $whereClause"
);
$stmt->execute($params);
$custoAtendimentos = (float)$stmt->fetchColumn();

$margemOperacional = $faturamentoTotal - $custoAtendimentos;

$stmt = $db->prepare(
    "SELECT COALESCE(SUM(ar.amount), 0) AS total
     FROM finance_accounts_receivable ar
     INNER JOIN appointments a ON a.id = ar.appointment_id
     INNER JOIN patients p ON p.id = ar.patient_id
     WHERE ar.status = 'pendente' AND $whereClause"
);
$stmt->execute($params);
$contasReceber = (float)$stmt->fetchColumn();

$stmt = $db->prepare(
    "SELECT COALESCE(SUM(ap.amount), 0) AS total
     FROM finance_accounts_payable ap
     INNER JOIN appointments a ON a.id = ap.appointment_id
     INNER JOIN patients p ON p.id = a.patient_id
     WHERE ap.status = 'pendente' AND $whereClause"
);
$stmt->execute($params);
$contasPagar = (float)$stmt->fetchColumn();

$stmt = $db->prepare(
    "SELECT COALESCE(SUM(ar.amount), 0) AS total
     FROM finance_accounts_receivable ar
     INNER JOIN appointments a ON a.id = ar.appointment_id
     INNER JOIN patients p ON p.id = ar.patient_id
     WHERE ar.status = 'inadimplente' AND $whereClause"
);
$stmt->execute($params);
$inadimplencia = (float)$stmt->fetchColumn();

$professionals = $db->query(
    "SELECT u.id, u.name FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'profissional' AND u.status = 'active' ORDER BY u.name ASC"
)->fetchAll();

$specialties = $db->query(
    "SELECT DISTINCT specialty FROM appointments WHERE specialty IS NOT NULL AND specialty != '' ORDER BY specialty ASC"
)->fetchAll();

$cities = $db->query(
    "SELECT DISTINCT address_city FROM patients WHERE address_city IS NOT NULL AND address_city != '' AND deleted_at IS NULL ORDER BY address_city ASC"
)->fetchAll();

view_header('Dashboard Financeiro');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Dashboard Financeiro</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Indicadores de saúde financeira da operação (Módulo 9.4).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/finance_receivable_list.php">Contas a Receber</a>';
echo '<a class="btn" href="/finance_payable_list.php">Contas a Pagar</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/finance_dashboard.php" style="margin-top:14px;display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">';

echo '<select name="period">';
$periodLabels = ['day' => 'Hoje', 'week' => 'Esta semana', 'month' => 'Este mês', 'year' => 'Este ano'];
foreach ($periodLabels as $k => $label) {
    $sel = ($period === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';

echo '<select name="professional_id">';
echo '<option value="0">Todos profissionais</option>';
foreach ($professionals as $prof) {
    $sel = ($professionalId === (int)$prof['id']) ? ' selected' : '';
    echo '<option value="' . (int)$prof['id'] . '"' . $sel . '>' . h((string)$prof['name']) . '</option>';
}
echo '</select>';

echo '<select name="specialty">';
echo '<option value="">Todas especialidades</option>';
foreach ($specialties as $sp) {
    $sel = ($specialty === (string)$sp['specialty']) ? ' selected' : '';
    echo '<option value="' . h((string)$sp['specialty']) . '"' . $sel . '>' . h((string)$sp['specialty']) . '</option>';
}
echo '</select>';

echo '<select name="city">';
echo '<option value="">Todas cidades</option>';
foreach ($cities as $c) {
    $sel = ($city === (string)$c['address_city']) ? ' selected' : '';
    echo '<option value="' . h((string)$c['address_city']) . '"' . $sel . '>' . h((string)$c['address_city']) . '</option>';
}
echo '</select>';

echo '<button class="btn btnPrimary" type="submit">Filtrar</button>';

echo '</form>';

echo '</section>';

echo '<section class="card col4">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:8px">Faturamento Total</div>';
echo '<div style="font-size:28px;font-weight:900;color:hsl(var(--primary))">R$ ' . number_format($faturamentoTotal, 2, ',', '.') . '</div>';
echo '<div style="margin-top:4px;font-size:12px;color:hsl(var(--muted-foreground))">Contas recebidas no período</div>';
echo '</section>';

echo '<section class="card col4">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:8px">Custo de Atendimentos</div>';
echo '<div style="font-size:28px;font-weight:900;color:hsl(var(--destructive))">R$ ' . number_format($custoAtendimentos, 2, ',', '.') . '</div>';
echo '<div style="margin-top:4px;font-size:12px;color:hsl(var(--muted-foreground))">Repasses pagos no período</div>';
echo '</section>';

echo '<section class="card col4">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:8px">Margem Operacional</div>';
$margemColor = $margemOperacional >= 0 ? 'hsl(var(--primary))' : 'hsl(var(--destructive))';
echo '<div style="font-size:28px;font-weight:900;color:' . $margemColor . '">R$ ' . number_format($margemOperacional, 2, ',', '.') . '</div>';
echo '<div style="margin-top:4px;font-size:12px;color:hsl(var(--muted-foreground))">Faturamento - Custos</div>';
echo '</section>';

echo '<section class="card col4">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:8px">Contas a Receber</div>';
echo '<div style="font-size:28px;font-weight:900">R$ ' . number_format($contasReceber, 2, ',', '.') . '</div>';
echo '<div style="margin-top:4px;font-size:12px;color:hsl(var(--muted-foreground))">Valores pendentes de recebimento</div>';
echo '</section>';

echo '<section class="card col4">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:8px">Contas a Pagar</div>';
echo '<div style="font-size:28px;font-weight:900">R$ ' . number_format($contasPagar, 2, ',', '.') . '</div>';
echo '<div style="margin-top:4px;font-size:12px;color:hsl(var(--muted-foreground))">Repasses pendentes de pagamento</div>';
echo '</section>';

echo '<section class="card col4">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:8px">Inadimplência</div>';
echo '<div style="font-size:28px;font-weight:900;color:hsl(var(--destructive))">R$ ' . number_format($inadimplencia, 2, ',', '.') . '</div>';
echo '<div style="margin-top:4px;font-size:12px;color:hsl(var(--muted-foreground))">Contas vencidas não recebidas</div>';
echo '</section>';

echo '</div>';

view_footer();
