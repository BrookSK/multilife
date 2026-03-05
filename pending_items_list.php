<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

$status = isset($_GET['status']) ? (string)$_GET['status'] : 'open';
$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$mine = isset($_GET['mine']) ? (string)$_GET['mine'] : '1';

if (!in_array($status, ['open', 'done', 'dismissed', 'all'], true)) {
    $status = 'open';
}

$sql = 'SELECT p.*, u.name AS assigned_user_name FROM pending_items p LEFT JOIN users u ON u.id = p.assigned_user_id WHERE 1=1';
$params = [];

if ($status !== 'all') {
    $sql .= ' AND p.status = :st';
    $params['st'] = $status;
}

if ($type !== '') {
    $sql .= ' AND p.type = :tp';
    $params['tp'] = $type;
}

if ($mine === '1') {
    $sql .= ' AND (p.assigned_user_id = :uid OR p.assigned_user_id IS NULL)';
    $params['uid'] = auth_user_id();
}

$sql .= ' ORDER BY p.created_at DESC, p.id DESC LIMIT 300';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$types = db()->query('SELECT DISTINCT type FROM pending_items ORDER BY type ASC')->fetchAll();

view_header('Pendências');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Pendências</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Alertas automáticos e tarefas pendentes (ex: chats sem resposta).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/pending_items_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="min-width:200px">';
foreach (['open' => 'Abertas', 'done' => 'Concluídas', 'dismissed' => 'Dispensadas', 'all' => 'Todas'] as $k => $lbl) {
    echo '<option value="' . h($k) . '"' . ($status === $k ? ' selected' : '') . '>' . h($lbl) . '</option>';
}

echo '</select>';

echo '<select name="type" style="min-width:240px">';
echo '<option value="">Tipo (todos)</option>';
foreach ($types as $t) {
    $tv = (string)$t['type'];
    echo '<option value="' . h($tv) . '"' . ($type === $tv ? ' selected' : '') . '>' . h($tv) . '</option>';
}

echo '</select>';

echo '<label style="display:flex;align-items:center;gap:8px">';
echo '<input type="checkbox" name="mine" value="1"' . ($mine === '1' ? ' checked' : '') . '>'; 
echo '<span style="font-size:13px">Minhas / Sem responsável</span>';
echo '</label>';

echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Tipo</th><th>Status</th><th>Título</th><th>Responsável</th><th>Criada</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';

foreach ($rows as $r) {
    $assigned = $r['assigned_user_name'] ? (string)$r['assigned_user_name'] : '-';

    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td>' . h((string)$r['type']) . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td>' . h((string)$r['title']) . '</td>';
    echo '<td>' . h($assigned) . '</td>';
    echo '<td>' . h((string)$r['created_at']) . '</td>';

    echo '<td style="text-align:right">';
    if ((string)$r['related_table'] === 'chat_conversations' && $r['related_id'] !== null) {
        echo '<a class="btn" href="/chat_web.php?id=' . (int)$r['related_id'] . '">Abrir chat</a>';
    }

    if ((string)$r['type'] === 'patient_feedback' && (string)$r['related_table'] === 'appointments' && $r['related_id'] !== null) {
        $aid = (int)$r['related_id'];
        echo '<a class="btn" href="/appointments_view.php?id=' . $aid . '">Abrir agendamento</a>';

        try {
            $stmt = db()->prepare('SELECT demand_id FROM appointments WHERE id = :id');
            $stmt->execute(['id' => $aid]);
            $a = $stmt->fetch();
            if ($a && $a['demand_id'] !== null) {
                echo ' <a class="btn" href="/demands_view.php?id=' . (int)$a['demand_id'] . '">Abrir demanda</a>';
            }
        } catch (Throwable $e) {
        }
    }

    if ((string)$r['type'] === 'appointment_cycle_renewal' && (string)$r['related_table'] === 'appointments' && $r['related_id'] !== null) {
        echo '<a class="btn btnPrimary" href="/appointments_renew_cycle.php?appointment_id=' . (int)$r['related_id'] . '" style="font-size:11px;padding:4px 8px">Renovar</a>';
        echo ' <a class="btn" href="/appointments_view.php?id=' . (int)$r['related_id'] . '" style="font-size:11px;padding:4px 8px">Ver agendamento</a>';
    }

    if ((string)$r['type'] === 'appointment_review' && (string)$r['related_table'] === 'appointments' && $r['related_id'] !== null) {
        echo '<a class="btn" href="/appointments_view.php?id=' . (int)$r['related_id'] . '" style="font-size:11px;padding:4px 8px">Revisar agendamento</a>';
    }

    echo ' ';
    echo '<a class="btn" href="/pending_items_set_status_post.php?id=' . (int)$r['id'] . '&status=done">Concluir</a>';
    echo ' ';
    echo '<a class="btn" href="/pending_items_set_status_post.php?id=' . (int)$r['id'] . '&status=dismissed">Dispensar</a>';
    echo '</td>';

    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
