<?php
/**
 * Lista de atendimentos finalizados/encerrados (itens 5/11).
 * Mostra paciente, profissional, especialidade, motivo do encerramento,
 * data/hora, quem encerrou e observações.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$uid = (int)auth_user_id();

// Controle de acesso:
// - Quem tem demands.manage (Admin, Captador, Admissão) ou audit.view (Auditoria) vê TODOS os atendimentos.
// - Profissional vê SOMENTE os atendimentos em que ele é o profissional.
$canSeeAll = rbac_user_can($uid, 'demands.manage') || rbac_user_can($uid, 'audit.view');
$isProfessional = in_array('profissional', rbac_user_roles($uid), true);

if (!$canSeeAll && !$isProfessional) {
    // Sem permissão para gerenciar/auditar e não é profissional: acesso negado.
    rbac_require_permission('demands.manage'); // dispara o bloqueio padrão do sistema
    exit;
}

// Se não pode ver tudo, mas é profissional, restringe aos atendimentos dele.
$restrictToProfessionalId = (!$canSeeAll && $isProfessional) ? $uid : 0;

$db = db();

// Garantir colunas de encerramento (fallback caso a migration não tenha rodado)
foreach ([
    "ALTER TABLE patient_assignments ADD COLUMN ended_at DATETIME NULL",
    "ALTER TABLE patient_assignments ADD COLUMN end_reason_id INT UNSIGNED NULL",
    "ALTER TABLE patient_assignments ADD COLUMN end_notes TEXT NULL",
    "ALTER TABLE patient_assignments ADD COLUMN ended_by_user_id INT UNSIGNED NULL",
] as $alter) {
    try { $db->exec($alter); } catch (Throwable $e) { /* já existe */ }
}

// Filtro de busca (paciente/profissional)
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

// Paginação
$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Cláusula WHERE + parâmetros compartilhados entre a contagem e a listagem
$whereSql = " WHERE pa.status = 'completed' AND pa.ended_at IS NOT NULL";
$params = [];

// Profissional: só enxerga os próprios atendimentos.
if ($restrictToProfessionalId > 0) {
    $whereSql .= " AND pa.professional_user_id = :prof_uid";
    $params['prof_uid'] = $restrictToProfessionalId;
}

if ($q !== '') {
    $whereSql .= " AND (p.full_name LIKE :q1 OR u.name LIKE :q2)";
    $params['q1'] = '%' . $q . '%';
    $params['q2'] = '%' . $q . '%';
}

$fromSql = "
    FROM patient_assignments pa
    LEFT JOIN patients p ON p.id = pa.patient_id
    LEFT JOIN users u ON u.id = pa.professional_user_id
    LEFT JOIN treatment_end_reasons ter ON ter.id = pa.end_reason_id
    LEFT JOIN users closer ON closer.id = pa.ended_by_user_id
";

