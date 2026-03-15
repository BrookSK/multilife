<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings');

require_once __DIR__ . '/app/whatsapp_template_processor.php';

$eventFilter = isset($_GET['event']) ? trim((string)$_GET['event']) : '';
$insurerFilter = isset($_GET['insurer']) ? (int)$_GET['insurer'] : 0;

$sql = 'SELECT t.id, t.name, t.event_trigger, t.health_insurer_id, t.is_active, t.created_at,
               h.name AS insurer_name,
               (SELECT COUNT(*) FROM whatsapp_template_attachments WHERE template_id = t.id) AS attachments_count
        FROM whatsapp_message_templates t
        LEFT JOIN health_insurers h ON h.id = t.health_insurer_id
        WHERE 1=1';

$params = [];

if ($eventFilter !== '') {
    $sql .= ' AND t.event_trigger = :event';
    $params['event'] = $eventFilter;
}

if ($insurerFilter > 0) {
    $sql .= ' AND t.health_insurer_id = :insurer';
    $params['insurer'] = $insurerFilter;
}

$sql .= ' ORDER BY t.event_trigger, h.name, t.name';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$templates = $stmt->fetchAll();

$insurersStmt = db()->query('SELECT id, name FROM health_insurers WHERE is_active = 1 ORDER BY name');
$insurers = $insurersStmt->fetchAll();

$events = whatsapp_get_available_events();

view_header('Templates WhatsApp');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Templates de Mensagem WhatsApp</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.5">Gerenciar templates personalizados por operadora e evento</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/admin_whatsapp_templates_edit.php">+ Novo Template</a>';
echo '<a class="btn" href="/admin_settings.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/admin_whatsapp_templates.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="event" style="min-width:240px">';
echo '<option value="">Todos os Eventos</option>';
foreach ($events as $key => $label) {
    $sel = $eventFilter === $key ? ' selected' : '';
    echo '<option value="' . h($key) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';

echo '<select name="insurer" style="min-width:240px">';
echo '<option value="0">Todas as Operadoras</option>';
foreach ($insurers as $ins) {
    $sel = $insurerFilter === (int)$ins['id'] ? ' selected' : '';
    echo '<option value="' . (int)$ins['id'] . '"' . $sel . '>' . h((string)$ins['name']) . '</option>';
}
echo '</select>';

echo '<button class="btn" type="submit">Filtrar</button>';
echo '<a class="btn" href="/admin_whatsapp_templates.php">Limpar</a>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Nome</th><th>Evento</th><th>Operadora</th><th>Anexos</th><th>Status</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';

if (empty($templates)) {
    echo '<tr><td colspan="7" style="text-align:center;padding:40px;color:hsl(var(--muted-foreground))">Nenhum template encontrado</td></tr>';
} else {
    foreach ($templates as $t) {
        $eventLabel = $events[$t['event_trigger']] ?? h((string)$t['event_trigger']);
        $insurerName = $t['insurer_name'] ? h((string)$t['insurer_name']) : '<em>Todas</em>';
        $attachmentsCount = (int)$t['attachments_count'];
        $statusBadge = (int)$t['is_active'] === 1 
            ? '<span class="badge badgeSuccess">Ativo</span>' 
            : '<span class="badge badgeDanger">Inativo</span>';
        
        echo '<tr>';
        echo '<td>' . (int)$t['id'] . '</td>';
        echo '<td style="font-weight:700">' . h((string)$t['name']) . '</td>';
        echo '<td>' . h($eventLabel) . '</td>';
        echo '<td>' . $insurerName . '</td>';
        echo '<td>';
        if ($attachmentsCount > 0) {
            echo '<span class="badge">📎 ' . $attachmentsCount . '</span>';
        } else {
            echo '-';
        }
        echo '</td>';
        echo '<td>' . $statusBadge . '</td>';
        echo '<td style="text-align:right">';
        echo '<a class="btn btnSmall" href="/admin_whatsapp_templates_edit.php?id=' . (int)$t['id'] . '">Editar</a> ';
        echo '<form method="post" action="/admin_whatsapp_templates_delete_post.php" style="display:inline" onsubmit="return confirm(\'Confirma exclusão?\');">';
        echo '<input type="hidden" name="id" value="' . (int)$t['id'] . '">';
        echo '<button class="btn btnSmall btnDanger" type="submit">Excluir</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
