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
$flashSuccess = '';
$flashError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_manual') {
    $recipientType = trim((string)($_POST['recipient_type'] ?? ''));
    $recipientId = (int)($_POST['recipient_id'] ?? 0);
    $healthInsurerId = (int)($_POST['health_insurer_id'] ?? 0);
    $sendMethod = trim((string)($_POST['send_method'] ?? 'email'));
    $notes = trim((string)($_POST['notes'] ?? ''));
    
    if ($recipientId <= 0 || $recipientType === '') {
        $flashError = 'Selecione um destinatário.';
    } elseif (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $flashError = 'Selecione um arquivo.';
    } else {
        $fileName = $_FILES['document']['name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','webp'])) {
            $flashError = 'Formato não permitido.';
        } elseif ($_FILES['document']['size'] > 10485760) {
            $flashError = 'Arquivo excede 10MB.';
        } else {
            $uploadDir = __DIR__ . '/uploads/manual_docs/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
            $uniqueName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            
            if (move_uploaded_file($_FILES['document']['tmp_name'], $uploadDir . $uniqueName)) {
                $relativePath = '/uploads/manual_docs/' . $uniqueName;
                $recipientEmail = '';
                try {
                    if ($recipientType === 'professional') {
                        $s = $db->prepare("SELECT email FROM users WHERE id = ?");
                    } else {
                        $s = $db->prepare("SELECT email FROM patients WHERE id = ?");
                    }
                    $s->execute([$recipientId]);
                    $recipientEmail = (string)($s->fetchColumn() ?: '');
                } catch (Throwable $e) {}
                
                try {
                    $db->prepare("INSERT INTO document_send_logs (document_source, recipient_type, recipient_id, recipient_email, health_insurer_id, send_method, sent_by_user_id, file_name, file_path, notes) VALUES ('manual',?,?,?,?,?,?,?,?,?)")
                        ->execute([$recipientType, $recipientId, $recipientEmail, $healthInsurerId > 0 ? $healthInsurerId : null, $sendMethod, auth_user_id(), $fileName, $relativePath, $notes]);
                } catch (Throwable $e) {}
                
                if ($sendMethod === 'email' && $recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                    try {
                        require_once __DIR__ . '/app/email_base_template.php';
                        $body = '<p style="font-size:15px;color:#374151">Olá!</p><p style="font-size:14px;color:#4b5563">Segue documento enviado:</p>';
                        $body .= '<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px"><p style="margin:0">📄 <a href="https://multilife.onsolutionsbrasil.com.br' . $relativePath . '" style="color:#0284c7">' . htmlspecialchars($fileName) . '</a></p></div>';
                        $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
                        $htmlBody = email_base_layout('Documento Enviado', $body);
                        $smtp = new SmtpClient();
                        $smtp->send((string)admin_setting_get('smtp.out.from_email', ''), (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care'), $recipientEmail, 'Documento - ' . $fileName, $htmlBody);
                    } catch (Throwable $e) {}
                }
                $flashSuccess = 'Documento enviado com sucesso!';
            } else {
                $flashError = 'Falha ao salvar arquivo.';
            }
        }
    }
}

