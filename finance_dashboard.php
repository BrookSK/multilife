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

// Receitas de contas a receber (sistema antigo)
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(ar.amount), 0) AS total
     FROM finance_accounts_receivable ar
     INNER JOIN appointments a ON a.id = ar.appointment_id
     INNER JOIN patients p ON p.id = ar.patient_id
     WHERE ar.status IN ('recebido') AND $whereClause"
);
$stmt->execute($params);
$receitasContasReceber = (float)$stmt->fetchColumn();

// Receitas de lançamentos financeiros (sistema de faturamento)
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(fe.amount), 0) AS total
     FROM financial_entries fe
     WHERE fe.entry_type = 'income' AND fe.status IN ('pending', 'paid')"
);
$stmt->execute();
$receitasFaturamento = (float)$stmt->fetchColumn();

$faturamentoTotal = $receitasContasReceber + $receitasFaturamento;

// Despesas de contas a pagar (sistema antigo)
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(ap.amount), 0) AS total
     FROM finance_accounts_payable ap
     INNER JOIN appointments a ON a.id = ap.appointment_id
     INNER JOIN patients p ON p.id = a.patient_id
     WHERE ap.status IN ('pago') AND $whereClause"
);
$stmt->execute($params);
$despesasContasPagar = (float)$stmt->fetchColumn();

// Despesas de lançamentos financeiros (sistema de faturamento)
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(fe.amount), 0) AS total
     FROM financial_entries fe
     WHERE fe.entry_type = 'expense' AND fe.status IN ('pending', 'paid')"
);
$stmt->execute();
$despesasFaturamento = (float)$stmt->fetchColumn();

$custoAtendimentos = $despesasContasPagar + $despesasFaturamento;

$margemOperacional = $faturamentoTotal - $custoAtendimentos;
$lucroLiquido = $margemOperacional;

// Número de atendimentos
$stmt = $db->prepare(
    "SELECT COUNT(*) AS total
     FROM appointments a
     INNER JOIN patients p ON p.id = a.patient_id
     WHERE $whereClause"
);
$stmt->execute($params);
$numAtendimentos = (int)$stmt->fetchColumn();

// Atendimentos cancelados
$stmt = $db->prepare(
    "SELECT COUNT(*) AS total
     FROM appointments a
     INNER JOIN patients p ON p.id = a.patient_id
     WHERE a.status = 'cancelado' AND $whereClause"
);
$stmt->execute($params);
$numCancelados = (int)$stmt->fetchColumn();

// Atendimentos por especialidade
$stmt = $db->prepare(
    "SELECT a.specialty, COUNT(*) as count
     FROM appointments a
     INNER JOIN patients p ON p.id = a.patient_id
     WHERE $whereClause
     GROUP BY a.specialty
     ORDER BY count DESC
     LIMIT 10"
);
$stmt->execute($params);
$atendimentosPorEspecialidade = $stmt->fetchAll();

// Dados do período anterior (últimos 30 dias antes do período atual)
$previousDateFilter = '';
switch ($period) {
    case 'day':
        $previousDateFilter = 'DATE(a.first_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)';
        break;
    case 'week':
        $previousDateFilter = 'YEARWEEK(a.first_at, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)';
        break;
    case 'month':
        $previousDateFilter = 'YEAR(a.first_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(a.first_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))';
        break;
    case 'year':
        $previousDateFilter = 'YEAR(a.first_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 YEAR))';
        break;
}

$previousWhere = array_merge([$previousDateFilter], array_slice($baseWhere, 1));
$previousWhereClause = implode(' AND ', $previousWhere);

// Faturamento período anterior
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(ar.amount), 0) AS total
     FROM finance_accounts_receivable ar
     INNER JOIN appointments a ON a.id = ar.appointment_id
     INNER JOIN patients p ON p.id = ar.patient_id
     WHERE ar.status IN ('recebido') AND $previousWhereClause"
);
$stmt->execute($params);
$faturamentoPrevious = (float)$stmt->fetchColumn();

// Calcular crescimento
$crescimentoFaturamento = 0;
if ($faturamentoPrevious > 0) {
    $crescimentoFaturamento = (($faturamentoTotal - $faturamentoPrevious) / $faturamentoPrevious) * 100;
}

// Número de atendimentos período anterior
$stmt = $db->prepare(
    "SELECT COUNT(*) AS total
     FROM appointments a
     INNER JOIN patients p ON p.id = a.patient_id
     WHERE $previousWhereClause"
);
$stmt->execute($params);
$numAtendimentosPrevious = (int)$stmt->fetchColumn();

