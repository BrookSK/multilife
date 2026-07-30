<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('documents.manage');

$db = db();

// Garantir que a tabela existe
try {
    $db->exec("CREATE TABLE IF NOT EXISTS document_send_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        document_id INT UNSIGNED NOT NULL DEFAULT 0,
        document_source VARCHAR(50) NOT NULL DEFAULT 'insurer',
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

// Filtros
$filterRecipient = trim((string)($_GET['recipient'] ?? ''));
$filterInsurer = (int)($_GET['insurer_id'] ?? 0);

// Buscar logs
$where = [];
$params = [];

if ($filterRecipient !== '') {
    $where[] = "(dsl.recipient_email LIKE :r OR dsl.file_name LIKE :r)";
    $params['r'] = '%' . $filterRecipient . '%';
}
if ($filterInsurer > 0) {
    $where[] = "dsl.health_insurer_id = :ins";
    $params['ins'] = $filterInsurer;
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$logs = [];
try {
    $stmt = $db->prepare("
        SELECT dsl.*, hi.name as insurer_name
        FROM document_send_logs dsl
        LEFT JOIN health_insurers hi ON hi.id = dsl.health_insurer_id
        $whereClause
        ORDER BY dsl.created_at DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $logs = [];
}

// Buscar operadoras para filtro
$insurers = [];
try {
    $insurers = $db->query("SELECT id, name FROM health_insurers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Buscar profissionais e pacientes para envio manual
$professionals = [];
try {
    $professionals = $db->query("
        SELECT u.id, u.name, u.email FROM users u
        INNER JOIN user_roles ur ON ur.user_id = u.id
        INNER JOIN roles r ON r.id = ur.role_id
        WHERE u.status = 'active' AND r.slug = 'profissional'
        ORDER BY u.name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$patients = [];
try {
    $patients = $db->query("SELECT id, full_name, email FROM patients WHERE deleted_at IS NULL ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

view_header('Documentos Enviados');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Gerenciamento de Documentos Enviados</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Visualize documentos enviados automaticamente e envie documentos extras manualmente</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px">';
echo '<button onclick="document.getElementById(\'sendModal\').style.display=\'flex\'" class="btn btnPrimary">Enviar Documento</button>';
echo '<a class="btn" href="/documents_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

// Filtros
echo '<form method="get" style="display:flex;gap:12px;flex-wrap:wrap;margin-top:16px">';
echo '<input name="recipient" value="' . h($filterRecipient) . '" placeholder="Buscar..." style="flex:1;min-width:200px">';
echo '<select name="insurer_id" style="min-width:180px">';
echo '<option value="">Todas operadoras</option>';
foreach ($insurers as $ins) {
    $sel = ($filterInsurer === (int)$ins['id']) ? ' selected' : '';
    echo '<option value="' . (int)$ins['id'] . '"' . $sel . '>' . h($ins['name']) . '</option>';
}
echo '</select>';
echo '<button class="btn" type="submit">Filtrar</button>';
if ($filterRecipient !== '' || $filterInsurer > 0) {
    echo '<a class="btn" href="/admin_documents_sent.php">Limpar</a>';
}
echo '</form>';
echo '</section>';

// Tabela
echo '<section class="card col12">';
if (empty($logs)) {
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum documento enviado ainda</div>';
    echo '<div style="font-size:14px">Os documentos aparecerão aqui quando forem enviados automaticamente ou manualmente.</div>';
    echo '</div>';
} else {
    echo '<div style="overflow:auto"><table>';
    echo '<thead><tr><th>Data</th><th>Documento</th><th>Operadora</th><th>Destinatário</th><th>Método</th><th>Observação</th></tr></thead><tbody>';
    foreach ($logs as $log) {
        $fileName = $log['file_name'] ?: 'Documento';
        $methodLabels = ['email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'portal' => 'Portal'];
        $method = $methodLabels[$log['send_method']] ?? $log['send_method'];
        
        echo '<tr>';
        echo '<td style="font-size:12px;white-space:nowrap">' . date('d/m/Y H:i', strtotime($log['created_at'])) . '</td>';
        echo '<td>';
        if (!empty($log['file_path'])) {
            echo '<a href="' . h($log['file_path']) . '" target="_blank" style="color:hsl(var(--primary));text-decoration:none;font-weight:500">📄 ' . h($fileName) . '</a>';
        } else {
            echo h($fileName);
        }
        echo '</td>';
        echo '<td>' . h($log['insurer_name'] ?? '-') . '</td>';
        echo '<td>' . h($log['recipient_email'] ?? '-') . '</td>';
        echo '<td><span style="font-size:12px;padding:2px 8px;background:hsl(var(--secondary));border-radius:4px">' . h($method) . '</span></td>';
        echo '<td style="font-size:12px;color:hsl(var(--muted-foreground));max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . h($log['notes'] ?? '') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';
echo '</div>';

// Modal de Envio Manual
echo '<div id="sendModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display=\'none\'">';
echo '<div style="background:#fff;border-radius:12px;padding:24px;max-width:600px;width:100%;max-height:90vh;overflow-y:auto">';
echo '<h2 style="margin:0 0 20px;font-size:20px;font-weight:700">Enviar Documento</h2>';
echo '<form method="post" action="/admin_documents_send_post.php" enctype="multipart/form-data">';

echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">';
echo '<div>';
echo '<label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Tipo *</label>';
echo '<select name="recipient_type" id="recipientType" onchange="toggleRecipient()" required style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">';
echo '<option value="">Selecione...</option>';
echo '<option value="professional">Profissional</option>';
echo '<option value="patient">Paciente</option>';
echo '</select>';
echo '</div>';
echo '<div>';
echo '<label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Destinatário *</label>';
echo '<select name="recipient_id" id="recipientSelect" required style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">';
echo '<option value="">Selecione tipo primeiro</option>';
echo '</select>';
echo '</div>';
echo '</div>';

echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">';
echo '<div>';
echo '<label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Operadora</label>';
echo '<select name="health_insurer_id" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">';
echo '<option value="">Nenhuma</option>';
foreach ($insurers as $ins) {
    echo '<option value="' . (int)$ins['id'] . '">' . h($ins['name']) . '</option>';
}
echo '</select>';
echo '</div>';
echo '<div>';
echo '<label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Método *</label>';
echo '<select name="send_method" required style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">';
echo '<option value="email">E-mail</option>';
echo '<option value="portal">Portal</option>';
echo '</select>';
echo '</div>';
echo '</div>';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Arquivo *</label>';
echo '<input type="file" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" style="width:100%">';
echo '</div>';

echo '<div style="margin-bottom:20px">';
echo '<label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Observação</label>';
echo '<textarea name="notes" rows="2" placeholder="Motivo do envio..." style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px;resize:vertical"></textarea>';
echo '</div>';

echo '<div style="display:flex;gap:12px">';
echo '<button type="button" onclick="document.getElementById(\'sendModal\').style.display=\'none\'" class="btn" style="flex:1">Cancelar</button>';
echo '<button type="submit" class="btn btnPrimary" style="flex:1">Enviar</button>';
echo '</div>';

echo '</form>';
echo '</div>';
echo '</div>';

// JavaScript
$profJson = [];
foreach ($professionals as $p) {
    $profJson[] = ['id' => (int)$p['id'], 'label' => $p['name'] . ' (' . ($p['email'] ?? '') . ')'];
}
$patJson = [];
foreach ($patients as $p) {
    $patJson[] = ['id' => (int)$p['id'], 'label' => $p['full_name'] . ' (' . ($p['email'] ?? '') . ')'];
}

echo '<script>';
echo 'var profs=' . json_encode($profJson) . ';';
echo 'var pats=' . json_encode($patJson) . ';';
echo 'function toggleRecipient(){';
echo '  var t=document.getElementById("recipientType").value;';
echo '  var s=document.getElementById("recipientSelect");';
echo '  s.innerHTML="<option value=\\'\\'>Selecione...</option>";';
echo '  var list=t==="professional"?profs:(t==="patient"?pats:[]);';
echo '  for(var i=0;i<list.length;i++){';
echo '    var o=document.createElement("option");o.value=list[i].id;o.textContent=list[i].label;s.appendChild(o);';
echo '  }';
echo '}';
echo '</script>';

view_footer();
