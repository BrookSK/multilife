<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();

$db = db();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS document_send_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        document_id INT UNSIGNED NOT NULL DEFAULT 0,
        document_source VARCHAR(50) NOT NULL DEFAULT 'manual',
        recipient_type VARCHAR(50) NOT NULL DEFAULT 'professional',
        recipient_id INT UNSIGNED NULL,
        recipient_email VARCHAR(255) NULL,
        assignment_id INT UNSIGNED NULL,
        demand_id INT UNSIGNED NULL,
        health_insurer_id INT UNSIGNED NULL,
        send_method VARCHAR(30) NOT NULL DEFAULT 'email',
        sent_by_user_id INT UNSIGNED NULL,
        file_name VARCHAR(255) NULL,
        file_path VARCHAR(500) NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

$logs = [];
try {
    $logs = $db->query("SELECT * FROM document_send_logs ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

view_header('Documentos Enviados');
echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div><div style="font-size:22px;font-weight:900">Documentos Enviados</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Historico de documentos enviados para profissionais e pacientes</div></div>';
echo '<a class="btn" href="/documents_list.php">Voltar</a>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
if (empty($logs)) {
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum documento enviado ainda</div>';
    echo '<div style="font-size:14px">Os documentos aparecerao aqui apos aprovacao de atendimentos ou envio manual.</div>';
    echo '</div>';
} else {
    echo '<div style="overflow:auto"><table><thead><tr><th>Data</th><th>Documento</th><th>Destinatario</th><th>Metodo</th><th>Origem</th></tr></thead><tbody>';
    foreach ($logs as $l) {
        $mt = ($l['send_method'] ?? '') === 'email' ? 'E-mail' : ucfirst($l['send_method'] ?? '');
        $or = ($l['document_source'] ?? '') === 'insurer' ? 'Auto' : 'Manual';
        echo '<tr>';
        echo '<td style="font-size:12px">' . date('d/m/Y H:i', strtotime($l['created_at'])) . '</td>';
        echo '<td>' . htmlspecialchars($l['file_name'] ?? '-') . '</td>';
        echo '<td style="font-size:12px">' . htmlspecialchars($l['recipient_email'] ?? '-') . '</td>';
        echo '<td style="font-size:11px">' . $mt . '</td>';
        echo '<td style="font-size:11px">' . $or . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';
echo '</div>';
view_footer();
