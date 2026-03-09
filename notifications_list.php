<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('notifications.view');

$userId = auth_user_id();

// Filtros
$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$isRead = isset($_GET['is_read']) ? trim((string)$_GET['is_read']) : '';

$sql = 'SELECT id, type, title, message, link, is_read, created_at
        FROM notifications
        WHERE user_id = :uid';

$params = ['uid' => $userId];

if ($type !== '') {
    $sql .= ' AND type = :type';
    $params['type'] = $type;
}

if ($isRead !== '') {
    $sql .= ' AND is_read = :is_read';
    $params['is_read'] = (int)$isRead;
}

$sql .= ' ORDER BY created_at DESC LIMIT 200';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

view_header('Notificações');

echo '<div class="pageHeader">';
echo '<h1>📬 Notificações</h1>';
echo '<div class="pageHeaderActions">';
echo '<a href="/dashboard.php" class="btn">← Voltar</a>';
echo '</div>';
echo '</div>';

// Filtros
echo '<div class="card" style="margin-bottom:20px">';
echo '<form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">';
echo '<div style="flex:1;min-width:200px">';
echo '<label style="display:block;margin-bottom:6px;font-size:13px;font-weight:600">Tipo</label>';
echo '<select name="type" class="input">';
echo '<option value="">Todos</option>';
echo '<option value="info"' . ($type === 'info' ? ' selected' : '') . '>ℹ️ Informação</option>';
echo '<option value="success"' . ($type === 'success' ? ' selected' : '') . '>✅ Sucesso</option>';
echo '<option value="warning"' . ($type === 'warning' ? ' selected' : '') . '>⚠️ Aviso</option>';
echo '<option value="error"' . ($type === 'error' ? ' selected' : '') . '>❌ Erro</option>';
echo '</select>';
echo '</div>';

echo '<div style="flex:1;min-width:200px">';
echo '<label style="display:block;margin-bottom:6px;font-size:13px;font-weight:600">Status</label>';
echo '<select name="is_read" class="input">';
echo '<option value="">Todas</option>';
echo '<option value="0"' . ($isRead === '0' ? ' selected' : '') . '>Não lidas</option>';
echo '<option value="1"' . ($isRead === '1' ? ' selected' : '') . '>Lidas</option>';
echo '</select>';
echo '</div>';

echo '<button type="submit" class="btn btnPrimary">Filtrar</button>';
echo '<a href="/notifications_list.php" class="btn">Limpar</a>';
echo '</form>';
echo '</div>';

// Lista de notificações
if (count($notifications) === 0) {
    echo '<div class="card" style="text-align:center;padding:40px">';
    echo '<div style="font-size:48px;margin-bottom:12px">📭</div>';
    echo '<p style="color:hsl(var(--muted-foreground))">Nenhuma notificação encontrada</p>';
    echo '</div>';
} else {
    echo '<div class="card">';
    echo '<div class="table-responsive">';
    echo '<table class="table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th style="width:50px"></th>';
    echo '<th>Título</th>';
    echo '<th>Mensagem</th>';
    echo '<th style="width:150px">Data</th>';
    echo '<th style="width:100px">Ações</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    foreach ($notifications as $notif) {
        $isRead = (int)$notif['is_read'] === 1;
        $typeEmoji = match($notif['type']) {
            'success' => '✅',
            'warning' => '⚠️',
            'error' => '❌',
            default => 'ℹ️'
        };
        
        echo '<tr style="' . ($isRead ? '' : 'background:hsla(var(--primary)/.03);font-weight:600') . '">';
        echo '<td style="text-align:center">' . $typeEmoji . '</td>';
        echo '<td>' . h((string)$notif['title']) . '</td>';
        echo '<td>' . h((string)$notif['message']) . '</td>';
        echo '<td style="font-size:12px;color:hsl(var(--muted-foreground))">' . date('d/m/Y H:i', strtotime((string)$notif['created_at'])) . '</td>';
        echo '<td>';
        if (!empty($notif['link'])) {
            echo '<a href="' . h((string)$notif['link']) . '" class="btn btn-sm">Ver</a>';
        }
        if (!$isRead) {
            echo ' <button class="btn btn-sm" onclick="markAsRead(' . (int)$notif['id'] . ')">✓</button>';
        }
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

echo '<script>';
echo 'function markAsRead(id) {';
echo '  fetch("/notifications_mark_read.php", {';
echo '    method: "POST",';
echo '    headers: {"Content-Type": "application/json"},';
echo '    body: JSON.stringify({id: id})';
echo '  }).then(() => location.reload());';
echo '}';
echo '</script>';

view_footer();
