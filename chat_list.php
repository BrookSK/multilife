<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

$status = isset($_GET['status']) ? (string)$_GET['status'] : 'open';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

if (!in_array($status, ['open', 'closed'], true)) {
    $status = 'open';
}

$sql = 'SELECT c.id, c.external_phone, c.contact_kind, c.status, c.assigned_user_id, c.last_message_at, c.last_message_preview, c.created_at,
               u.name AS assigned_user_name
        FROM chat_conversations c
        LEFT JOIN users u ON u.id = c.assigned_user_id
        WHERE c.status = :status';
$params = ['status' => $status];

if ($q !== '') {
    $sql .= ' AND (c.external_phone LIKE :q OR c.last_message_preview LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' ORDER BY COALESCE(c.last_message_at, c.created_at) DESC, c.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$conversations = $stmt->fetchAll();

view_header('Chat Interno');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">Chat Interno</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.5">Conversas pvt recebidas (estilo WhatsApp Web). O contato sempre inicia.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/chat_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<option value="open"' . ($status === 'open' ? ' selected' : '') . '>Abertas</option>';
echo '<option value="closed"' . ($status === 'closed' ? ' selected' : '') . '>Histórico (Finalizadas)</option>';
echo '</select>';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar por telefone ou mensagem" style="flex:1;min-width:240px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table style="width:100%;border-collapse:separate;border-spacing:0 10px">';
echo '<thead><tr style="text-align:left;color:rgba(234,240,255,.72);font-size:12px">';
echo '<th>ID</th><th>Telefone</th><th>Tipo</th><th>Responsável</th><th>Última</th><th>Prévia</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($conversations as $c) {
    $assigned = $c['assigned_user_name'] ? (string)$c['assigned_user_name'] : '-';
    $lastAt = $c['last_message_at'] ? (string)$c['last_message_at'] : (string)$c['created_at'];
    $preview = $c['last_message_preview'] ? (string)$c['last_message_preview'] : '';

    echo '<tr style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10)">';
    echo '<td style="padding:12px;border-top-left-radius:14px;border-bottom-left-radius:14px">' . (int)$c['id'] . '</td>';
    echo '<td style="padding:12px">' . h((string)$c['external_phone']) . '</td>';
    echo '<td style="padding:12px">' . h((string)$c['contact_kind']) . '</td>';
    echo '<td style="padding:12px">' . h($assigned) . '</td>';
    echo '<td style="padding:12px">' . h($lastAt) . '</td>';
    echo '<td style="padding:12px">' . h(mb_strimwidth($preview, 0, 90, '...')) . '</td>';
    echo '<td style="padding:12px;border-top-right-radius:14px;border-bottom-right-radius:14px;text-align:right">';
    echo '<a class="btn" href="/chat_view.php?id=' . (int)$c['id'] . '">Abrir</a>';
    echo '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
