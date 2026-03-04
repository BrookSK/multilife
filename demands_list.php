<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$status = isset($_GET['status']) ? (string)$_GET['status'] : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$allowedStatuses = ['','aguardando_captacao','tratamento_manual','em_captacao','admitido','cancelado'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$sql = 'SELECT d.id, d.title, d.specialty, d.location_city, d.location_state, d.status, d.assumed_by_user_id, d.created_at, u.name AS assumed_by_name
        FROM demands d
        LEFT JOIN users u ON u.id = d.assumed_by_user_id';

$where = [];
$params = [];

if ($status !== '') {
    $where[] = 'd.status = :status';
    $params['status'] = $status;
}

if ($q !== '') {
    $where[] = '(d.title LIKE :q OR d.specialty LIKE :q OR d.location_city LIKE :q OR d.origin_email LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

if (count($where) > 0) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY d.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Captação - Demandas');

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">Demandas</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.5">Cards de captação: e-mail → card → assumir → disparo em grupos.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/demands_create.php">Novo card</a>';
echo '<a class="btn" href="/whatsapp_groups_list.php">Grupos WhatsApp</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/demands_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
$opts = [
    '' => 'Todos os status',
    'aguardando_captacao' => 'Aguardando Captação',
    'tratamento_manual' => 'Tratamento Manual',
    'em_captacao' => 'Em Captação',
    'admitido' => 'Admitido',
    'cancelado' => 'Cancelado',
];
foreach ($opts as $k => $label) {
    $sel = ($status === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (título, especialidade, cidade, origem)" style="flex:1;min-width:240px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table style="width:100%;border-collapse:separate;border-spacing:0 10px">';
echo '<thead><tr style="text-align:left;color:rgba(234,240,255,.72);font-size:12px">';
echo '<th>ID</th><th>Título</th><th>Local</th><th>Especialidade</th><th>Status</th><th>Responsável</th><th>Criado</th><th style="text-align:right">Ações</th>';
echo '</tr></thead>';
echo '<tbody>';
foreach ($rows as $r) {
    $loc = trim((string)$r['location_city']);
    $uf = trim((string)$r['location_state']);
    $locTxt = $loc !== '' ? ($loc . ($uf !== '' ? '/' . $uf : '')) : '-';
    $assumed = $r['assumed_by_name'] ? (string)$r['assumed_by_name'] : '-';

    echo '<tr style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10)">';
    echo '<td style="padding:12px;border-top-left-radius:14px;border-bottom-left-radius:14px">' . (int)$r['id'] . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['title']) . '</td>';
    echo '<td style="padding:12px">' . h($locTxt) . '</td>';
    echo '<td style="padding:12px">' . h((string)($r['specialty'] ?? '-')) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['status']) . '</td>';
    echo '<td style="padding:12px">' . h($assumed) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['created_at']) . '</td>';
    echo '<td style="padding:12px;border-top-right-radius:14px;border-bottom-right-radius:14px;text-align:right">';
    echo '<a class="btn" href="/demands_view.php?id=' . (int)$r['id'] . '">Abrir</a> ';
    echo '<a class="btn" href="/demands_edit.php?id=' . (int)$r['id'] . '">Editar</a> ';
    echo '<form method="post" action="/demands_delete_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
    echo '<button class="btn" type="submit" onclick="return confirm(\'Excluir este card?\')">Excluir</button>';
    echo '</form>';
    echo '</td>';
    echo '</tr>';
}

echo '</tbody>';
echo '</table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
