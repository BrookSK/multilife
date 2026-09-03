<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
// Aceita finance.manage (escrita) ou finance.view (somente leitura)
$uid = auth_user_id();
if (!rbac_user_can($uid, 'finance.manage') && !rbac_user_can($uid, 'finance.view')) {
    rbac_require_permission('finance.manage'); // Vai exibir "Acesso Negado"
}

$period = isset($_GET['period']) ? (string)$_GET['period'] : 'month';
$professionalId = isset($_GET['professional_id']) ? (int)$_GET['professional_id'] : 0;
$specialty = isset($_GET['specialty']) ? trim((string)$_GET['specialty']) : '';
$city = isset($_GET['city']) ? trim((string)$_GET['city']) : '';
// ITEM 12: período personalizado por datas
$dateFrom = isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_from']) ? (string)$_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_to']) ? (string)$_GET['date_to'] : '';
$useCustomRange = ($dateFrom !== '' && $dateTo !== '');
if ($useCustomRange) { $period = 'custom'; }

$allowedPeriods = ['day', 'week', 'month', 'year', 'custom'];
if (!in_array($period, $allowedPeriods, true)) {
    $period = 'month';
}

$dateFilter = '';
switch ($period) {
    case 'day':
        $dateFilter = 'DATE(pa.created_at) = CURDATE()';
        break;
    case 'week':
        $dateFilter = 'YEARWEEK(pa.created_at, 1) = YEARWEEK(CURDATE(), 1)';
        break;
    case 'month':
        $dateFilter = 'YEAR(pa.created_at) = YEAR(CURDATE()) AND MONTH(pa.created_at) = MONTH(CURDATE())';
        break;
    case 'year':
        $dateFilter = 'YEAR(pa.created_at) = YEAR(CURDATE())';
        break;
    case 'custom':
        $dateFilter = "DATE(pa.created_at) BETWEEN " . db()->quote($dateFrom) . " AND " . db()->quote($dateTo);
        break;
}

$baseWhere = [$dateFilter];
$params = [];

if ($professionalId > 0) {
    $baseWhere[] = 'pa.professional_user_id = :prof_id';
    $params['prof_id'] = $professionalId;
}

if ($specialty !== '') {
    $baseWhere[] = 'pa.specialty = :specialty';
    $params['specialty'] = $specialty;
}

if ($city !== '') {
    $baseWhere[] = 'p.address_city = :city';
    $params['city'] = $city;
}

$whereClause = implode(' AND ', $baseWhere);

$db = db();

// ============================================================================
// FONTE ÚNICA DE VERDADE: financial_entries
// Receitas e despesas são calculadas apenas da tabela financial_entries
// para evitar contagem dupla (patient_assignments + financial_entries)
// ============================================================================

$dateFilterFinancial = '';
switch ($period) {
    case 'day':
        $dateFilterFinancial = 'DATE(fe.entry_date) = CURDATE()';
        break;
    case 'week':
        $dateFilterFinancial = 'YEARWEEK(fe.entry_date, 1) = YEARWEEK(CURDATE(), 1)';
        break;
    case 'month':
        $dateFilterFinancial = 'YEAR(fe.entry_date) = YEAR(CURDATE()) AND MONTH(fe.entry_date) = MONTH(CURDATE())';
        break;
    case 'year':
        $dateFilterFinancial = 'YEAR(fe.entry_date) = YEAR(CURDATE())';
        break;
    case 'custom':
        $dateFilterFinancial = "DATE(fe.entry_date) BETWEEN " . db()->quote($dateFrom) . " AND " . db()->quote($dateTo);
        break;
}

// Receitas (income): tudo que foi aprovado no faturamento
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(fe.amount), 0) AS total
     FROM financial_entries fe
     WHERE fe.entry_type = 'income' AND fe.status IN ('pending', 'paid') AND fe.is_active = 1 AND $dateFilterFinancial"
);
$stmt->execute();
$faturamentoTotal = (float)$stmt->fetchColumn();

