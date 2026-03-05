<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

// Buscar logs de integração WhatsApp
$stmt = db()->query(
    "SELECT * FROM integration_logs 
     WHERE provider = 'evolution' 
     ORDER BY created_at DESC 
     LIMIT 200"
);
$logs = $stmt->fetchAll();

view_header('Mensagens WhatsApp - Log');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Histórico de Mensagens WhatsApp</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Logs de mensagens enviadas via Evolution API.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/whatsapp_hub.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Ação</th><th>Status</th><th>Payload</th><th>Resposta</th><th>Erro</th><th>Data</th>';
echo '</tr></thead><tbody>';

foreach ($logs as $log) {
    $statusClass = '';
    if ((string)$log['status'] === 'success') {
        $statusClass = ' style="color:hsl(var(--success))"';
    } elseif ((string)$log['status'] === 'error') {
        $statusClass = ' style="color:hsl(var(--destructive))"';
    }
    
    echo '<tr>';
    echo '<td>' . (int)$log['id'] . '</td>';
    echo '<td>' . h((string)$log['action']) . '</td>';
    echo '<td' . $statusClass . '><strong>' . h((string)$log['status']) . '</strong></td>';
    
    $payload = (string)($log['payload'] ?? '');
    echo '<td><div style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . h($payload) . '</div></td>';
    
    $response = (string)($log['response'] ?? '');
    echo '<td><div style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . h($response) . '</div></td>';
    
    $error = (string)($log['error_message'] ?? '');
    echo '<td>' . ($error !== '' ? '<span style="color:hsl(var(--destructive))">' . h($error) . '</span>' : '-') . '</td>';
    
    echo '<td style="font-size:12px">' . h((string)$log['created_at']) . '</td>';
    echo '</tr>';
}

if (count($logs) === 0) {
    echo '<tr><td colspan="7" style="text-align:center;padding:20px;color:hsl(var(--muted-foreground))">Nenhum log encontrado.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