$crescimentoAtendimentos = 0;
if ($numAtendimentosPrevious > 0) {
    $crescimentoAtendimentos = (($numAtendimentos - $numAtendimentosPrevious) / $numAtendimentosPrevious) * 100;
}

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

// Cards principais - Linha 1 (com padding aumentado)
echo '<section class="card col3" style="padding:24px">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:10px">Número de Atendimentos</div>';
echo '<div style="font-size:36px;font-weight:900;color:hsl(var(--foreground))">' . $numAtendimentos . '</div>';
$crescAtendIcon = $crescimentoAtendimentos >= 0 ? '↑' : '↓';
$crescAtendColor = $crescimentoAtendimentos >= 0 ? 'hsl(142, 76%, 36%)' : 'hsl(var(--destructive))';
echo '<div style="margin-top:6px;font-size:13px;color:' . $crescAtendColor . ';font-weight:600">';
echo $crescAtendIcon . ' ' . number_format(abs($crescimentoAtendimentos), 1) . '% vs período anterior';
echo '</div>';
echo '</section>';

echo '<section class="card col3" style="padding:24px">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:10px">Atendimentos Cancelados</div>';
echo '<div style="font-size:36px;font-weight:900;color:hsl(var(--destructive))">' . $numCancelados . '</div>';
$taxaCancelamento = $numAtendimentos > 0 ? ($numCancelados / $numAtendimentos) * 100 : 0;
echo '<div style="margin-top:6px;font-size:13px;color:hsl(var(--muted-foreground))">';
echo number_format($taxaCancelamento, 1) . '% do total';
echo '</div>';
echo '</section>';

echo '<section class="card col3" style="padding:24px">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:10px">Faturamento Total</div>';
echo '<div style="font-size:36px;font-weight:900;color:hsl(var(--primary))">R$ ' . number_format($faturamentoTotal, 2, ',', '.') . '</div>';
$crescFatIcon = $crescimentoFaturamento >= 0 ? '↑' : '↓';
$crescFatColor = $crescimentoFaturamento >= 0 ? 'hsl(142, 76%, 36%)' : 'hsl(var(--destructive))';
echo '<div style="margin-top:6px;font-size:13px;color:' . $crescFatColor . ';font-weight:600">';
echo $crescFatIcon . ' ' . number_format(abs($crescimentoFaturamento), 1) . '% vs período anterior';
echo '</div>';
echo '</section>';

echo '<section class="card col3" style="padding:24px">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:10px">Custo Total</div>';
echo '<div style="font-size:36px;font-weight:900;color:hsl(var(--destructive))">R$ ' . number_format($custoAtendimentos, 2, ',', '.') . '</div>';
echo '<div style="margin-top:6px;font-size:13px;color:hsl(var(--muted-foreground))">Repasses pagos</div>';
echo '</section>';

// Cards secundários - Linha 2
echo '<section class="card col4" style="padding:24px">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:10px">Lucro Líquido Real</div>';
$lucroColor = $lucroLiquido >= 0 ? 'hsl(142, 76%, 36%)' : 'hsl(var(--destructive))';
echo '<div style="font-size:32px;font-weight:900;color:' . $lucroColor . '">R$ ' . number_format($lucroLiquido, 2, ',', '.') . '</div>';
$margemPercentual = $faturamentoTotal > 0 ? ($lucroLiquido / $faturamentoTotal) * 100 : 0;
echo '<div style="margin-top:6px;font-size:13px;color:hsl(var(--muted-foreground))">Margem: ' . number_format($margemPercentual, 1) . '%</div>';
echo '</section>';

echo '<section class="card col4" style="padding:24px">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:10px">Contas a Receber</div>';
echo '<div style="font-size:32px;font-weight:900;color:hsl(var(--foreground))">R$ ' . number_format($contasReceber, 2, ',', '.') . '</div>';
echo '<div style="margin-top:6px;font-size:13px;color:hsl(var(--muted-foreground))">Pendente de recebimento</div>';
echo '</section>';

echo '<section class="card col4" style="padding:24px">';
echo '<div style="font-size:14px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:10px">Contas a Pagar</div>';
echo '<div style="font-size:32px;font-weight:900;color:hsl(var(--foreground))">R$ ' . number_format($contasPagar, 2, ',', '.') . '</div>';
echo '<div style="margin-top:6px;font-size:13px;color:hsl(var(--muted-foreground))">Pendente de pagamento</div>';
echo '</section>';

