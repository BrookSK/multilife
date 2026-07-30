<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();

$db = db();

// Criar tabela se não existir
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

// Processar envio manual (POST)
$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_manual') {
    $recipientType = trim((string)($_POST['recipient_type'] ?? ''));
    $recipientId = (int)($_POST['recipient_id'] ?? 0);
    $healthInsurerId = (int)($_POST['health_insurer_id'] ?? 0);
    $sendMethod = trim((string)($_POST['send_method'] ?? 'email'));
    $notes = trim((string)($_POST['notes'] ?? ''));
    
    if ($recipientId <= 0 || $recipientType === '') {
        $error = 'Selecione um destinatário.';
    } elseif (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Selecione um arquivo.';
    } else {
        $file = $_FILES['document'];
        $fileName = $file['name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','webp'];
        
        if (!in_array($ext, $allowed)) {
            $error = 'Formato não permitido.';
        } elseif ($file['size'] > 10 * 1024 * 1024) {
            $error = 'Arquivo excede 10MB.';
        } else {
            $uploadDir = __DIR__ . '/uploads/manual_docs/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $uniqueName = time() . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
            $destPath = $uploadDir . $uniqueName;
            
            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $relativePath = '/uploads/manual_docs/' . $uniqueName;
                
                // Buscar email do destinatário
                $recipientEmail = '';
                if ($recipientType === 'professional') {
                    $s = $db->prepare("SELECT email FROM users WHERE id = ?");
                    $s->execute([$recipientId]);
                    $recipientEmail = (string)($s->fetchColumn() ?: '');
                } else {
                    $s = $db->prepare("SELECT email FROM patients WHERE id = ?");
                    $s->execute([$recipientId]);
                    $recipientEmail = (string)($s->fetchColumn() ?: '');
                }
                
                // Registrar no log
                $ins = $db->prepare("INSERT INTO document_send_logs 
                    (document_source, recipient_type, recipient_id, recipient_email, health_insurer_id, send_method, sent_by_user_id, file_name, file_path, notes)
                    VALUES ('manual', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([
                    $recipientType, $recipientId, $recipientEmail,
                    $healthInsurerId > 0 ? $healthInsurerId : null,
                    $sendMethod, auth_user_id(), $fileName, $relativePath, $notes
                ]);
                
                // Enviar por email se método for email
                if ($sendMethod === 'email' && $recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                    try {
                        require_once __DIR__ . '/app/email_base_template.php';
                        $fromEmail = (string)admin_setting_get('smtp.out.from_email', '');
                        $fromName = (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care');
                        $baseUrl = 'https://multilife.onsolutionsbrasil.com.br';
                        
                        $body = '<p style="font-size:15px;color:#374151">Olá!</p>';
                        $body .= '<p style="font-size:14px;color:#4b5563">Segue documento enviado pela equipe MultiLife Care:</p>';
                        $body .= '<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px">';
                        $body .= '<p style="margin:6px 0;font-size:14px">📄 <a href="' . $baseUrl . $relativePath . '" style="color:#0284c7">' . htmlspecialchars($fileName) . '</a></p>';
                        if ($notes !== '') $body .= '<p style="margin:8px 0 0;font-size:13px;color:#6b7280">' . htmlspecialchars($notes) . '</p>';
                        $body .= '</div>';
                        $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
                        
                        $htmlBody = email_base_layout('Documento Enviado', $body);
                        $smtp = new SmtpClient();
                        $smtp->send($fromEmail, $fromName, $recipientEmail, 'Documento - ' . $fileName, $htmlBody);
                    } catch (Throwable $e) {}
                }
                
                $success = 'Documento enviado com sucesso!';
            } else {
                $error = 'Falha ao salvar arquivo.';
            }
        }
    }
}

// Filtros
$filterQ = trim((string)($_GET['q'] ?? ''));
$filterInsurer = (int)($_GET['insurer_id'] ?? 0);

// Buscar logs
$logs = [];
try {
    $where = '';
    $params = [];
    $conditions = [];
    
    if ($filterQ !== '') {
        $conditions[] = "(file_name LIKE ? OR recipient_email LIKE ? OR notes LIKE ?)";
        $params[] = "%$filterQ%";
        $params[] = "%$filterQ%";
        $params[] = "%$filterQ%";
    }
    if ($filterInsurer > 0) {
        $conditions[] = "health_insurer_id = ?";
        $params[] = $filterInsurer;
    }
    if (!empty($conditions)) {
        $where = 'WHERE ' . implode(' AND ', $conditions);
    }
    
    $stmt = $db->prepare("SELECT * FROM document_send_logs $where ORDER BY created_at DESC LIMIT 100");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Dados para formulário
$insurers = [];
try { $insurers = $db->query("SELECT id, name FROM health_insurers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

$professionals = [];
try { $professionals = $db->query("SELECT u.id, u.name, u.email FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.status = 'active' AND r.slug = 'profissional' ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

$patientsList = [];
try { $patientsList = $db->query("SELECT id, full_name, email FROM patients WHERE deleted_at IS NULL ORDER BY full_name LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

view_header('Documentos Enviados');

// Flash messages
if ($success !== '') {
    echo '<div style="background:#d1fae5;border:1px solid #10b981;padding:12px 16px;border-radius:8px;margin-bottom:16px;color:#065f46;font-weight:600">' . htmlspecialchars($success) . '</div>';
}
if ($error !== '') {
    echo '<div style="background:#fee2e2;border:1px solid #ef4444;padding:12px 16px;border-radius:8px;margin-bottom:16px;color:#991b1b;font-weight:600">' . htmlspecialchars($error) . '</div>';
}

echo '<div class="grid">';

// Header
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Gerenciamento de Documentos Enviados</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Visualize e envie documentos para profissionais, pacientes e operadoras</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px">';
echo '<button onclick="document.getElementById(\'sendModal\').style.display=\'flex\'" class="btn btnPrimary">Enviar Documento</button>';
echo '<a class="btn" href="/documents_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

// Filtros
echo '<form method="get" style="display:flex;gap:12px;flex-wrap:wrap;margin-top:16px">';
echo '<input name="q" value="' . htmlspecialchars($filterQ) . '" placeholder="Buscar documento, destinatário..." style="flex:1;min-width:200px">';
echo '<select name="insurer_id" style="min-width:180px"><option value="">Todas operadoras</option>';
foreach ($insurers as $ins) {
    $sel = ($filterInsurer === (int)$ins['id']) ? ' selected' : '';
    echo '<option value="' . (int)$ins['id'] . '"' . $sel . '>' . htmlspecialchars($ins['name']) . '</option>';
}
echo '</select>';
echo '<button class="btn" type="submit">Filtrar</button>';
if ($filterQ !== '' || $filterInsurer > 0) echo '<a class="btn" href="/admin_documents_sent.php">Limpar</a>';
echo '</form>';
echo '</section>';

// Tabela
echo '<section class="card col12">';
if (empty($logs)) {
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum documento enviado ainda</div>';
    echo '<div style="font-size:14px">Use o botão "Enviar Documento" para enviar documentos manualmente, ou os documentos aparecerão aqui automaticamente após a aprovação de atendimentos.</div>';
    echo '</div>';
} else {
    echo '<div style="overflow:auto"><table>';
    echo '<thead><tr><th>Data</th><th>Documento</th><th>Destinatário</th><th>Tipo</th><th>Método</th><th>Origem</th><th>Observação</th></tr></thead><tbody>';
    foreach ($logs as $log) {
        $fname = htmlspecialchars($log['file_name'] ?: 'Documento');
        $fpath = $log['file_path'] ?? '';
        $recipientLabel = htmlspecialchars($log['recipient_email'] ?: '-');
        $typeLabel = $log['recipient_type'] === 'professional' ? 'Profissional' : ($log['recipient_type'] === 'patient' ? 'Paciente' : 'Outro');
        $methodMap = ['email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'portal' => 'Portal'];
        $method = $methodMap[$log['send_method']] ?? $log['send_method'];
        $sourceMap = ['insurer' => 'Automático', 'manual' => 'Manual', 'system' => 'Sistema'];
        $source = $sourceMap[$log['document_source']] ?? $log['document_source'];
        
        echo '<tr>';
        echo '<td style="font-size:12px;white-space:nowrap">' . date('d/m/Y H:i', strtotime($log['created_at'])) . '</td>';
        echo '<td>';
        if ($fpath !== '') echo '<a href="' . htmlspecialchars($fpath) . '" target="_blank" style="color:hsl(var(--primary));text-decoration:none;font-weight:500">📄 ' . $fname . '</a>';
        else echo $fname;
        echo '</td>';
        echo '<td>' . $recipientLabel . '</td>';
        echo '<td><span style="font-size:11px;padding:2px 6px;background:hsl(var(--secondary));border-radius:4px">' . $typeLabel . '</span></td>';
        echo '<td><span style="font-size:11px;padding:2px 6px;background:hsl(var(--secondary));border-radius:4px">' . htmlspecialchars($method) . '</span></td>';
        echo '<td><span style="font-size:11px;padding:2px 6px;background:' . ($source === 'Automático' ? '#d1fae5' : '#e0e7ff') . ';border-radius:4px">' . $source . '</span></td>';
        echo '<td style="font-size:12px;color:hsl(var(--muted-foreground));max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . htmlspecialchars($log['notes'] ?? '') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';
echo '</div>';

// Modal de Envio
echo '<div id="sendModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display=\'none\'">';
echo '<div style="background:#fff;border-radius:12px;padding:24px;max-width:560px;width:100%;max-height:90vh;overflow-y:auto">';
echo '<h2 style="margin:0 0 20px;font-size:18px;font-weight:700">Enviar Documento</h2>';
echo '<form method="post" enctype="multipart/form-data">';
echo '<input type="hidden" name="action" value="send_manual">';

// Tipo + Destinatário
echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">';
echo '<div><label style="display:block;margin-bottom:4px;font-weight:600;font-size:13px">Tipo *</label>';
echo '<select name="recipient_type" id="rType" onchange="updateRecipients()" required style="width:100%;padding:9px;border:1px solid #d1d7db;border-radius:6px">';
echo '<option value="">Selecione...</option><option value="professional">Profissional</option><option value="patient">Paciente</option>';
echo '</select></div>';
echo '<div><label style="display:block;margin-bottom:4px;font-weight:600;font-size:13px">Destinatário *</label>';
echo '<select name="recipient_id" id="rSelect" required style="width:100%;padding:9px;border:1px solid #d1d7db;border-radius:6px"><option value="">Selecione tipo primeiro</option></select></div>';
echo '</div>';

// Operadora + Método
echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">';
echo '<div><label style="display:block;margin-bottom:4px;font-weight:600;font-size:13px">Operadora</label>';
echo '<select name="health_insurer_id" style="width:100%;padding:9px;border:1px solid #d1d7db;border-radius:6px"><option value="0">Nenhuma</option>';
foreach ($insurers as $ins) echo '<option value="' . (int)$ins['id'] . '">' . htmlspecialchars($ins['name']) . '</option>';
echo '</select></div>';
echo '<div><label style="display:block;margin-bottom:4px;font-weight:600;font-size:13px">Método *</label>';
echo '<select name="send_method" required style="width:100%;padding:9px;border:1px solid #d1d7db;border-radius:6px"><option value="email">E-mail</option><option value="portal">Portal</option></select></div>';
echo '</div>';

// Arquivo
echo '<div style="margin-bottom:14px"><label style="display:block;margin-bottom:4px;font-weight:600;font-size:13px">Arquivo *</label>';
echo '<input type="file" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp" style="width:100%">';
echo '<div style="font-size:11px;color:#9ca3af;margin-top:4px">PDF, DOC, DOCX, XLS, XLSX, JPG, PNG (máx 10MB)</div></div>';

// Observação
echo '<div style="margin-bottom:18px"><label style="display:block;margin-bottom:4px;font-weight:600;font-size:13px">Observação</label>';
echo '<textarea name="notes" rows="2" style="width:100%;padding:9px;border:1px solid #d1d7db;border-radius:6px;resize:vertical" placeholder="Motivo do envio..."></textarea></div>';

// Botões
echo '<div style="display:flex;gap:12px">';
echo '<button type="button" onclick="document.getElementById(\'sendModal\').style.display=\'none\'" class="btn" style="flex:1">Cancelar</button>';
echo '<button type="submit" class="btn btnPrimary" style="flex:1">Enviar</button>';
echo '</div>';

echo '</form></div></div>';

// JavaScript
echo '<script>';
echo 'var profs=[';
foreach ($professionals as $i => $p) {
    if ($i > 0) echo ',';
    echo '{id:' . (int)$p['id'] . ',n:"' . addslashes($p['name']) . '"}';
}
echo '];';
echo 'var pats=[';
foreach ($patientsList as $i => $p) {
    if ($i > 0) echo ',';
    echo '{id:' . (int)$p['id'] . ',n:"' . addslashes($p['full_name']) . '"}';
}
echo '];';
echo 'function updateRecipients(){';
echo 'var t=document.getElementById("rType").value;';
echo 'var s=document.getElementById("rSelect");';
echo 's.innerHTML="<option value=\\'\\'>Selecione...</option>";';
echo 'var l=t==="professional"?profs:(t==="patient"?pats:[]);';
echo 'for(var i=0;i<l.length;i++){var o=document.createElement("option");o.value=l[i].id;o.textContent=l[i].n;s.appendChild(o);}';
echo '}';
echo '</script>';

view_footer();
