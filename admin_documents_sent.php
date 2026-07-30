<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();

$db = db();

// Garantir que a tabela existe
try {
    $db->exec("CREATE TABLE IF NOT EXISTS document_send_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        document_id INT UNSIGNED NOT NULL DEFAULT 0,
        document_source VARCHAR(50) NOT NULL DEFAULT 'manual',
        recipient_type VARCHAR(50) NOT NULL DEFAULT 'professional',
        recipient_id INT UNSIGNED NULL,
        recipient_email VARCHAR(255) NULL,
        recipient_name VARCHAR(255) NULL,
        assignment_id INT UNSIGNED NULL,
        demand_id INT UNSIGNED NULL,
        health_insurer_id INT UNSIGNED NULL,
        send_method VARCHAR(30) NOT NULL DEFAULT 'email',
        sent_by_user_id INT UNSIGNED NULL,
        file_name VARCHAR(255) NULL,
        file_path VARCHAR(500) NULL,
        notes TEXT NULL,
        send_status VARCHAR(30) NOT NULL DEFAULT 'enviado',
        send_action VARCHAR(30) NOT NULL DEFAULT 'envio_inicial',
        resent_from_log_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

// Verificar quais colunas existem
$existingCols = [];
try {
    $colsResult = $db->query("SHOW COLUMNS FROM document_send_logs");
    if ($colsResult) {
        while ($col = $colsResult->fetch(PDO::FETCH_ASSOC)) {
            $existingCols[] = $col['Field'];
        }
    }
} catch (Throwable $e) {}

$hasSendStatus = in_array('send_status', $existingCols, true);
$hasSendAction = in_array('send_action', $existingCols, true);
$hasRecipientName = in_array('recipient_name', $existingCols, true);
$hasResentFrom = in_array('resent_from_log_id', $existingCols, true);

// Adicionar colunas faltantes de forma segura
if (!empty($existingCols)) {
    if (!$hasSendStatus) { try { $db->exec("ALTER TABLE document_send_logs ADD COLUMN send_status VARCHAR(30) NOT NULL DEFAULT 'enviado'"); $hasSendStatus = true; } catch (Throwable $e) {} }
    if (!$hasSendAction) { try { $db->exec("ALTER TABLE document_send_logs ADD COLUMN send_action VARCHAR(30) NOT NULL DEFAULT 'envio_inicial'"); $hasSendAction = true; } catch (Throwable $e) {} }
    if (!$hasRecipientName) { try { $db->exec("ALTER TABLE document_send_logs ADD COLUMN recipient_name VARCHAR(255) NULL"); $hasRecipientName = true; } catch (Throwable $e) {} }
    if (!$hasResentFrom) { try { $db->exec("ALTER TABLE document_send_logs ADD COLUMN resent_from_log_id INT UNSIGNED NULL"); $hasResentFrom = true; } catch (Throwable $e) {} }
}

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

        if (!in_array($ext, ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','webp'], true)) {
            $flashError = 'Formato não permitido.';
        } elseif ($_FILES['document']['size'] > 10485760) {
            $flashError = 'Arquivo excede 10MB.';
        } else {
            $uploadDir = __DIR__ . '/uploads/manual_docs/';
            if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
            $uniqueName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

            if (move_uploaded_file($_FILES['document']['tmp_name'], $uploadDir . $uniqueName)) {
                $relativePath = '/uploads/manual_docs/' . $uniqueName;
                $recipientEmail = '';
                $recipientName = '';

                try {
                    if ($recipientType === 'professional') {
                        $s = $db->prepare("SELECT name, email FROM users WHERE id = ?");
                        $s->execute([$recipientId]);
                        $row = $s->fetch(PDO::FETCH_ASSOC);
                        $recipientEmail = (string)($row['email'] ?? '');
                        $recipientName = (string)($row['name'] ?? '');
                    } else {
                        $s = $db->prepare("SELECT full_name, email FROM patients WHERE id = ?");
                        $s->execute([$recipientId]);
                        $row = $s->fetch(PDO::FETCH_ASSOC);
                        $recipientEmail = (string)($row['email'] ?? '');
                        $recipientName = (string)($row['full_name'] ?? '');
                    }
                } catch (Throwable $e) {}

                $sendStatus = 'enviado';
                if ($sendMethod === 'email' && $recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                    try {
                        require_once __DIR__ . '/app/email_base_template.php';
                        $baseUrl = rtrim((string)admin_setting_get('app.base_url', 'https://multilife.onsolutionsbrasil.com.br'), '/');
                        $body = '<p style="font-size:15px;color:#374151">Olá, <strong>' . htmlspecialchars($recipientName) . '</strong>!</p>';
                        $body .= '<p style="font-size:14px;color:#4b5563">Segue documento enviado pela equipe MultiLife Care:</p>';
                        $body .= '<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px">';
                        $body .= '<p style="margin:0">📄 <a href="' . $baseUrl . $relativePath . '" style="color:#0284c7">' . htmlspecialchars($fileName) . '</a></p>';
                        if ($notes !== '') { $body .= '<p style="margin:8px 0 0;font-size:13px;color:#6b7280">' . nl2br(htmlspecialchars($notes)) . '</p>'; }
                        $body .= '</div>';
                        $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
                        $htmlBody = email_base_layout('Documento Enviado', $body);
                        $smtp = new SmtpClient();
                        $smtp->send(
                            (string)admin_setting_get('smtp.out.from_email', ''),
                            (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care'),
                            $recipientEmail,
                            'Documento - ' . $fileName,
                            $htmlBody
                        );
                        $sendStatus = 'entregue';
                    } catch (Throwable $e) {
                        $sendStatus = 'falha';
                        $flashError = 'Arquivo salvo, mas falha ao enviar e-mail.';
                    }
                }

                // Inserir log
                try {
                    if ($hasSendStatus && $hasSendAction && $hasRecipientName) {
                        $db->prepare("INSERT INTO document_send_logs (document_source, recipient_type, recipient_id, recipient_email, recipient_name, health_insurer_id, send_method, sent_by_user_id, file_name, file_path, notes, send_status, send_action) VALUES ('manual',?,?,?,?,?,?,?,?,?,?,?,?)")
                            ->execute([$recipientType, $recipientId, $recipientEmail, $recipientName, $healthInsurerId > 0 ? $healthInsurerId : null, $sendMethod, auth_user_id(), $fileName, $relativePath, $notes, $sendStatus, 'envio_inicial']);
                    } else {
                        $db->prepare("INSERT INTO document_send_logs (document_source, recipient_type, recipient_id, recipient_email, health_insurer_id, send_method, sent_by_user_id, file_name, file_path, notes) VALUES ('manual',?,?,?,?,?,?,?,?,?)")
                            ->execute([$recipientType, $recipientId, $recipientEmail, $healthInsurerId > 0 ? $healthInsurerId : null, $sendMethod, auth_user_id(), $fileName, $relativePath, $notes]);
                    }
                } catch (Throwable $e) {}

                if ($flashError === '') { $flashSuccess = 'Documento enviado com sucesso!'; }
            } else {
                $flashError = 'Falha ao salvar arquivo.';
            }
        }
    }
}

// Buscar logs
$q = trim((string)($_GET['q'] ?? ''));
$logs = [];
try {
    if ($q !== '') {
        $stmt = $db->prepare("SELECT * FROM document_send_logs WHERE file_name LIKE ? OR recipient_email LIKE ? OR notes LIKE ? ORDER BY created_at DESC LIMIT 200");
        $stmt->execute(["%$q%", "%$q%", "%$q%"]);
    } else {
        $stmt = $db->query("SELECT * FROM document_send_logs ORDER BY created_at DESC LIMIT 200");
    }
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Buscar dados auxiliares
$insurers = [];
try { $insurers = $db->query("SELECT id, name FROM health_insurers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

$profs = [];
try { $profs = $db->query("SELECT u.id, u.name FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.status = 'active' AND r.slug = 'profissional' ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

$pats = [];
try { $pats = $db->query("SELECT id, full_name FROM patients ORDER BY full_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

// Estatísticas
$totalEnvios = count($logs);
$totalAuto = 0;
$totalManual = 0;
$totalFalhas = 0;
$totalReenvios = 0;
foreach ($logs as $l) {
    if (($l['document_source'] ?? '') !== 'manual') { $totalAuto++; } else { $totalManual++; }
    if ($hasSendStatus && ($l['send_status'] ?? '') === 'falha') { $totalFalhas++; }
    if ($hasSendAction && ($l['send_action'] ?? '') === 'reenvio') { $totalReenvios++; }
}

view_header('Documentos Enviados');

if ($flashSuccess !== '') { echo '<div class="alert alertSuccess">' . htmlspecialchars($flashSuccess) . '</div>'; }
if ($flashError !== '') { echo '<div class="alert alertError">' . htmlspecialchars($flashError) . '</div>'; }

echo '<div class="grid">';

// Header
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Gerenciamento de Documentos Enviados</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Visualize, envie e reenvie documentos para profissionais e pacientes</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px">';
echo '<button onclick="document.getElementById(\'sendModal\').style.display=\'flex\'" class="btn btnPrimary">Enviar Documento</button>';
echo '<a class="btn" href="/documents_list.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Cards de estatísticas
echo '<div class="col12" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px">';
echo '<div class="card" style="text-align:center;padding:14px"><div style="font-size:24px;font-weight:800;color:hsl(var(--primary))">' . $totalEnvios . '</div><div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Total</div></div>';
echo '<div class="card" style="text-align:center;padding:14px"><div style="font-size:24px;font-weight:800;color:#0284c7">' . $totalAuto . '</div><div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Automáticos</div></div>';
echo '<div class="card" style="text-align:center;padding:14px"><div style="font-size:24px;font-weight:800;color:#7c3aed">' . $totalManual . '</div><div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Manuais</div></div>';
echo '<div class="card" style="text-align:center;padding:14px"><div style="font-size:24px;font-weight:800;color:#f59e0b">' . $totalReenvios . '</div><div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Reenvios</div></div>';
echo '<div class="card" style="text-align:center;padding:14px"><div style="font-size:24px;font-weight:800;color:hsl(var(--destructive))">' . $totalFalhas . '</div><div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Falhas</div></div>';
echo '</div>';

// Filtro de busca
echo '<section class="card col12">';
echo '<form method="get" style="display:flex;gap:10px;align-items:flex-end">';
echo '<div style="flex:1"><input name="q" value="' . htmlspecialchars($q) . '" placeholder="Buscar por nome, e-mail ou documento..." style="width:100%"></div>';
echo '<button class="btn btnPrimary" type="submit">Filtrar</button>';
if ($q !== '') { echo '<a class="btn" href="/admin_documents_sent.php">Limpar</a>'; }
echo '</form>';
echo '</section>';

// Tabela de histórico
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">';
echo '<div style="font-size:16px;font-weight:700">Histórico de Envios</div>';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">' . $totalEnvios . ' registro(s)</div>';
echo '</div>';

if (empty($logs)) {
    echo '<div style="padding:50px 20px;text-align:center;color:hsl(var(--muted-foreground))">';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:6px">Nenhum documento enviado</div>';
    echo '<div style="font-size:14px">Use o botão "Enviar Documento" para começar.</div>';
    echo '</div>';
} else {
    echo '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">';
    echo '<thead><tr style="border-bottom:2px solid hsl(var(--border))">';
    echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Data/Hora</th>';
    echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Documento</th>';
    echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Destinatário</th>';
    echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Tipo</th>';
    echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Método</th>';
    echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Origem</th>';
    if ($hasSendAction) { echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Ação</th>'; }
    if ($hasSendStatus) { echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Status</th>'; }
    echo '<th style="padding:10px 8px;text-align:right;font-size:12px;font-weight:700">Ações</th>';
    echo '</tr></thead><tbody>';

    foreach ($logs as $l) {
        $src = ($l['document_source'] ?? '') === 'manual' ? 'Manual' : 'Auto';
        $mth = ($l['send_method'] ?? '') === 'email' ? 'E-mail' : ucfirst((string)($l['send_method'] ?? 'portal'));
        $tp = ($l['recipient_type'] ?? '') === 'professional' ? 'Prof.' : 'Pac.';
        $fName = (string)($l['file_name'] ?? '');
        $fPath = (string)($l['file_path'] ?? '');
        $destName = (string)($l['recipient_name'] ?? '');
        $destEmail = (string)($l['recipient_email'] ?? '');
        $destDisplay = $destName !== '' ? $destName : ($destEmail !== '' ? $destEmail : '-');

        echo '<tr style="border-bottom:1px solid hsl(var(--border))">';
        echo '<td style="padding:10px 8px;font-size:12px;white-space:nowrap">' . date('d/m/Y H:i', strtotime((string)$l['created_at'])) . '</td>';

        // Documento com link
        echo '<td style="padding:10px 8px;font-size:13px">';
        if ($fPath !== '') {
            echo '<a href="' . htmlspecialchars($fPath) . '" target="_blank" style="color:hsl(var(--primary));font-weight:600">📄 ' . htmlspecialchars($fName !== '' ? $fName : 'Documento') . '</a>';
        } else {
            echo htmlspecialchars($fName !== '' ? $fName : '-');
        }
        echo '</td>';

        // Destinatário
        echo '<td style="padding:10px 8px;font-size:12px">' . htmlspecialchars($destDisplay) . '</td>';
        echo '<td style="padding:10px 8px;font-size:11px">' . $tp . '</td>';
        echo '<td style="padding:10px 8px;font-size:11px">' . $mth . '</td>';
        echo '<td style="padding:10px 8px;font-size:11px">' . $src . '</td>';

        if ($hasSendAction) {
            $action = (string)($l['send_action'] ?? 'envio_inicial');
            $actionLabel = $action === 'reenvio' ? '🔄 Reenvio' : '📤 Envio';
            echo '<td style="padding:10px 8px;font-size:12px">' . $actionLabel . '</td>';
        }

        if ($hasSendStatus) {
            $status = (string)($l['send_status'] ?? 'enviado');
            $statusLabel = 'Enviado';
            $statusColor = '#0284c7';
            if ($status === 'entregue') { $statusLabel = 'Entregue'; $statusColor = '#10b981'; }
            elseif ($status === 'falha') { $statusLabel = 'Falha'; $statusColor = '#ef4444'; }
            elseif ($status === 'pendente') { $statusLabel = 'Pendente'; $statusColor = '#f59e0b'; }
            echo '<td style="padding:10px 8px"><span style="font-size:11px;padding:3px 8px;border-radius:6px;background:' . $statusColor . '20;color:' . $statusColor . ';font-weight:700">' . $statusLabel . '</span></td>';
        }

        // Ações: visualizar, baixar, reenviar
        echo '<td style="padding:10px 8px;text-align:right;white-space:nowrap">';
        if ($fPath !== '') {
            echo '<a href="' . htmlspecialchars($fPath) . '" target="_blank" title="Visualizar" class="btn" style="padding:4px 8px;font-size:11px">👁</a> ';
            echo '<a href="' . htmlspecialchars($fPath) . '" download title="Baixar" class="btn" style="padding:4px 8px;font-size:11px">⬇</a> ';
        }
        echo '<button onclick="reenviar(' . (int)$l['id'] . ')" title="Reenviar" class="btn" style="padding:4px 8px;font-size:11px">🔄</button>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';
echo '</div>';

// Modal de Envio Manual
echo '<div id="sendModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display=\'none\'">';
echo '<div style="background:#fff;border-radius:12px;padding:24px;max-width:520px;width:100%;max-height:90vh;overflow-y:auto">';
echo '<h2 style="margin:0 0 16px;font-size:18px;font-weight:700">Enviar Documento</h2>';
echo '<form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="send_manual">';
echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">';
echo '<div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Tipo *</label><select name="recipient_type" id="rt" onchange="ur()" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"><option value="">Selecione</option><option value="professional">Profissional</option><option value="patient">Paciente</option></select></div>';
echo '<div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Destinatário *</label><select name="recipient_id" id="rs" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"><option value="">Selecione tipo</option></select></div>';
echo '</div>';
echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">';
echo '<div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Operadora</label><select name="health_insurer_id" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"><option value="0">Nenhuma</option>';
foreach ($insurers as $i) { echo '<option value="' . (int)$i['id'] . '">' . htmlspecialchars($i['name']) . '</option>'; }
echo '</select></div>';
echo '<div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Método *</label><select name="send_method" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"><option value="email">E-mail</option><option value="portal">Portal</option></select></div>';
echo '</div>';
echo '<div style="margin-bottom:12px"><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Arquivo *</label><input type="file" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp" style="width:100%"></div>';
echo '<div style="margin-bottom:16px"><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Observação</label><input name="notes" placeholder="Motivo..." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"></div>';
echo '<div style="display:flex;gap:10px"><button type="button" onclick="document.getElementById(\'sendModal\').style.display=\'none\'" class="btn" style="flex:1">Cancelar</button><button type="submit" class="btn btnPrimary" style="flex:1">Enviar</button></div>';
echo '</form></div></div>';

// Modal de Reenvio
echo '<div id="resendModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display=\'none\'">';
echo '<div style="background:#fff;border-radius:12px;padding:24px;max-width:460px;width:100%">';
echo '<h2 style="margin:0 0 16px;font-size:18px;font-weight:700">Reenviar Documento</h2>';
echo '<form method="post" action="/admin_documents_resend_post.php">';
echo '<input type="hidden" name="log_id" id="resend_log_id" value="">';
echo '<div style="margin-bottom:16px"><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Observação do reenvio</label><input name="resend_notes" placeholder="Motivo do reenvio..." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"></div>';
echo '<div style="display:flex;gap:10px"><button type="button" onclick="document.getElementById(\'resendModal\').style.display=\'none\'" class="btn" style="flex:1">Cancelar</button><button type="submit" class="btn btnPrimary" style="flex:1">Reenviar</button></div>';
echo '</form></div></div>';

// JavaScript
echo '<script>';
echo 'var P=[';
foreach ($profs as $i => $p) { if ($i) echo ','; echo '[' . (int)$p['id'] . ',"' . addslashes($p['name']) . '"]'; }
echo '];var A=[';
foreach ($pats as $i => $p) { if ($i) echo ','; echo '[' . (int)$p['id'] . ',"' . addslashes($p['full_name']) . '"]'; }
echo '];';
echo 'function ur(){var t=document.getElementById("rt").value,s=document.getElementById("rs"),l=t==="professional"?P:t==="patient"?A:[];s.innerHTML="<option value=\\'\\'>Selecione...</option>";for(var i=0;i<l.length;i++){var o=document.createElement("option");o.value=l[i][0];o.textContent=l[i][1];s.appendChild(o);}}';
echo 'function reenviar(id){document.getElementById("resend_log_id").value=id;document.getElementById("resendModal").style.display="flex";}';
echo '</script>';

view_footer();