// Buscar logs
$logs = [];
try {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $stmt = $db->prepare("SELECT * FROM document_send_logs WHERE file_name LIKE ? OR recipient_email LIKE ? OR notes LIKE ? ORDER BY created_at DESC LIMIT 100");
        $stmt->execute(["%$q%", "%$q%", "%$q%"]);
    } else {
        $stmt = $db->query("SELECT * FROM document_send_logs ORDER BY created_at DESC LIMIT 100");
    }
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Buscar operadoras
$insurers = [];
try { $insurers = $db->query("SELECT id, name FROM health_insurers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

// Buscar profissionais
$profs = [];
try { $profs = $db->query("SELECT u.id, u.name FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.status = 'active' AND r.slug = 'profissional' ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

// Buscar pacientes
$pats = [];
try { $pats = $db->query("SELECT id, full_name FROM patients ORDER BY full_name LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

view_header('Documentos Enviados');

if ($flashSuccess) echo '<div style="background:#d1fae5;border:1px solid #10b981;padding:12px 16px;border-radius:8px;margin-bottom:16px;color:#065f46;font-weight:600">' . htmlspecialchars($flashSuccess) . '</div>';
if ($flashError) echo '<div style="background:#fee2e2;border:1px solid #ef4444;padding:12px 16px;border-radius:8px;margin-bottom:16px;color:#991b1b;font-weight:600">' . htmlspecialchars($flashError) . '</div>';

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div><div style="font-size:22px;font-weight:900">Gerenciamento de Documentos Enviados</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Envie documentos para profissionais e pacientes, e acompanhe o histórico</div></div>';
echo '<div style="display:flex;gap:10px"><button onclick="document.getElementById(\'sendModal\').style.display=\'flex\'" class="btn btnPrimary">Enviar Documento</button>';
echo '<a class="btn" href="/documents_list.php">Voltar</a></div>';
echo '</div>';
echo '<form method="get" style="display:flex;gap:12px;margin-top:16px"><input name="q" value="' . htmlspecialchars($_GET['q'] ?? '') . '" placeholder="Buscar..." style="flex:1"><button class="btn" type="submit">Filtrar</button></form>';
echo '</section>';

echo '<section class="card col12">';
if (empty($logs)) {
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))"><div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum documento enviado ainda</div><div style="font-size:14px">Use o botão "Enviar Documento" acima para começar.</div></div>';
} else {
    echo '<div style="overflow:auto"><table><thead><tr><th>Data</th><th>Documento</th><th>Destinatário</th><th>Tipo</th><th>Método</th><th>Origem</th><th>Obs</th></tr></thead><tbody>';
    foreach ($logs as $l) {
        $src = $l['document_source'] === 'insurer' ? 'Auto' : 'Manual';
        $mth = $l['send_method'] === 'email' ? 'E-mail' : ucfirst($l['send_method']);
        $tp = $l['recipient_type'] === 'professional' ? 'Prof.' : 'Pac.';
        echo '<tr>';
        echo '<td style="font-size:12px;white-space:nowrap">' . date('d/m/Y H:i', strtotime($l['created_at'])) . '</td>';
        echo '<td>' . ($l['file_path'] ? '<a href="' . htmlspecialchars($l['file_path']) . '" target="_blank" style="color:hsl(var(--primary))">📄 ' . htmlspecialchars($l['file_name'] ?: 'Doc') . '</a>' : htmlspecialchars($l['file_name'] ?: '-')) . '</td>';
        echo '<td style="font-size:12px">' . htmlspecialchars($l['recipient_email'] ?: '-') . '</td>';
        echo '<td style="font-size:11px">' . $tp . '</td>';
        echo '<td style="font-size:11px">' . $mth . '</td>';
        echo '<td style="font-size:11px">' . $src . '</td>';
        echo '<td style="font-size:11px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . htmlspecialchars($l['notes'] ?: '') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section></div>';

// Modal
echo '<div id="sendModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display=\'none\'">';
echo '<div style="background:#fff;border-radius:12px;padding:24px;max-width:520px;width:100%;max-height:90vh;overflow-y:auto">';
echo '<h2 style="margin:0 0 16px;font-size:18px;font-weight:700">Enviar Documento</h2>';
echo '<form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="send_manual">';
echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">';
echo '<div><label style="font-size:12px;font-weight:600">Tipo *</label><select name="recipient_type" id="rt" onchange="ur()" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-top:4px"><option value="">Selecione</option><option value="professional">Profissional</option><option value="patient">Paciente</option></select></div>';
echo '<div><label style="font-size:12px;font-weight:600">Destinatário *</label><select name="recipient_id" id="rs" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-top:4px"><option value="">Selecione tipo</option></select></div>';
echo '</div>';
echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">';
echo '<div><label style="font-size:12px;font-weight:600">Operadora</label><select name="health_insurer_id" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-top:4px"><option value="0">Nenhuma</option>';
foreach ($insurers as $i) echo '<option value="' . (int)$i['id'] . '">' . htmlspecialchars($i['name']) . '</option>';
echo '</select></div>';
echo '<div><label style="font-size:12px;font-weight:600">Método *</label><select name="send_method" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-top:4px"><option value="email">E-mail</option><option value="portal">Portal</option></select></div>';
echo '</div>';
echo '<div style="margin-bottom:12px"><label style="font-size:12px;font-weight:600">Arquivo *</label><input type="file" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" style="width:100%;margin-top:4px"></div>';
echo '<div style="margin-bottom:16px"><label style="font-size:12px;font-weight:600">Observação</label><input name="notes" placeholder="Motivo..." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-top:4px"></div>';
echo '<div style="display:flex;gap:10px"><button type="button" onclick="document.getElementById(\'sendModal\').style.display=\'none\'" class="btn" style="flex:1">Cancelar</button><button type="submit" class="btn btnPrimary" style="flex:1">Enviar</button></div>';
echo '</form></div></div>';

// JS
echo '<script>var P=[';
foreach ($profs as $i => $p) { if ($i) echo ','; echo '[' . (int)$p['id'] . ',"' . addslashes($p['name']) . '"]'; }
echo '];var A=[';
foreach ($pats as $i => $p) { if ($i) echo ','; echo '[' . (int)$p['id'] . ',"' . addslashes($p['full_name']) . '"]'; }
echo '];function ur(){var t=document.getElementById("rt").value,s=document.getElementById("rs"),l=t==="professional"?P:t==="patient"?A:[];s.innerHTML="<option value=\\'\\'>Selecione...</option>";for(var i=0;i<l.length;i++){var o=document.createElement("option");o.value=l[i][0];o.textContent=l[i][1];s.appendChild(o);}}</script>';

view_footer();
