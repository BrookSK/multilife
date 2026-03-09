<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.dashboard');

view_header('Dashboard');

$user = auth_user();

$kpiAtendRealizados = 0;
$kpiAtendRecusados = 0;
$kpiCaptacoesAtivas = 0;
$kpiCaptacoesPendentes = 0;
$kpiFaturamentoTotal = 0.0;
$kpiCustosAndamento = 0.0;
$kpiReceber = 0.0;
$kpiPagar = 0.0;

try {
    // Atendimentos realizados (patient_assignments com status completed ou paid)
    $result = db()->query("
        SELECT COUNT(*) AS c 
        FROM patient_assignments pa
        INNER JOIN patients p ON p.id = pa.patient_id
        WHERE p.deleted_at IS NULL AND pa.status IN ('completed', 'paid')
    ");
    if ($result) {
        $kpiAtendRealizados = (int)($result->fetch()['c'] ?? 0);
    }
    error_log("[DASHBOARD] Atendimentos Realizados: " . $kpiAtendRealizados);
} catch (Throwable $e) {
    error_log("[DASHBOARD] Erro em atendimentos realizados: " . $e->getMessage());
}

try {
    // Captações canceladas (demands com status 'cancelado')
    $result = db()->query("
        SELECT COUNT(*) AS c 
        FROM demands
        WHERE status = 'cancelado'
    ");
    if ($result) {
        $kpiAtendRecusados = (int)($result->fetch()['c'] ?? 0);
    }
    error_log("[DASHBOARD] Captações Canceladas: " . $kpiAtendRecusados);
} catch (Throwable $e) {
    error_log("[DASHBOARD] Erro em captações canceladas: " . $e->getMessage());
}

try {
    $result = db()->query("SELECT COUNT(*) AS c FROM demands WHERE status IN ('em_captacao', 'tratamento_manual')");
    if ($result) {
        $kpiCaptacoesAtivas = (int)($result->fetch()['c'] ?? 0);
    }
} catch (Throwable $e) {
    error_log("[DASHBOARD] Erro em captações ativas: " . $e->getMessage());
}

try {
    $result = db()->query("SELECT COUNT(*) AS c FROM demands WHERE status = 'aguardando_captacao'");
    if ($result) {
        $kpiCaptacoesPendentes = (int)($result->fetch()['c'] ?? 0);
    }
} catch (Throwable $e) {
    error_log("[DASHBOARD] Erro em captações pendentes: " . $e->getMessage());
}

try {
    // MESMA QUERY da aba finance_receivable_list.php - Faturamento Total (recebido)
    $result = db()->query("
        SELECT IFNULL(SUM(pa.authorized_value), 0) AS s
        FROM patient_assignments pa
        INNER JOIN patients p ON p.id = pa.patient_id
        WHERE p.deleted_at IS NULL 
        AND pa.authorized_value IS NOT NULL 
        AND pa.authorized_value > 0
        AND pa.status = 'paid'
    ");
    if ($result) {
        $row = $result->fetch();
        $kpiFaturamentoTotal = (float)($row['s'] ?? 0.0);
    }
    
    // Adicionar receitas de financial_entries
    $result = db()->query("
        SELECT IFNULL(SUM(amount), 0) AS s
        FROM financial_entries
        WHERE entry_type = 'income' AND status = 'paid'
    ");
    if ($result) {
        $row = $result->fetch();
        $kpiFaturamentoTotal += (float)($row['s'] ?? 0.0);
    }
    
    error_log("[DASHBOARD] Faturamento Total: R$ " . number_format($kpiFaturamentoTotal, 2));
} catch (Throwable $e) {
    error_log("[DASHBOARD] Erro em faturamento total: " . $e->getMessage());
}

try {
    // MESMA QUERY da aba finance_payable_list.php - Custos em Andamento (pendente)
    $result = db()->query("
        SELECT IFNULL(SUM(pa.agreed_value), 0) AS s
        FROM patient_assignments pa
        WHERE pa.agreed_value IS NOT NULL 
        AND pa.agreed_value > 0
        AND pa.status IN ('approved', 'completed', 'confirmed')
    ");
    if ($result) {
        $row = $result->fetch();
        $kpiCustosAndamento = (float)($row['s'] ?? 0.0);
    }
    
    // Adicionar despesas de financial_entries
    $result = db()->query("
        SELECT IFNULL(SUM(amount), 0) AS s
        FROM financial_entries
        WHERE entry_type = 'expense' AND status = 'pending'
    ");
    if ($result) {
        $row = $result->fetch();
        $kpiCustosAndamento += (float)($row['s'] ?? 0.0);
    }
    
    error_log("[DASHBOARD] Custos em Andamento: R$ " . number_format($kpiCustosAndamento, 2));
} catch (Throwable $e) {
    error_log("[DASHBOARD] Erro em custos: " . $e->getMessage());
}

try {
    // MESMA QUERY da aba finance_receivable_list.php - Contas a Receber (pendente)
    $result = db()->query("
        SELECT IFNULL(SUM(pa.authorized_value), 0) AS s
        FROM patient_assignments pa
        INNER JOIN patients p ON p.id = pa.patient_id
        WHERE p.deleted_at IS NULL 
        AND pa.authorized_value IS NOT NULL 
        AND pa.authorized_value > 0
        AND pa.status IN ('approved', 'completed', 'confirmed')
    ");
    if ($result) {
        $row = $result->fetch();
        $kpiReceber = (float)($row['s'] ?? 0.0);
    }
    
    // Adicionar receitas pendentes de financial_entries
    $result = db()->query("
        SELECT IFNULL(SUM(amount), 0) AS s
        FROM financial_entries
        WHERE entry_type = 'income' AND status = 'pending'
    ");
    if ($result) {
        $row = $result->fetch();
        $kpiReceber += (float)($row['s'] ?? 0.0);
    }
    
    error_log("[DASHBOARD] Contas a Receber: R$ " . number_format($kpiReceber, 2));
} catch (Throwable $e) {
    error_log("[DASHBOARD] Erro em contas a receber: " . $e->getMessage());
}

try {
    // MESMA QUERY da aba finance_payable_list.php - Contas a Pagar (total)
    $result = db()->query("
        SELECT IFNULL(SUM(pa.agreed_value), 0) AS s
        FROM patient_assignments pa
        WHERE pa.agreed_value IS NOT NULL 
        AND pa.agreed_value > 0
    ");
    if ($result) {
        $row = $result->fetch();
        $kpiPagar = (float)($row['s'] ?? 0.0);
    }
    
    // Adicionar despesas de financial_entries
    $result = db()->query("
        SELECT IFNULL(SUM(amount), 0) AS s
        FROM financial_entries
        WHERE entry_type = 'expense'
    ");
    if ($result) {
        $row = $result->fetch();
        $kpiPagar += (float)($row['s'] ?? 0.0);
    }
    
    error_log("[DASHBOARD] Contas a Pagar: R$ " . number_format($kpiPagar, 2));
} catch (Throwable $e) {
    error_log("[DASHBOARD] Erro em contas a pagar: " . $e->getMessage());
}

error_log("[DASHBOARD] === RESUMO MÉTRICAS ===");
error_log("[DASHBOARD] Atendimentos Realizados: " . $kpiAtendRealizados);
error_log("[DASHBOARD] Atendimentos Cancelados: " . $kpiAtendRecusados);
error_log("[DASHBOARD] Captações Ativas: " . $kpiCaptacoesAtivas);
error_log("[DASHBOARD] Captações Pendentes: " . $kpiCaptacoesPendentes);

// Dados para gráfico de faturamento - últimos 6 meses
$chartFaturamento = [];
try {
    $stmt = db()->query("
        SELECT 
            DATE_FORMAT(pa.created_at, '%Y-%m') as mes,
            SUM(pa.authorized_value) as total
        FROM patient_assignments pa
        INNER JOIN patients p ON p.id = pa.patient_id
        WHERE p.deleted_at IS NULL 
        AND pa.authorized_value IS NOT NULL 
        AND pa.authorized_value > 0
        AND pa.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(pa.created_at, '%Y-%m')
        ORDER BY mes ASC
    ");
    $chartFaturamento = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Adicionar financial_entries
    $stmt = db()->query("
        SELECT 
            DATE_FORMAT(entry_date, '%Y-%m') as mes,
            SUM(amount) as total
        FROM financial_entries
        WHERE entry_type = 'income'
        AND entry_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(entry_date, '%Y-%m')
        ORDER BY mes ASC
    ");
    $chartFaturamentoEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Combinar os dois arrays
    foreach ($chartFaturamentoEntries as $entry) {
        $found = false;
        foreach ($chartFaturamento as &$item) {
            if ($item['mes'] === $entry['mes']) {
                $item['total'] += $entry['total'];
                $found = true;
                break;
            }
        }
        if (!$found) {
            $chartFaturamento[] = $entry;
        }
    }
} catch (Throwable $e) {
    error_log("[DASHBOARD] Erro em gráfico faturamento: " . $e->getMessage());
}

// Dados para gráfico de atendimentos - últimos 6 meses
$chartAtendimentos = [];
try {
    $stmt = db()->query("
        SELECT 
            DATE_FORMAT(pa.created_at, '%Y-%m') as mes,
            COUNT(*) as total
        FROM patient_assignments pa
        INNER JOIN patients p ON p.id = pa.patient_id
        WHERE p.deleted_at IS NULL
        AND pa.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(pa.created_at, '%Y-%m')
        ORDER BY mes ASC
    ");
    $chartAtendimentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[DASHBOARD] Erro em gráfico atendimentos: " . $e->getMessage());
}

$recentDemands = [];
try {
    $stmt = db()->prepare(
        'SELECT d.id, d.title, d.origin_email, d.created_at, d.status, u.name AS captador_name
         FROM demands d
         LEFT JOIN users u ON u.id = d.assumed_by_user_id
         ORDER BY d.id DESC
         LIMIT 8'
    );
    $stmt->execute();
    $recentDemands = $stmt->fetchAll();
} catch (Throwable $e) {
}

$lastBackup = null;
try {
    $stmt = db()->prepare('SELECT id, kind, status, started_at, finished_at, error_message FROM backup_runs ORDER BY id DESC LIMIT 1');
    $stmt->execute();
    $lastBackup = $stmt->fetch();
} catch (Throwable $e) {
}

// Cards de Atendimentos (4 em cima)
echo '<div class="kpiGrid">';

echo '<div class="kpiCard"><div class="kpiBody">';
echo '<div class="kpiTop"><div class="kpiIcon">OK</div><div class="kpiChange">+12%</div></div>';
echo '<div class="kpiValue">' . number_format($kpiAtendRealizados, 0, ',', '.') . '</div>';
echo '<div class="kpiLabel">Atendimentos Realizados</div>';
echo '</div></div>';

echo '<div class="kpiCard"><div class="kpiBody">';
echo '<div class="kpiTop"><div class="kpiIcon" style="background:hsla(var(--destructive)/.10);color:hsl(var(--destructive))">X</div><div class="kpiChange">-5%</div></div>';
echo '<div class="kpiValue">' . number_format($kpiAtendRecusados, 0, ',', '.') . '</div>';
echo '<div class="kpiLabel">Atendimentos Cancelados</div>';
echo '</div></div>';

echo '<div class="kpiCard"><div class="kpiBody">';
echo '<div class="kpiTop"><div class="kpiIcon" style="background:hsla(var(--primary)/.10);color:hsl(var(--primary))">HE</div><div class="kpiChange">+18%</div></div>';
echo '<div class="kpiValue">' . number_format($kpiCaptacoesAtivas, 0, ',', '.') . '</div>';
echo '<div class="kpiLabel">Captações Ativas</div>';
echo '</div></div>';

echo '<div class="kpiCard"><div class="kpiBody">';
echo '<div class="kpiTop"><div class="kpiIcon" style="background:hsla(var(--warning)/.10);color:hsl(var(--warning))">⏸</div><div class="kpiChange">+3%</div></div>';
echo '<div class="kpiValue">' . number_format($kpiCaptacoesPendentes, 0, ',', '.') . '</div>';
echo '<div class="kpiLabel">Captações Pendentes</div>';
echo '</div></div>';

echo '</div>';

echo '<div style="height:12px"></div>';

// Cards Financeiros (4 embaixo)
echo '<div class="kpiGrid">';

echo '<div class="kpiCard"><div class="kpiBody">';
echo '<div class="kpiTop"><div class="kpiIcon" style="background:hsla(var(--success)/.10);color:hsl(var(--success))">R$</div><div class="kpiChange">+18%</div></div>';
echo '<div class="kpiValue">R$ ' . number_format($kpiFaturamentoTotal, 2, ',', '.') . '</div>';
echo '<div class="kpiLabel">Faturamento Total</div>';
echo '</div></div>';

echo '<div class="kpiCard"><div class="kpiBody">';
echo '<div class="kpiTop"><div class="kpiIcon" style="background:hsla(var(--warning)/.10);color:hsl(var(--warning))">$</div><div class="kpiChange">+3%</div></div>';
echo '<div class="kpiValue">R$ ' . number_format($kpiCustosAndamento, 2, ',', '.') . '</div>';
echo '<div class="kpiLabel">Custos em Andamento</div>';
echo '</div></div>';

echo '<div class="kpiCard"><div class="kpiBody">';
echo '<div class="kpiTop"><div class="kpiIcon" style="background:hsla(var(--info)/.10);color:hsl(var(--info))">↓</div><div class="kpiChange">+8%</div></div>';
echo '<div class="kpiValue">R$ ' . number_format($kpiReceber, 2, ',', '.') . '</div>';
echo '<div class="kpiLabel">Contas a Receber</div>';
echo '</div></div>';

echo '<div class="kpiCard"><div class="kpiBody">';
echo '<div class="kpiTop"><div class="kpiIcon" style="background:hsla(var(--destructive)/.10);color:hsl(var(--destructive))">↑</div><div class="kpiChange">-2%</div></div>';
echo '<div class="kpiValue">R$ ' . number_format($kpiPagar, 2, ',', '.') . '</div>';
echo '<div class="kpiLabel">Contas a Pagar</div>';
echo '</div></div>';

echo '</div>';

echo '<div style="height:18px"></div>';

echo '<div class="grid">';

echo '<section class="card col6">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px">';
echo '<div style="font-weight:900">Faturamento — Últimos 6 meses</div>';
echo '<a class="btn" href="/finance_receivable_list.php">Ver</a>';
echo '</div>';
echo '<div style="overflow:auto">';
echo '<table><thead><tr><th>Mês</th><th style="text-align:right">Valor</th></tr></thead><tbody>';
if (empty($chartFaturamento)) {
    echo '<tr><td colspan="2" style="text-align:center;color:hsl(var(--muted-foreground))">Sem dados</td></tr>';
} else {
    foreach ($chartFaturamento as $item) {
        $mesFormatado = date('m/Y', strtotime($item['mes'] . '-01'));
        echo '<tr>';
        echo '<td>' . h($mesFormatado) . '</td>';
        echo '<td style="text-align:right;font-weight:700">R$ ' . number_format((float)$item['total'], 2, ',', '.') . '</td>';
        echo '</tr>';
    }
}
echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '<section class="card col6">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px">';
echo '<div style="font-weight:900">Atendimentos por Mês</div>';
echo '<a class="btn" href="/appointments_list.php">Ver</a>';
echo '</div>';
echo '<div style="overflow:auto">';
echo '<table><thead><tr><th>Mês</th><th style="text-align:right">Quantidade</th></tr></thead><tbody>';
if (empty($chartAtendimentos)) {
    echo '<tr><td colspan="2" style="text-align:center;color:hsl(var(--muted-foreground))">Sem dados</td></tr>';
} else {
    foreach ($chartAtendimentos as $item) {
        $mesFormatado = date('m/Y', strtotime($item['mes'] . '-01'));
        echo '<tr>';
        echo '<td>' . h($mesFormatado) . '</td>';
        echo '<td style="text-align:right;font-weight:700">' . number_format((int)$item['total'], 0, ',', '.') . '</td>';
        echo '</tr>';
    }
}
echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px">';
echo '<div style="font-weight:900">Últimas Solicitações de Captação</div>';
echo '<a class="btn" href="/demands_list.php">Abrir Kanban</a>';
echo '</div>';

echo '<div style="overflow:auto">';
echo '<table>'; 
echo '<thead><tr>';
echo '<th>ID</th><th>Título</th><th>Origem</th><th>Captador</th><th>Criado</th><th>Status</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($recentDemands as $d) {
    $capt = $d['captador_name'] ? (string)$d['captador_name'] : '-';
    $st = (string)$d['status'];
    $badgeCls = 'badgeInfo';
    if ($st === 'admitido') {
        $badgeCls = 'badgeSuccess';
    } elseif ($st === 'em_captacao' || $st === 'tratamento_manual') {
        $badgeCls = 'badgeWarn';
    } elseif ($st === 'cancelado') {
        $badgeCls = 'badgeDanger';
    }

    echo '<tr>';
    echo '<td>' . (int)$d['id'] . '</td>';
    echo '<td style="font-weight:700">' . h((string)$d['title']) . '</td>';
    echo '<td>' . h((string)($d['origin_email'] ?? '-')) . '</td>';
    echo '<td>' . h($capt) . '</td>';
    echo '<td>' . h((string)$d['created_at']) . '</td>';
    echo '<td><span class="badge ' . h($badgeCls) . '">' . h($st) . '</span></td>';
    echo '<td style="text-align:right"><a class="btn" href="/demands_view.php?id=' . (int)$d['id'] . '">Abrir</a></td>';
    echo '</tr>';
}
echo '</tbody></table>';
echo '</div>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px">';
echo '<div>';
echo '<div style="font-weight:900">Backups Automáticos</div>';
if (is_array($lastBackup)) {
    echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:13px">Último backup: ' . h((string)$lastBackup['started_at']) . ' • Status: ' . h((string)$lastBackup['status']) . '</div>';
} else {
    echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:13px">Sem histórico de backup.</div>';
}
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/backup_runs_list.php">Ver backups</a>';
echo '<form method="post" action="/backup_runs_run_post.php" style="display:inline">';
echo '<button class="btn btnPrimary" type="submit">Executar agora</button>';
echo '</form>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