// Despesas (expense): custos com profissionais + outras despesas
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(fe.amount), 0) AS total
     FROM financial_entries fe
     WHERE fe.entry_type = 'expense' AND fe.status IN ('pending', 'paid') AND fe.is_active = 1 AND $dateFilterFinancial"
);
$stmt->execute();
$custoAtendimentos = (float)$stmt->fetchColumn();

$margemOperacional = $faturamentoTotal - $custoAtendimentos;
$lucroLiquido = $margemOperacional;

// Valores JÁ RECEBIDOS (apenas status 'paid')
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(fe.amount), 0) AS total
     FROM financial_entries fe
     WHERE fe.entry_type = 'income' AND fe.status = 'paid' AND fe.is_active = 1 AND $dateFilterFinancial"
);
$stmt->execute();
$recebidoTotal = (float)$stmt->fetchColumn();

// Valores JÁ PAGOS (apenas status 'paid')
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(fe.amount), 0) AS total
     FROM financial_entries fe
     WHERE fe.entry_type = 'expense' AND fe.status = 'paid' AND fe.is_active = 1 AND $dateFilterFinancial"
);
$stmt->execute();
$pagoTotal = (float)$stmt->fetchColumn();

// Compat: manter variáveis antigas usadas na view
$valoresRecebidos = $recebidoTotal;
$valoresPagos = $pagoTotal;

// Número de atendimentos
$stmt = $db->prepare(
    "SELECT COUNT(*) AS total
     FROM patient_assignments pa
     INNER JOIN patients p ON p.id = pa.patient_id
     WHERE $whereClause"
);
$stmt->execute($params);
$numAtendimentos = (int)$stmt->fetchColumn();

// Atendimentos cancelados
$stmt = $db->prepare(
    "SELECT COUNT(*) AS total
     FROM patient_assignments pa
     INNER JOIN patients p ON p.id = pa.patient_id
     WHERE pa.status = 'cancelled' AND $whereClause"
);
$stmt->execute($params);
$numCancelados = (int)$stmt->fetchColumn();

// Atendimentos por especialidade
$stmt = $db->prepare(
    "SELECT pa.specialty, COUNT(*) as count
     FROM patient_assignments pa
     INNER JOIN patients p ON p.id = pa.patient_id
     WHERE $whereClause
     GROUP BY pa.specialty
     ORDER BY count DESC
     LIMIT 10"
);
$stmt->execute($params);
$atendimentosPorEspecialidade = $stmt->fetchAll();

// Atendimentos por operadora
$stmt = $db->prepare(
    "SELECT 
        COALESCE(hi.name, 'Não informado') as operadora,
        COUNT(*) as count,
        SUM(pa.authorized_value) as total_receita
     FROM patient_assignments pa
     INNER JOIN patients p ON p.id = pa.patient_id
     LEFT JOIN health_insurers hi ON hi.id = pa.health_insurer_id
     WHERE $whereClause
     GROUP BY pa.health_insurer_id, hi.name
     ORDER BY count DESC"
);
$stmt->execute($params);
$atendimentosPorOperadora = $stmt->fetchAll();

