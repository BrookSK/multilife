<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$colsStmt = db()->prepare('SHOW COLUMNS FROM inbound_emails');
$colsStmt->execute();
$cols = [];
foreach ($colsStmt->fetchAll() as $c) {
    if (isset($c['Field'])) {
        $cols[(string)$c['Field']] = true;
    }
}

$hasMailboxKey = isset($cols['mailbox_key']);
$hasFromEmail = isset($cols['from_email']);
$hasFromAddress = isset($cols['from_address']);
$hasLinkedDemandId = isset($cols['linked_demand_id']);

$allowed = ['', 'received', 'ai_pending', 'ai_processed', 'archived', 'processed', 'error'];
if (!in_array($status, $allowed, true)) {
    $status = '';
}

$selectMailboxKey = $hasMailboxKey ? 'e.mailbox_key' : "'demands' AS mailbox_key";
$selectFromEmail = $hasFromEmail ? 'e.from_email' : ($hasFromAddress ? 'e.from_address AS from_email' : 'NULL AS from_email');
$selectFromName = isset($cols['from_name']) ? 'e.from_name' : 'NULL AS from_name';
$selectLinkedDemandId = $hasLinkedDemandId ? 'e.linked_demand_id' : 'NULL AS linked_demand_id';
$selectProcessedAt = isset($cols['processed_at']) ? 'e.processed_at' : 'NULL AS processed_at';

$sql = "SELECT e.id, $selectMailboxKey, e.message_id, $selectFromEmail, $selectFromName, e.subject, e.received_at, e.status, $selectLinkedDemandId, e.error_message, $selectProcessedAt
        FROM inbound_emails e
        WHERE 1=1";

if ($hasMailboxKey) {
    $sql .= " AND e.mailbox_key = 'demands'";
}
$params = [];

if ($status !== '') {
    $st = $status;
    if (!$hasMailboxKey) {
        if ($st === 'ai_processed') {
            $st = 'processed';
        } elseif ($st === 'ai_pending') {
            $st = 'received';
        } elseif ($st === 'archived') {
            $st = 'processed';
        }
    }
    $sql .= ' AND e.status = :st';
    $params['st'] = $st;
}

if ($q !== '') {
    $fromField = $hasFromEmail ? 'e.from_email' : ($hasFromAddress ? 'e.from_address' : '');
    $sql .= ' AND (';
    $parts = [];
    if ($fromField !== '') {
        $parts[] = "$fromField LIKE :q";
    }
    $parts[] = 'e.subject LIKE :q';
    $parts[] = 'e.message_id LIKE :q';
    $parts[] = 'CAST(e.body_text AS CHAR) LIKE :q';
    $sql .= implode(' OR ', $parts) . ')';
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
