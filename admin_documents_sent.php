<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$db = db();

// Garantir que a tabela existe
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS document_send_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            document_id INT UNSIGNED NOT NULL,
            document_source VARCHAR(50) NOT NULL DEFAULT 'insurer',
            recipient_type VARCHAR(50) NOT NULL,
            recipient_id INT UNSIGNED NULL,
            recipient_email VARCHAR(255) NULL,
            assignment_id INT UNSIGNED NULL,
            demand_id INT UNSIGNED NULL,
            health_insurer_id INT UNSIGNED NULL,
            send_method VARCHAR(30) NOT NULL DEFAULT 'email',
            sent_by_user_id INT UNSIGNED NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_document (document_id),
            INDEX idx_recipient (recipient_type, recipient_id),
            INDEX idx_assignment (assignment_id),
            INDEX idx_insurer (health_insurer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) {}

// Filtros
$filterRecipient = trim((string)($_GET['recipient'] ?? ''));
$filterInsurer = (int)($_GET['insurer_id'] ?? 0);
$filterDemand = (int)($_GET['demand_id'] ?? 0);

// Buscar logs de envio
$where = [];
$params = [];

if ($filterRecipient !== '') {
    $where[] = "(u.name LIKE :r OR p.full_name LIKE :r OR dsl.recipient_email LIKE :r)";
    $params['r'] = '%' . $filterRecipient . '%';
}
if ($filterInsurer > 0) {
    $where[] = "dsl.health_insurer_id = :ins";
    $params['ins'] = $filterInsurer;
}
if ($filterDemand > 0) {
    $where[] = "dsl.demand_id = :did";
    $params['did'] = $filterDemand;
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT dsl.*, 
           hid.file_name, hid.file_path,
           hi.name as insurer_name,
           u.name as recipient_name,
           p.full_name as patient_name,
           sender.name as sent_by_name
    FROM document_send_logs dsl
    LEFT JOIN health_insurer_documents hid ON hid.id = dsl.document_id
    LEFT JOIN health_insurers hi ON hi.id = dsl.health_insurer_id
    LEFT JOIN users u ON u.id = dsl.recipient_id AND dsl.recipient_type = 'professional'
    LEFT JOIN patients p ON p.id = dsl.recipient_id AND dsl.recipient_type = 'patient'
    LEFT JOIN users sender ON sender.id = dsl.sent_by_user_id
    $whereClause
    ORDER BY dsl.created_at DESC
    LIMIT 100
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar operadoras para filtro
$insurers = $db->query("SELECT id, name FROM health_insurers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Buscar profissionais e pacientes para envio manual
$professionals = $db->query("
    SELECT u.id, u.name, u.email FROM users u
    INNER JOIN user_roles ur ON ur.user_id = u.id
    INNER JOIN roles r ON r.id = ur.role_id
    WHERE u.status = 'active' AND r.slug = 'profissional'
    ORDER BY u.name
")->fetchAll(PDO::FETCH_ASSOC);

$patients = $db->query("SELECT id, full_name, email FROM patients WHERE deleted_at IS NULL ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

view_header('Gerenciamento de Documentos Enviados');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Gerenciamento de Documentos</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Visualize documentos enviados automaticamente e envie documentos extras manualmente</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px">';
echo '<button onclick="document.getElementById(\'sendModal\').style.display=\'flex\'" class="btn btnPrimary">Enviar Documento Manual</button>';
echo '<a class="btn" href="/admin_dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

// Filtros
echo '<form method="get" style="display:flex;gap:12px;flex-wrap:wrap;margin-top:16px">';
echo '<input name="recipient" value="' . h($filterRecipient) . '" placeholder="Buscar destinatário..." style="flex:1;min-width:200px">';
echo '<select name="insurer_id" style="min-width:180px">';
echo '<option value="">Todas operadoras</option>';
foreach ($insurers as $ins) {
    $sel = ($filterInsurer === (int)$ins['id']) ? ' selected' : '';
    echo '<option value="' . (int)$ins['id'] . '"' . $sel . '>' . h($ins['name']) . '</option>';
}
echo '</select>';
echo '<button class="btn" type="submit">Filtrar</button>';
if ($filterRecipient !== '' || $filterInsurer > 0 || $filterDemand > 0) {
    echo '<a class="btn" href="/admin_documents_sent.php">Limpar</a>';
}
echo '</form>';

echo '</section>';

// Tabela de logs
echo '<section class="card col12">';

if (empty($logs)) {
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum documento enviado</div>';
    echo '<div style="font-size:14px">Os documentos aparecerão aqui quando forem enviados automaticamente na aprovação ou manualmente.</div>';
    echo '</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Data/Hora</th>';
    echo '<th>Documento</th>';
    echo '<th>Operadora</th>';
    echo '<th>Destinatário</th>';
    echo '<th>Método</th>';
    echo '<th>Enviado por</th>';
    echo '<th>Observação</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($logs as $log) {
        $recipientLabel = '';
        if ($log['recipient_type'] === 'professional') {
            $recipientLabel = ($log['recipient_name'] ?? $log['recipient_email'] ?? '-');
        } elseif ($log['recipient_type'] === 'patient') {
            $recipientLabel = ($log['patient_name'] ?? $log['recipient_email'] ?? '-');
        } else {
            $recipientLabel = $log['recipient_email'] ?? '-';
        }
        
        $methodLabels = ['email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'portal' => 'Portal'];
        $methodLabel = $methodLabels[$log['send_method']] ?? $log['send_method'];
        
        $senderLabel = $log['sent_by_name'] ?? 'Automático';
        
        $icon = '';
        $fileName = $log['file_name'] ?? 'Documento removido';
        if (preg_match('/\.pdf$/i', $fileName)) $icon = '📄';
        elseif (preg_match('/\.(doc|docx)$/i', $fileName)) $icon = '📝';
        elseif (preg_match('/\.(xls|xlsx)$/i', $fileName)) $icon = '📊';
        else $icon = '📎';
        
        echo '<tr>';
        echo '<td style="font-size:12px;white-space:nowrap">' . date('d/m/Y H:i', strtotime($log['created_at'])) . '</td>';
        echo '<td>';
        if (!empty($log['file_path'])) {
            echo '<a href="' . h($log['file_path']) . '" target="_blank" style="color:hsl(var(--primary));text-decoration:none;font-weight:500">' . $icon . ' ' . h($fileName) . '</a>';
        } else {
            echo '<span style="color:hsl(var(--muted-foreground))">' . $icon . ' ' . h($fileName) . '</span>';
        }
        echo '</td>';
        echo '<td>' . h($log['insurer_name'] ?? '-') . '</td>';
        echo '<td>' . h($recipientLabel) . '</td>';
        echo '<td><span style="font-size:12px;padding:2px 8px;background:hsl(var(--secondary));border-radius:4px">' . h($methodLabel) . '</span></td>';
        echo '<td style="font-size:12px">' . h($senderLabel) . '</td>';
        echo '<td style="font-size:12px;color:hsl(var(--muted-foreground));max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . h($log['notes'] ?? '') . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';
echo '</div>';

// Modal de Envio Manual
echo '<div id="sendModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display=\'none\'">';
echo '<div style="background:#fff;border-radius:12px;padding:24px;max-width:600px;width:100%;max-height:90vh;overflow-y:auto">';
echo '<h2 style="margin:0 0 20px;font-size:20px;font-weight:700">Enviar Documento Manual</h2>';

echo '<form method="post" action="/admin_documents_send_post.php" enctype="multipart/form-data">';

echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">';
echo '<div>';
echo '<label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Destinatário *</label>';
echo '<select name="recipient_type" id="recipientType" onchange="toggleRecipient()" required style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">';
echo '<option value="">Selecione...</option>';
echo '<option value="professional">Profissional</option>';
echo '<option value="patient">Paciente</option>';
echo '</select>';
echo '</div>';
echo '<div id="recipientSelectDiv">';
echo '<label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Selecionar</label>';
echo '<select name="recipient_id" id="recipientSelect" required style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">';
echo '<option value="">Selecione o destinatário acima</option>';
echo '</select>';
echo '</div>';
echo '</div>';

echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">';
echo '<div>';
echo '<label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Operadora (opcional)</label>';
echo '<select name="health_insurer_id" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">';
echo '<option value="">Nenhuma</option>';
foreach ($insurers as $ins) {
    echo '<option value="' . (int)$ins['id'] . '">' . h($ins['name']) . '</option>';
}
echo '</select>';
echo '</div>';
echo '<div>';
echo '<label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Método de Envio *</label>';
echo '<select name="send_method" required style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">';
echo '<option value="email">E-mail</option>';
echo '<option value="portal">Disponibilizar no Portal</option>';
echo '</select>';
echo '</div>';
echo '</div>';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Arquivo *</label>';
echo '<input type="file" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">';
echo '<div style="font-size:11px;color:hsl(var(--muted-foreground));margin-top:4px">PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, WEBP (máx. 10MB)</div>';
echo '</div>';

echo '<div style="margin-bottom:20px">';
echo '<label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Observação</label>';
echo '<textarea name="notes" rows="2" placeholder="Motivo do envio, referência ao atendimento..." style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px;resize:vertical"></textarea>';
echo '</div>';

echo '<div style="display:flex;gap:12px">';
echo '<button type="button" onclick="document.getElementById(\'sendModal\').style.display=\'none\'" class="btn" style="flex:1">Cancelar</button>';
echo '<button type="submit" class="btn btnPrimary" style="flex:1">Enviar</button>';
echo '</div>';

echo '</form>';
echo '</div>';
echo '</div>';

// JavaScript para alternar selects de destinatário
echo '<script>';
echo 'const professionals = ' . json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'email' => $p['email'] ?? ''], $professionals)) . ';';
echo 'const patients = ' . json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['full_name'], 'email' => $p['email'] ?? ''], $patients)) . ';';
echo 'function toggleRecipient() {';
echo '  const type = document.getElementById("recipientType").value;';
echo '  const select = document.getElementById("recipientSelect");';
echo '  select.innerHTML = "<option value=\\'\\'>Selecione...</option>";';
echo '  const list = type === "professional" ? professionals : (type === "patient" ? patients : []);';
echo '  list.forEach(item => {';
echo '    const opt = document.createElement("option");';
echo '    opt.value = item.id;';
echo '    opt.textContent = item.name + (item.email ? " (" + item.email + ")" : "");';
echo '    select.appendChild(opt);';
echo '  });';
echo '}';
echo '</script>';

view_footer();
