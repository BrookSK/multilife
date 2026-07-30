<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();

view_header('Documentos Enviados');

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Gerenciamento de Documentos Enviados</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Visualize documentos enviados e envie documentos extras manualmente</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px">';
echo '<a class="btn" href="/documents_list.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Tentar buscar logs se a tabela existir
$logs = [];
try {
    $stmt = db()->query("SELECT * FROM document_send_logs ORDER BY created_at DESC LIMIT 50");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Tabela não existe ainda - mostrar vazio
}

echo '<section class="card col12">';
if (empty($logs)) {
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum documento enviado ainda</div>';
    echo '<div style="font-size:14px">Os documentos aparecerão aqui quando forem enviados automaticamente na aprovação ou manualmente.</div>';
    echo '</div>';
} else {
    echo '<div style="overflow:auto"><table>';
    echo '<thead><tr><th>Data</th><th>Documento</th><th>Destinatário</th><th>Método</th><th>Observação</th></tr></thead><tbody>';
    foreach ($logs as $log) {
        echo '<tr>';
        echo '<td style="font-size:12px">' . htmlspecialchars($log['created_at'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($log['file_name'] ?? 'Documento') . '</td>';
        echo '<td>' . htmlspecialchars($log['recipient_email'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($log['send_method'] ?? 'email') . '</td>';
        echo '<td style="font-size:12px">' . htmlspecialchars($log['notes'] ?? '') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';
echo '</div>';

view_footer();