// Atendimentos por Especialidade + Movimentações (UNIFICADO)
echo '<section class="card col12">';
echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">';
echo '<div style="font-weight:700;font-size:16px">Atendimentos por Especialidade</div>';
echo '<a href="/finance_entries_list.php" class="btn" style="font-size:13px;padding:6px 12px">Ver Mais Lançamentos →</a>';
echo '</div>';

echo '<div style="display:grid;grid-template-columns:1fr 400px;gap:24px">';

// Coluna 1: Atendimentos por Especialidade
echo '<div>';
if (empty($atendimentosPorEspecialidade)) {
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">Nenhum atendimento no período</div>';
} else {
    $maxCount = max(array_column($atendimentosPorEspecialidade, 'count'));
    foreach ($atendimentosPorEspecialidade as $spec) {
        $specialty = $spec['specialty'] ?? 'Não especificado';
        $count = (int)$spec['count'];
        $percentage = $maxCount > 0 ? ($count / $maxCount) * 100 : 0;
        
        echo '<div style="margin-bottom:12px">';
        echo '<div style="display:flex;justify-content:space-between;margin-bottom:4px">';
        echo '<span style="font-size:13px;font-weight:600">' . h($specialty) . '</span>';
        echo '<span style="font-size:13px;color:hsl(var(--muted-foreground))">' . $count . ' atendimentos</span>';
        echo '</div>';
        echo '<div style="height:8px;background:hsl(var(--accent));border-radius:4px;overflow:hidden">';
        echo '<div style="height:100%;background:hsl(var(--primary));width:' . $percentage . '%"></div>';
        echo '</div>';
        echo '</div>';
    }
}
echo '</div>';

// Coluna 2: Balanço de Movimentações
echo '<div style="border-left:1px solid hsl(var(--border));padding-left:24px">';
echo '<div style="font-weight:600;font-size:14px;margin-bottom:12px;color:hsl(var(--muted-foreground))">Balanço de Movimentações</div>';
echo '<div style="display:grid;gap:10px">';

echo '<div style="display:flex;justify-content:space-between;padding:10px;background:hsla(var(--primary)/.05);border-radius:8px">';
echo '<span style="font-size:14px;font-weight:600">Entradas (Recebido)</span>';
echo '<span style="font-size:14px;font-weight:700;color:hsl(142, 76%, 36%)">+ R$ ' . number_format($faturamentoTotal, 2, ',', '.') . '</span>';
echo '</div>';

echo '<div style="display:flex;justify-content:space-between;padding:10px;background:hsla(var(--destructive)/.05);border-radius:8px">';
echo '<span style="font-size:14px;font-weight:600">Saídas (Pago)</span>';
echo '<span style="font-size:14px;font-weight:700;color:hsl(var(--destructive))">- R$ ' . number_format($custoAtendimentos, 2, ',', '.') . '</span>';
echo '</div>';

echo '<div style="display:flex;justify-content:space-between;padding:10px;background:hsl(var(--accent));border-radius:8px">';
echo '<span style="font-size:14px;font-weight:600">A Receber (Pendente)</span>';
echo '<span style="font-size:14px;font-weight:700">R$ ' . number_format($contasReceber, 2, ',', '.') . '</span>';
echo '</div>';

echo '<div style="display:flex;justify-content:space-between;padding:10px;background:hsl(var(--accent));border-radius:8px">';
echo '<span style="font-size:14px;font-weight:600">A Pagar (Pendente)</span>';
echo '<span style="font-size:14px;font-weight:700">R$ ' . number_format($contasPagar, 2, ',', '.') . '</span>';
echo '</div>';

echo '<div style="height:1px;background:hsl(var(--border));margin:8px 0"></div>';

$saldoFinal = $faturamentoTotal - $custoAtendimentos + $contasReceber - $contasPagar;
$saldoColor = $saldoFinal >= 0 ? 'hsl(142, 76%, 36%)' : 'hsl(var(--destructive))';
echo '<div style="display:flex;justify-content:space-between;padding:12px;background:hsla(var(--primary)/.08);border-radius:8px">';
echo '<span style="font-size:15px;font-weight:700">Saldo Projetado</span>';
echo '<span style="font-size:15px;font-weight:900;color:' . $saldoColor . '">R$ ' . number_format($saldoFinal, 2, ',', '.') . '</span>';
echo '</div>';

echo '</div>'; // Fecha grid de movimentações
echo '</div>'; // Fecha coluna 2

echo '</div>'; // Fecha grid de 2 colunas
echo '</section>'; // Fecha card unificado

echo '</div>';

view_footer();
