<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$allowed = ['', 'received', 'ai_pending', 'ai_processed', 'archived', 'error'];
if (!in_array($status, $allowed, true)) {
    $status = '';
}

$sql = "SELECT e.id, e.mailbox_key, e.message_id, e.from_email, e.from_name, e.subject, e.received_at, e.status, e.linked_demand_id, e.error_message, e.processed_at
        FROM inbound_emails e
        WHERE e.mailbox_key = 'demands'";
$params = [];

if ($status !== '') {
    $sql .= ' AND e.status = :st';
    $params['st'] = $status;
}

if ($q !== '') {
    $sql .= ' AND (e.from_email LIKE :q OR e.subject LIKE :q OR e.message_id LIKE :q OR CAST(e.body_text AS CHAR) LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' ORDER BY e.id DESC LIMIT 300';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Inbox - Demandas (E-mails)');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Inbox de E-mails (Demandas)</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Acompanha e-mails recebidos e processados pela IA.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/demands_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/inbound_emails_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';

echo '<select name="status" style="min-width:220px">';
$opts = [
    '' => 'Todos',
    'received' => 'received',
    'ai_pending' => 'ai_pending',
    'ai_processed' => 'ai_processed',
    'archived' => 'archived',
    'error' => 'error',
];
foreach ($opts as $k => $label) {
    $sel = ($status === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';

echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (remetente, assunto, texto...)" style="flex:1;min-width:240px">';

echo '<button class="btn" type="submit">Filtrar</button>';

echo '</form>';

echo '</section>';


echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Recebido</th><th>Status</th><th>Remetente</th><th>Assunto</th><th>Demanda</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    $from = trim((string)($r['from_email'] ?? ''));
    if ($from === '') {
        $from = '-';
    }

    $subj = (string)($r['subject'] ?? '');

    $did = $r['linked_demand_id'] !== null ? (int)$r['linked_demand_id'] : 0;

    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td>' . h((string)$r['received_at']) . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td>' . h($from) . '</td>';
    echo '<td>' . h(mb_strimwidth($subj, 0, 90, '...')) . '</td>';
    echo '<td>';
    if ($did > 0) {
        echo '<a class="btn" href="/demands_view.php?id=' . $did . '">#' . $did . '</a>';
    } else {
        echo '-';
    }
    echo '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/inbound_emails_view.php?id=' . (int)$r['id'] . '">Ver</a>';
    echo '</td>';
    echo '</tr>';
}
if (count($rows) === 0) {
    echo '<tr><td colspan="7" class="pill" style="display:table-cell;padding:12px">Sem e-mails.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
