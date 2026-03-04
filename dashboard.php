<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

view_header('Dashboard');

$user = auth_user();

$kpiAtendRealizados = 0;
$kpiAtendRecusados = 0;
$kpiFaturamentoTotal = 0.0;
$kpiCustosAndamento = 0.0;
$kpiReceber = 0.0;
$kpiPagar = 0.0;

try {
    $kpiAtendRealizados = (int)db()->query("SELECT COUNT(*) AS c FROM appointments WHERE status = 'realizado'")->fetch()['c'];
} catch (Throwable $e) {
}

try {
    $kpiAtendRecusados = (int)db()->query("SELECT COUNT(*) AS c FROM appointments WHERE status = 'cancelado'")->fetch()['c'];
} catch (Throwable $e) {
}

try {
    $kpiFaturamentoTotal = (float)db()->query("SELECT IFNULL(SUM(amount),0) AS s FROM finance_accounts_receivable WHERE status IN ('recebido')")->fetch()['s'];
} catch (Throwable $e) {
}

try {
    $kpiCustosAndamento = (float)db()->query("SELECT IFNULL(SUM(amount),0) AS s FROM finance_accounts_payable WHERE status IN ('pendente')")->fetch()['s'];
} catch (Throwable $e) {
}

try {
    $kpiReceber = (float)db()->query("SELECT IFNULL(SUM(amount),0) AS s FROM finance_accounts_receivable WHERE status IN ('pendente','inadimplente')")->fetch()['s'];
} catch (Throwable $e) {
}

try {
    $kpiPagar = (float)db()->query("SELECT IFNULL(SUM(amount),0) AS s FROM finance_accounts_payable WHERE status IN ('pendente')")->fetch()['s'];
} catch (Throwable $e) {
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
echo '<div class="kpiTop"><div class="kpiIcon" style="background:hsla(var(--primary)/.10);color:hsl(var(--primary-darker))">R$</div><div class="kpiChange">+18%</div></div>';
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
echo '<div class="chartBox"><div class="chartBoxInner">Chart placeholder</div></div>';
echo '</section>';

echo '<section class="card col6">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px">';
echo '<div style="font-weight:900">Atendimentos por Mês</div>';
echo '<a class="btn" href="/appointments_list.php">Ver</a>';
echo '</div>';
echo '<div class="chartBox"><div class="chartBoxInner">Chart placeholder</div></div>';
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