// Buscar todas as operadoras ativas para mostrar mesmo com zero atendimentos
$todasOperadoras = $db->query("SELECT id, name FROM health_insurers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$operadorasComDados = [];
foreach ($atendimentosPorOperadora as $op) {
    $operadorasComDados[$op['operadora']] = [
        'count' => (int)$op['count'],
        'total_receita' => (float)$op['total_receita']
    ];
}
// Adicionar operadoras sem atendimentos
foreach ($todasOperadoras as $op) {
    if (!isset($operadorasComDados[$op['name']])) {
        $operadorasComDados[$op['name']] = ['count' => 0, 'total_receita' => 0];
    }
}
// Adicionar "Não informado" se houver atendimentos sem operadora
if (!isset($operadorasComDados['Não informado'])) {
    $operadorasComDados['Não informado'] = ['count' => 0, 'total_receita' => 0];
}

// Dados do período anterior (últimos 30 dias antes do período atual)
$previousDateFilter = '';
switch ($period) {
    case 'day':
        $previousDateFilter = 'DATE(pa.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)';
        break;
    case 'week':
        $previousDateFilter = 'YEARWEEK(pa.created_at, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)';
        break;
    case 'month':
        $previousDateFilter = 'YEAR(pa.created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(pa.created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))';
        break;
    case 'year':
        $previousDateFilter = 'YEAR(pa.created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 YEAR))';
        break;
}

$previousWhere = array_merge([$previousDateFilter], array_slice($baseWhere, 1));
$previousWhereClause = implode(' AND ', $previousWhere);

// Faturamento período anterior
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(pa.authorized_value), 0) AS total
     FROM patient_assignments pa
     INNER JOIN patients p ON p.id = pa.patient_id
     WHERE pa.status IN ('confirmed', 'approved', 'completed', 'paid') AND pa.authorized_value IS NOT NULL AND pa.authorized_value > 0 AND $previousWhereClause"
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
     FROM patient_assignments pa
     INNER JOIN patients p ON p.id = pa.patient_id
     WHERE $previousWhereClause"
);
$stmt->execute($params);
$numAtendimentosPrevious = (int)$stmt->fetchColumn();

$crescimentoAtendimentos = 0;
if ($numAtendimentosPrevious > 0) {
    $crescimentoAtendimentos = (($numAtendimentos - $numAtendimentosPrevious) / $numAtendimentosPrevious) * 100;
}

// Contas a receber: atendimentos aprovados mas ainda não pagos
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(pa.authorized_value), 0) AS total
     FROM patient_assignments pa
     INNER JOIN patients p ON p.id = pa.patient_id
     WHERE pa.status IN ('confirmed', 'approved', 'completed') AND pa.authorized_value IS NOT NULL AND pa.authorized_value > 0 AND $whereClause"
);
$stmt->execute($params);
$contasReceberAtendimentos = (float)$stmt->fetchColumn();

// Contas a receber de lançamentos financeiros
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(fe.amount), 0) AS total
     FROM financial_entries fe
     WHERE fe.entry_type = 'income' AND fe.status = 'pending'"
);
$stmt->execute();
$contasReceberLancamentos = (float)$stmt->fetchColumn();

$contasReceber = $contasReceberAtendimentos + $contasReceberLancamentos;

// Contas a pagar: valores acordados (custos) ainda não pagos
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(pa.agreed_value), 0) AS total
     FROM patient_assignments pa
     INNER JOIN patients p ON p.id = pa.patient_id
     WHERE pa.status IN ('confirmed', 'approved', 'completed') AND pa.agreed_value IS NOT NULL AND pa.agreed_value > 0 AND $whereClause"
);
$stmt->execute($params);
$contasPagarAtendimentos = (float)$stmt->fetchColumn();

// Contas a pagar de lançamentos financeiros
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(fe.amount), 0) AS total
     FROM financial_entries fe
     WHERE fe.entry_type = 'expense' AND fe.status = 'pending'"
);
$stmt->execute();
$contasPagarLancamentos = (float)$stmt->fetchColumn();

$contasPagar = $contasPagarAtendimentos + $contasPagarLancamentos;

// Inadimplência não é mais usada no novo sistema
$inadimplencia = 0;

$professionals = $db->query(
    "SELECT u.id, u.name FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'profissional' AND u.status = 'active' ORDER BY u.name ASC"
)->fetchAll();

$specialties = $db->query(
    "SELECT DISTINCT specialty FROM patient_assignments WHERE specialty IS NOT NULL AND specialty != '' ORDER BY specialty ASC"
)->fetchAll();

$cities = $db->query(
    "SELECT DISTINCT address_city FROM patients WHERE address_city IS NOT NULL AND address_city != '' AND deleted_at IS NULL ORDER BY address_city ASC"
)->fetchAll();