// Total (para calcular páginas)
$countStmt = $db->prepare("SELECT COUNT(*) " . $fromSql . $whereSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Listagem paginada
$sql = "
    SELECT
        pa.id AS assignment_id,
        pa.demand_id,
        pa.specialty,
        pa.ended_at,
        pa.end_notes,
        pa.completed_at,
        p.full_name AS patient_name,
        u.name AS professional_name,
        ter.name AS end_reason_name,
        closer.name AS ended_by_name
" . $fromSql . $whereSql
    . " ORDER BY pa.ended_at DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper para preservar a busca nos links de paginação
$buildPageUrl = function (int $p) use ($q): string {
    $qs = array_filter(['q' => $q, 'page' => $p], fn($v) => $v !== '' && $v !== null);
    return '/atendimentos_finalizados.php?' . http_build_query($qs);
};

view_header('Atendimentos Finalizados');

echo '<div class="grid"><section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Atendimentos Finalizados</div>';
if ($restrictToProfessionalId > 0) {
    echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Seus atendimentos encerrados, com o motivo, data e observações.</div>';
} else {
    echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Atendimentos encerrados, com o motivo, data e responsável pelo encerramento.</div>';
}
echo '</div>';
if ($restrictToProfessionalId > 0) {
    echo '<a class="btn" href="/profissional_registros.php">Voltar</a>';
} else {
    echo '<a class="btn" href="/monitoramento.php">Voltar ao Monitoramento</a>';
}
echo '</div>';

// Busca
echo '<form method="get" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar por paciente ou profissional" style="flex:1;min-width:240px">';
echo '<button class="btn btnPrimary" type="submit">Buscar</button>';
if ($q !== '') {
    echo '<a class="btn" href="/atendimentos_finalizados.php">Limpar</a>';
}
echo '</form>';

echo '<div style="margin-top:14px;color:hsl(var(--muted-foreground));font-size:13px">' . $totalRows . ' atendimento(s) finalizado(s).</div>';

echo '<div style="overflow:auto;margin-top:12px"><table>';
echo '<thead><tr>';
echo '<th>Card</th>';
echo '<th>Paciente</th>';
echo '<th>Profissional</th>';
echo '<th>Especialidade</th>';
echo '<th>Motivo do Encerramento</th>';
echo '<th>Data do Encerramento</th>';
echo '<th>Encerrado por</th>';
echo '<th>Observações</th>';
echo '</tr></thead><tbody>';

if (count($rows) === 0) {
    echo '<tr><td colspan="8" style="text-align:center;padding:32px;color:hsl(var(--muted-foreground))">Nenhum atendimento finalizado' . ($q !== '' ? ' para essa busca' : '') . '.</td></tr>';
} else {
    foreach ($rows as $r) {
        $endedAt = (string)($r['ended_at'] ?? '');
        $endedFmt = $endedAt !== '' ? date('d/m/Y H:i', strtotime($endedAt)) : '-';
        $demandId = $r['demand_id'] ? (int)$r['demand_id'] : 0;
        echo '<tr>';
        echo '<td>' . ($demandId ? '<a href="/demands_view.php?id=' . $demandId . '">#' . $demandId . '</a>' : '-') . '</td>';
        echo '<td style="font-weight:600">' . h((string)($r['patient_name'] ?? '-')) . '</td>';
        echo '<td>' . h((string)($r['professional_name'] ?? '-')) . '</td>';
        echo '<td>' . h((string)($r['specialty'] ?? '-')) . '</td>';
        echo '<td>' . ($r['end_reason_name'] ? '<span class="badge badgeInfo">' . h((string)$r['end_reason_name']) . '</span>' : '<span style="color:hsl(var(--muted-foreground))">Sem motivo</span>') . '</td>';
        echo '<td>' . h($endedFmt) . '</td>';
        echo '<td>' . h((string)($r['ended_by_name'] ?? '-')) . '</td>';
        echo '<td style="max-width:280px">' . ($r['end_notes'] ? h((string)$r['end_notes']) : '<span style="color:hsl(var(--muted-foreground))">-</span>') . '</td>';
        echo '</tr>';
    }
}

echo '</tbody></table></div>';

// Controles de paginação
if ($totalPages > 1) {
    echo '<div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:16px;flex-wrap:wrap">';
    if ($page > 1) {
        echo '<a class="btn" href="' . h($buildPageUrl($page - 1)) . '">← Anterior</a>';
    }
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    if ($start > 1) { echo '<a class="btn" href="' . h($buildPageUrl(1)) . '">1</a>'; if ($start > 2) echo '<span style="color:hsl(var(--muted-foreground))">…</span>'; }
    for ($i = $start; $i <= $end; $i++) {
        if ($i === $page) {
            echo '<span class="btn btnPrimary" style="pointer-events:none">' . $i . '</span>';
        } else {
            echo '<a class="btn" href="' . h($buildPageUrl($i)) . '">' . $i . '</a>';
        }
    }
    if ($end < $totalPages) { if ($end < $totalPages - 1) echo '<span style="color:hsl(var(--muted-foreground))">…</span>'; echo '<a class="btn" href="' . h($buildPageUrl($totalPages)) . '">' . $totalPages . '</a>'; }
    if ($page < $totalPages) {
        echo '<a class="btn" href="' . h($buildPageUrl($page + 1)) . '">Próxima →</a>';
    }
    echo '</div>';
    echo '<div style="text-align:center;margin-top:8px;font-size:13px;color:hsl(var(--muted-foreground))">Página ' . $page . ' de ' . $totalPages . '</div>';
}

echo '</section></div>';

view_footer();
