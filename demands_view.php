<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare(
    'SELECT d.*, u.name AS assumed_by_name
     FROM demands d
     LEFT JOIN users u ON u.id = d.assumed_by_user_id
     WHERE d.id = :id'
);
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /demands_list.php');
    exit;
}

$stmt = db()->prepare(
    'SELECT l.id, l.old_status, l.new_status, l.note, l.created_at, u.name AS user_name
     FROM demand_status_logs l
     LEFT JOIN users u ON u.id = l.user_id
     WHERE l.demand_id = :id
     ORDER BY l.id DESC'
);
$stmt->execute(['id' => $id]);
$statusLogs = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT dl.id, dl.message, dl.dispatch_status, dl.error_message, dl.created_at,
            g.name AS group_name, g.city, g.state, g.specialty,
            u.name AS dispatched_by_name
     FROM demand_dispatch_logs dl
     LEFT JOIN whatsapp_groups g ON g.id = dl.group_id
     LEFT JOIN users u ON u.id = dl.dispatched_by_user_id
     WHERE dl.demand_id = :id
     ORDER BY dl.id DESC'
);
$stmt->execute(['id' => $id]);
$dispatchLogs = $stmt->fetchAll();

$loc = trim((string)($d['location_city'] ?? ''));
$uf = trim((string)($d['location_state'] ?? ''));
$locTxt = $loc !== '' ? ($loc . ($uf !== '' ? '/' . $uf : '')) : '-';
$assumedBy = $d['assumed_by_name'] ? (string)$d['assumed_by_name'] : '-';

view_header('Demanda #' . (string)$d['id']);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:12px;color:rgba(234,240,255,.72);margin-bottom:6px">Card</div>';
echo '<div style="font-size:22px;font-weight:800">#' . (int)$d['id'] . ' — ' . h((string)$d['title']) . '</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">';
echo '<strong>Local:</strong> ' . h($locTxt) . ' &nbsp; <strong>Especialidade:</strong> ' . h((string)($d['specialty'] ?? '-')) . ' &nbsp; <strong>Status:</strong> ' . h((string)$d['status']) . ' &nbsp; <strong>Responsável:</strong> ' . h($assumedBy);
echo '</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/demands_list.php">Voltar</a>';
echo '<a class="btn" href="/demands_edit.php?id=' . (int)$d['id'] . '">Editar</a>';

echo '<form method="post" action="/demands_assume_post.php" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<button class="btn" type="submit">Assumir Demanda</button>';
echo '</form>';

echo '<form method="post" action="/demands_release_post.php" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<button class="btn" type="submit">Devolver</button>';
echo '</form>';

echo '<form method="post" action="/demands_dispatch_whatsapp_post.php" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<button class="btn btnPrimary" type="submit">Realizar Captação</button>';
echo '</form>';

echo '</div>';
echo '</div>';

echo '<div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<form method="post" action="/demands_set_status_post.php" style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<select name="status" style="border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
$allowed = ['aguardando_captacao','tratamento_manual','em_captacao','admitido','cancelado'];
foreach ($allowed as $st) {
    $sel = ((string)$d['status'] === $st) ? ' selected' : '';
    echo '<option value="' . h($st) . '"' . $sel . '>' . h($st) . '</option>';
}
echo '</select>';
echo '<input name="note" placeholder="Observação (opcional)" style="min-width:240px;flex:1;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<button class="btn" type="submit">Atualizar status</button>';
echo '</form>';
echo '</div>';

echo '</section>';

// Descrição

echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:8px">Detalhes</div>';
echo '<div style="color:rgba(234,240,255,.72);font-size:14px;line-height:1.7">';
echo '<div><strong>Origem:</strong> ' . h((string)($d['origin_email'] ?? '-')) . '</div>';
echo '<div style="margin-top:8px"><strong>Descrição:</strong></div>';
echo '<div style="white-space:pre-wrap">' . h((string)($d['description'] ?? '')) . '</div>';
echo '</div>';
echo '</section>';

// Logs

echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:8px">Histórico de status</div>';
echo '<div style="overflow:auto">';
echo '<table style="width:100%;border-collapse:separate;border-spacing:0 10px">';
echo '<thead><tr style="text-align:left;color:rgba(234,240,255,.72);font-size:12px">';
echo '<th>Quando</th><th>Usuário</th><th>De</th><th>Para</th><th>Obs.</th>';
echo '</tr></thead><tbody>';
foreach ($statusLogs as $l) {
    echo '<tr style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10)">';
    echo '<td style="padding:12px;border-top-left-radius:14px;border-bottom-left-radius:14px">' . h((string)$l['created_at']) . '</td>';
    echo '<td style="padding:12px">' . h((string)($l['user_name'] ?? '-')) . '</td>';
    echo '<td style="padding:12px">' . h((string)($l['old_status'] ?? '-')) . '</td>';
    echo '<td style="padding:12px">' . h((string)$l['new_status']) . '</td>';
    echo '<td style="padding:12px;border-top-right-radius:14px;border-bottom-right-radius:14px">' . h((string)($l['note'] ?? '')) . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:8px">Disparos em grupos (logs)</div>';
echo '<div style="overflow:auto">';
echo '<table style="width:100%;border-collapse:separate;border-spacing:0 10px">';
echo '<thead><tr style="text-align:left;color:rgba(234,240,255,.72);font-size:12px">';
echo '<th>Quando</th><th>Usuário</th><th>Grupo</th><th>Status</th><th>Erro</th>';
echo '</tr></thead><tbody>';
foreach ($dispatchLogs as $l) {
    $g = $l['group_name'] ? (string)$l['group_name'] : '-';
    echo '<tr style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10)">';
    echo '<td style="padding:12px;border-top-left-radius:14px;border-bottom-left-radius:14px">' . h((string)$l['created_at']) . '</td>';
    echo '<td style="padding:12px">' . h((string)($l['dispatched_by_name'] ?? '-')) . '</td>';
    echo '<td style="padding:12px">' . h($g) . '</td>';
    echo '<td style="padding:12px">' . h((string)$l['dispatch_status']) . '</td>';
    echo '<td style="padding:12px;border-top-right-radius:14px;border-bottom-right-radius:14px">' . h((string)($l['error_message'] ?? '')) . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
