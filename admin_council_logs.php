<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/council_validator.php';

auth_require_login();
rbac_require_permission('professional_applications.manage');

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 50;
$offset = ($page - 1) * $limit;

$filterCouncil  = strtoupper(trim((string)($_GET['council'] ?? '')));
$filterProvider = trim((string)($_GET['provider'] ?? ''));
$filterValid    = $_GET['valid'] ?? '';

// Monta query com filtros
$where  = [];
$params = [];

if ($filterCouncil !== '' && in_array($filterCouncil, COUNCIL_SUPPORTED, true)) {
    $where[]  = 'council_abbr = :council';
    $params['council'] = $filterCouncil;
}

if ($filterProvider !== '') {
    $where[]  = 'provider_used = :provider';
    $params['provider'] = $filterProvider;
}

if ($filterValid === '1') {
    $where[] = 'valid = 1';
} elseif ($filterValid === '0') {
    $where[] = 'success = 0';
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$countSql = "SELECT COUNT(*) FROM council_validation_logs $whereClause";
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

$sql = "SELECT cvl.*, u.name AS user_name
        FROM council_validation_logs cvl
        LEFT JOIN users u ON u.id = cvl.triggered_by_user_id
        $whereClause
        ORDER BY cvl.id DESC
        LIMIT $limit OFFSET $offset";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Logs de Validação');

echo '<div class="grid">';

// Cabeçalho
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Logs de Validação de Registros</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Histórico detalhado de todas as consultas realizadas.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_council_providers.php">Provedores</a>';
echo '</div>';
echo '</div>';

// Filtros
echo '<form method="get" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';

echo '<select name="council" style="min-width:140px">';
echo '<option value="">Todos os conselhos</option>';
foreach (COUNCIL_SUPPORTED as $c) {
    $sel = ($filterCouncil === $c) ? ' selected' : '';
    echo '<option value="' . h($c) . '"' . $sel . '>' . h($c) . '</option>';
}
echo '</select>';

echo '<select name="provider" style="min-width:160px">';
echo '<option value="">Todos os provedores</option>';
$providerNames = ['Consultar.IO', 'Infosimples', 'Portal Direto (Oficial)', 'fallback_exhausted'];
foreach ($providerNames as $pn) {
    $sel = ($filterProvider === $pn) ? ' selected' : '';
    echo '<option value="' . h($pn) . '"' . $sel . '>' . h($pn) . '</option>';
}
echo '</select>';

echo '<select name="valid" style="min-width:140px">';
echo '<option value="">Todos os resultados</option>';
$sel1 = ($filterValid === '1') ? ' selected' : '';
$sel0 = ($filterValid === '0') ? ' selected' : '';
echo '<option value="1"' . $sel1 . '>Válidos</option>';
echo '<option value="0"' . $sel0 . '>Erros</option>';
echo '</select>';

echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

// Tabela de logs
echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>Data</th><th>Conselho</th><th>Registro</th><th>UF</th><th>Resultado</th><th>Nome</th><th>Provedor</th><th>Tempo</th><th>Usuário</th>';
echo '</tr></thead><tbody>';

foreach ($rows as $r) {
    $isValid  = (int)($r['valid'] ?? 0);
    $isError  = !(int)($r['success'] ?? 0);

    $badge = '';
    if ($isValid) {
        $badge = '<span style="background:hsl(142 71% 45%);color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">VÁLIDO</span>';
    } elseif ($isError) {
        $badge = '<span style="background:hsl(0 72% 51%);color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">ERRO</span>';
    } else {
        $badge = '<span style="background:hsl(38 92% 50%);color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">NÃO ENCONTRADO</span>';
    }

    $timeMs = (int)($r['response_time_ms'] ?? 0);
    $timeStr = $timeMs > 0 ? $timeMs . 'ms' : '-';

    echo '<tr>';
    echo '<td style="white-space:nowrap;font-size:13px">' . h((string)($r['created_at'] ?? '')) . '</td>';
    echo '<td style="font-weight:700">' . h((string)($r['council_abbr'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['registry_number'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['council_state'] ?? '')) . '</td>';
    echo '<td>' . $badge . '</td>';
    echo '<td>' . h((string)($r['name_found'] ?? '-')) . '</td>';
    echo '<td style="font-size:13px">' . h((string)($r['provider_used'] ?? $r['source'] ?? '-')) . '</td>';
    echo '<td style="font-size:13px">' . h($timeStr) . '</td>';
    echo '<td style="font-size:13px">' . h((string)($r['user_name'] ?? '-')) . '</td>';
    echo '</tr>';

    // Linha de erro (se houver)
    if (!empty($r['error_message'])) {
        echo '<tr><td colspan="9" style="padding:4px 12px;font-size:12px;color:hsl(0 72% 51%);background:hsl(0 72% 97%)">';
        echo '↳ ' . h((string)$r['error_message']);
        echo '</td></tr>';
    }
}

if (empty($rows)) {
    echo '<tr><td colspan="9" style="text-align:center;padding:20px;color:hsl(var(--muted-foreground))">Nenhum log encontrado.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';

// Paginação
if ($totalPages > 1) {
    echo '<div style="margin-top:14px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap">';
    $baseUrl = '/admin_council_logs.php?' . http_build_query(array_filter([
        'council'  => $filterCouncil,
        'provider' => $filterProvider,
        'valid'    => $filterValid,
    ]));

    if ($page > 1) {
        echo '<a class="btn" href="' . h($baseUrl . '&page=' . ($page - 1)) . '">← Anterior</a>';
    }
    echo '<span style="padding:8px 12px;font-size:14px;color:hsl(var(--muted-foreground))">Página ' . $page . ' de ' . $totalPages . ' (' . number_format($totalRows) . ' registros)</span>';
    if ($page < $totalPages) {
        echo '<a class="btn" href="' . h($baseUrl . '&page=' . ($page + 1)) . '">Próxima →</a>';
    }
    echo '</div>';
}

echo '</section>';

echo '</div>';

view_footer();