// ITEM 12: Série temporal para gráficos (últimos 12 meses de receitas x despesas)
$chartData = ['labels' => [], 'income' => [], 'expense' => []];
try {
    $serieStmt = $db->query("
        SELECT DATE_FORMAT(fe.entry_date, '%Y-%m') AS mes,
               SUM(CASE WHEN fe.entry_type = 'income' THEN fe.amount ELSE 0 END) AS income,
               SUM(CASE WHEN fe.entry_type = 'expense' THEN fe.amount ELSE 0 END) AS expense
        FROM financial_entries fe
        WHERE fe.is_active = 1 AND fe.entry_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(fe.entry_date, '%Y-%m')
        ORDER BY mes ASC
    ");
    foreach ($serieStmt->fetchAll(PDO::FETCH_ASSOC) as $srow) {
        $chartData['labels'][] = date('m/Y', strtotime($srow['mes'] . '-01'));
        $chartData['income'][] = round((float)$srow['income'], 2);
        $chartData['expense'][] = round((float)$srow['expense'], 2);
    }
} catch (Throwable $e) {}

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
    $sel = ($period === $k && !$useCustomRange) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';

// Filtro por intervalo de datas personalizado (item 12)
echo '<input type="date" name="date_from" value="' . h($dateFrom) . '" title="Data inicial" placeholder="De">';
echo '<input type="date" name="date_to" value="' . h($dateTo) . '" title="Data final" placeholder="Até">';

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
if ($useCustomRange || $professionalId > 0 || $specialty !== '' || $city !== '') {
    echo '<a class="btn" href="/finance_dashboard.php">Limpar</a>';
}

echo '</form>';

echo '</section>';

// ITEM 12: Gráfico de evolução (Receitas x Despesas - últimos 12 meses)
echo '<section class="card col12" style="padding:24px">';
echo '<div style="font-size:16px;font-weight:800;margin-bottom:16px">Evolução Financeira (últimos 12 meses)</div>';
echo '<div style="position:relative;height:320px"><canvas id="financeChart"></canvas></div>';
echo '</section>';

// Cards principais - Linha 1
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
echo '<span style="font-size:14px;font-weight:700;color:hsl(142, 76%, 36%)">+ R$ ' . number_format($valoresRecebidos, 2, ',', '.') . '</span>';
echo '</div>';

echo '<div style="display:flex;justify-content:space-between;padding:10px;background:hsla(var(--destructive)/.05);border-radius:8px">';
echo '<span style="font-size:14px;font-weight:600">Saídas (Pago)</span>';
echo '<span style="font-size:14px;font-weight:700;color:hsl(var(--destructive))">- R$ ' . number_format($valoresPagos, 2, ',', '.') . '</span>';
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

$saldoFinal = $valoresRecebidos - $valoresPagos + $contasReceber - $contasPagar;
$saldoColor = $saldoFinal >= 0 ? 'hsl(142, 76%, 36%)' : 'hsl(var(--destructive))';
echo '<div style="display:flex;justify-content:space-between;padding:12px;background:hsla(var(--primary)/.08);border-radius:8px">';
echo '<span style="font-size:15px;font-weight:700">Saldo Projetado</span>';
echo '<span style="font-size:15px;font-weight:900;color:' . $saldoColor . '">R$ ' . number_format($saldoFinal, 2, ',', '.') . '</span>';
echo '</div>';

echo '</div>'; // Fecha grid de movimentações
echo '</div>'; // Fecha coluna 2

echo '</div>'; // Fecha grid de 2 colunas
echo '</section>'; // Fecha card unificado

// Card: Atendimentos por Operadora / Cliente
echo '<section class="card col12">';
echo '<div style="font-weight:700;font-size:16px;margin-bottom:16px">Atendimentos por Operadora / Cliente</div>';

if (empty($operadorasComDados)) {
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">Nenhuma operadora / cliente cadastrada</div>';
} else {
    // Ordenar por quantidade de atendimentos (decrescente)
    uasort($operadorasComDados, function($a, $b) {
        return $b['count'] - $a['count'];
    });
    
    $maxCountOp = max(array_column($operadorasComDados, 'count'));
    if ($maxCountOp === 0) $maxCountOp = 1; // Evitar divisão por zero
    
    foreach ($operadorasComDados as $nomeOperadora => $dados) {
        $count = $dados['count'];
        $receita = $dados['total_receita'];
        $percentage = ($count / $maxCountOp) * 100;
        
        // Cor da barra baseada na quantidade
        $barColor = $count > 0 ? 'hsl(var(--primary))' : 'hsl(var(--muted))';
        
        echo '<div style="margin-bottom:14px">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">';
        echo '<span style="font-size:14px;font-weight:600">' . h($nomeOperadora) . '</span>';
        echo '<div style="display:flex;gap:16px;align-items:center">';
        echo '<span style="font-size:13px;color:hsl(var(--muted-foreground))">' . $count . ' atendimento' . ($count !== 1 ? 's' : '') . '</span>';
        if ($receita > 0) {
            echo '<span style="font-size:13px;font-weight:600;color:hsl(142, 76%, 36%)">R$ ' . number_format($receita, 2, ',', '.') . '</span>';
        }
        echo '</div>';
        echo '</div>';
        
        echo '<div style="height:10px;background:hsl(var(--accent));border-radius:5px;overflow:hidden">';
        echo '<div style="height:100%;background:' . $barColor . ';width:' . $percentage . '%;transition:width 0.3s ease"></div>';
        echo '</div>';
        echo '</div>';
    }
}

echo '</section>';

// Card: Receitas e Despesas por Centro de Custo
$stmt = $db->prepare(
    "SELECT 
        COALESCE(fe.cost_center, 'Não informado') as centro_custo,
        SUM(CASE WHEN fe.entry_type = 'income' THEN fe.amount ELSE 0 END) as total_receitas,
        SUM(CASE WHEN fe.entry_type = 'expense' THEN fe.amount ELSE 0 END) as total_despesas,
        COUNT(*) as quantidade
     FROM financial_entries fe
     WHERE fe.is_active = 1 AND $whereClause
     GROUP BY fe.cost_center
     ORDER BY (total_receitas + total_despesas) DESC"
);
$stmt->execute($params);
$centrosCusto = $stmt->fetchAll();

// Buscar todos os centros de custo ativos para mostrar mesmo com zero lançamentos
$todosCentros = $db->query("SELECT id, name, color FROM cost_centers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$centrosComDados = [];
foreach ($centrosCusto as $cc) {
    $centrosComDados[$cc['centro_custo']] = [
        'receitas' => (float)$cc['total_receitas'],
        'despesas' => (float)$cc['total_despesas'],
        'quantidade' => (int)$cc['quantidade'],
        'color' => '#3b82f6'
    ];
}
// Adicionar centros sem lançamentos
foreach ($todosCentros as $cc) {
    if (!isset($centrosComDados[$cc['name']])) {
        $centrosComDados[$cc['name']] = [
            'receitas' => 0,
            'despesas' => 0,
            'quantidade' => 0,
            'color' => $cc['color']
        ];
    } else {
        $centrosComDados[$cc['name']]['color'] = $cc['color'];
    }
}
// Adicionar "Não informado" se houver lançamentos sem centro de custo
if (!isset($centrosComDados['Não informado'])) {
    $centrosComDados['Não informado'] = ['receitas' => 0, 'despesas' => 0, 'quantidade' => 0, 'color' => '#9ca3af'];
}

echo '<section class="card col12">';
echo '<div style="font-weight:700;font-size:16px;margin-bottom:16px">Receitas e Despesas por Centro de Custo</div>';

if (empty($centrosComDados)) {
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">Nenhum centro de custo cadastrado</div>';
} else {
    // Ordenar por total (receitas + despesas) decrescente
    uasort($centrosComDados, function($a, $b) {
        return ($b['receitas'] + $b['despesas']) - ($a['receitas'] + $a['despesas']);
    });
    
    $maxTotal = 0;
    foreach ($centrosComDados as $dados) {
        $total = $dados['receitas'] + $dados['despesas'];
        if ($total > $maxTotal) $maxTotal = $total;
    }
    if ($maxTotal === 0) $maxTotal = 1; // Evitar divisão por zero
    
    foreach ($centrosComDados as $nomeCentro => $dados) {
        $receitas = $dados['receitas'];
        $despesas = $dados['despesas'];
        $total = $receitas + $despesas;
        $saldo = $receitas - $despesas;
        $percentage = ($total / $maxTotal) * 100;
        
        echo '<div style="margin-bottom:16px">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">';
        echo '<div style="display:flex;align-items:center;gap:8px">';
        echo '<div style="width:12px;height:12px;border-radius:3px;background:' . h($dados['color']) . '"></div>';
        echo '<span style="font-size:14px;font-weight:600">' . h($nomeCentro) . '</span>';
        echo '</div>';
        echo '<div style="display:flex;gap:16px;align-items:center">';
        if ($receitas > 0) {
            echo '<span style="font-size:13px;color:hsl(142, 76%, 36%)">↑ R$ ' . number_format($receitas, 2, ',', '.') . '</span>';
        }
        if ($despesas > 0) {
            echo '<span style="font-size:13px;color:hsl(0, 84%, 60%)">↓ R$ ' . number_format($despesas, 2, ',', '.') . '</span>';
        }
        if ($saldo != 0) {
            $saldoColor = $saldo >= 0 ? 'hsl(142, 76%, 36%)' : 'hsl(0, 84%, 60%)';
            echo '<span style="font-size:13px;font-weight:600;color:' . $saldoColor . '">Saldo: R$ ' . number_format($saldo, 2, ',', '.') . '</span>';
        }
        echo '</div>';
        echo '</div>';
        
        echo '<div style="height:10px;background:hsl(var(--accent));border-radius:5px;overflow:hidden">';
        if ($total > 0) {
            echo '<div style="height:100%;background:' . h($dados['color']) . ';width:' . $percentage . '%;transition:width 0.3s ease"></div>';
        }
        echo '</div>';
        echo '</div>';
    }
}

echo '</section>';

echo '</div>';

// ITEM 12: Gráfico com Chart.js
echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
echo '<script>';
echo 'var _fcLabels = ' . json_encode($chartData['labels']) . ';';
echo 'var _fcIncome = ' . json_encode($chartData['income']) . ';';
echo 'var _fcExpense = ' . json_encode($chartData['expense']) . ';';
echo 'document.addEventListener("DOMContentLoaded", function(){';
echo '  var el = document.getElementById("financeChart"); if(!el || typeof Chart === "undefined") return;';
echo '  new Chart(el, {';
echo '    type: "bar",';
echo '    data: { labels: _fcLabels, datasets: [';
echo '      { label: "Receitas", data: _fcIncome, backgroundColor: "rgba(16,185,129,0.7)", borderRadius: 4 },';
echo '      { label: "Despesas", data: _fcExpense, backgroundColor: "rgba(220,38,38,0.7)", borderRadius: 4 }';
echo '    ]},';
echo '    options: { responsive: true, maintainAspectRatio: false,';
echo '      plugins: { legend: { position: "top" }, tooltip: { callbacks: { label: function(c){ return c.dataset.label + ": R$ " + c.parsed.y.toLocaleString("pt-BR", {minimumFractionDigits:2}); } } } },';
echo '      scales: { y: { beginAtZero: true, ticks: { callback: function(v){ return "R$ " + v.toLocaleString("pt-BR"); } } } }';
echo '    }';
echo '  });';
echo '});';
echo '</script>';

view_footer();
