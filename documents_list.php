<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('documents.manage');

$tab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'patients';
$searchQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$selectedEntityId = isset($_GET['entity_id']) ? (int)$_GET['entity_id'] : 0;

$allowedTabs = ['patients', 'professionals', 'employees', 'sent'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'patients';
}

// Se for aba "sent", renderizar tela de documentos enviados
if ($tab === 'sent') {
    $db = db();

    // Garantir tabela
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

    // Verificar colunas existentes
    $existingCols = [];
    try {
        $colsResult = $db->query("SHOW COLUMNS FROM document_send_logs");
        if ($colsResult) { while ($col = $colsResult->fetch(PDO::FETCH_ASSOC)) { $existingCols[] = $col['Field']; } }
    } catch (Throwable $e) {}

    $hasSendStatus = in_array('send_status', $existingCols, true);
    $hasSendAction = in_array('send_action', $existingCols, true);
    $hasRecipientName = in_array('recipient_name', $existingCols, true);

    // Adicionar colunas faltantes
    if (!empty($existingCols)) {
        if (!$hasSendStatus) { try { $db->exec("ALTER TABLE document_send_logs ADD COLUMN send_status VARCHAR(30) NOT NULL DEFAULT 'enviado'"); $hasSendStatus = true; } catch (Throwable $e) {} }
        if (!$hasSendAction) { try { $db->exec("ALTER TABLE document_send_logs ADD COLUMN send_action VARCHAR(30) NOT NULL DEFAULT 'envio_inicial'"); $hasSendAction = true; } catch (Throwable $e) {} }
        if (!$hasRecipientName) { try { $db->exec("ALTER TABLE document_send_logs ADD COLUMN recipient_name VARCHAR(255) NULL"); $hasRecipientName = true; } catch (Throwable $e) {} }
        if (!in_array('resent_from_log_id', $existingCols, true)) { try { $db->exec("ALTER TABLE document_send_logs ADD COLUMN resent_from_log_id INT UNSIGNED NULL"); } catch (Throwable $e) {} }
    }

    // POST: envio manual
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
                            $body = '<p>Olá, <strong>' . htmlspecialchars($recipientName) . '</strong>!</p>';
                            $body .= '<p>Segue documento enviado pela equipe MultiLife Care:</p>';
                            $body .= '<div style="background:#f9fafb;padding:18px;margin:20px 0;border-radius:8px"><p style="margin:0">📄 <a href="' . $baseUrl . $relativePath . '">' . htmlspecialchars($fileName) . '</a></p></div>';
                            $htmlBody = email_base_layout('Documento Enviado', $body);
                            $smtp = new SmtpClient();
                            $smtp->send((string)admin_setting_get('smtp.out.from_email', ''), (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care'), $recipientEmail, 'Documento - ' . $fileName, $htmlBody);
                            $sendStatus = 'entregue';
                        } catch (Throwable $e) { $sendStatus = 'falha'; }
                    }

                    try {
                        if ($hasSendStatus && $hasSendAction && $hasRecipientName) {
                            $db->prepare("INSERT INTO document_send_logs (document_source,recipient_type,recipient_id,recipient_email,recipient_name,health_insurer_id,send_method,sent_by_user_id,file_name,file_path,notes,send_status,send_action) VALUES ('manual',?,?,?,?,?,?,?,?,?,?,?,?)")
                                ->execute([$recipientType,$recipientId,$recipientEmail,$recipientName,$healthInsurerId>0?$healthInsurerId:null,$sendMethod,auth_user_id(),$fileName,$relativePath,$notes,$sendStatus,'envio_inicial']);
                        } else {
                            $db->prepare("INSERT INTO document_send_logs (document_source,recipient_type,recipient_id,recipient_email,health_insurer_id,send_method,sent_by_user_id,file_name,file_path,notes) VALUES ('manual',?,?,?,?,?,?,?,?,?)")
                                ->execute([$recipientType,$recipientId,$recipientEmail,$healthInsurerId>0?$healthInsurerId:null,$sendMethod,auth_user_id(),$fileName,$relativePath,$notes]);
                        }
                    } catch (Throwable $e) {}
                    if ($flashError === '') { $flashSuccess = 'Documento enviado com sucesso!'; }
                } else { $flashError = 'Falha ao salvar arquivo.'; }
            }
        }
    }

    // Buscar logs
    $sentQ = trim((string)($_GET['q'] ?? ''));
    $sentLogs = [];
    try {
        if ($sentQ !== '') {
            $stmt = $db->prepare("SELECT * FROM document_send_logs WHERE file_name LIKE ? OR recipient_email LIKE ? OR notes LIKE ? ORDER BY created_at DESC LIMIT 200");
            $stmt->execute(["%$sentQ%", "%$sentQ%", "%$sentQ%"]);
        } else {
            $stmt = $db->query("SELECT * FROM document_send_logs ORDER BY created_at DESC LIMIT 200");
        }
        $sentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    // Dados auxiliares
    $insurers = [];
    try { $insurers = $db->query("SELECT id, name FROM health_insurers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    $profs = [];
    try { $profs = $db->query("SELECT u.id, u.name FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.status = 'active' AND r.slug = 'profissional' ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    $pats = [];
    try { $pats = $db->query("SELECT id, full_name FROM patients ORDER BY full_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

    // Estatísticas
    $totalEnvios = count($sentLogs);
    $totalAuto = 0; $totalManual = 0; $totalFalhas = 0; $totalReenvios = 0;
    foreach ($sentLogs as $l) {
        if (($l['document_source'] ?? '') !== 'manual') { $totalAuto++; } else { $totalManual++; }
        if ($hasSendStatus && ($l['send_status'] ?? '') === 'falha') { $totalFalhas++; }
        if ($hasSendAction && ($l['send_action'] ?? '') === 'reenvio') { $totalReenvios++; }
    }

    view_header('Documentos Enviados');
    if ($flashSuccess !== '') { echo '<div class="alert alertSuccess">' . htmlspecialchars($flashSuccess) . '</div>'; }
    if ($flashError !== '') { echo '<div class="alert alertError">' . htmlspecialchars($flashError) . '</div>'; }

    echo '<div class="grid">';

    // Header
    echo '<section class="card col12"><div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap"><div><div style="font-size:22px;font-weight:900">Gerenciamento de Documentos Enviados</div><div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Visualize, envie e reenvie documentos para profissionais e pacientes</div></div><div style="display:flex;gap:10px"><button onclick="document.getElementById(\'sendModal\').style.display=\'flex\'" class="btn btnPrimary">Enviar Documento</button><a class="btn" href="/documents_list.php">Voltar</a></div></div></section>';

    // Estatísticas
    echo '<div class="col12" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px">';
    echo '<div class="card" style="text-align:center;padding:14px"><div style="font-size:24px;font-weight:800;color:hsl(var(--primary))">' . $totalEnvios . '</div><div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Total</div></div>';
    echo '<div class="card" style="text-align:center;padding:14px"><div style="font-size:24px;font-weight:800;color:#0284c7">' . $totalAuto . '</div><div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Automáticos</div></div>';
    echo '<div class="card" style="text-align:center;padding:14px"><div style="font-size:24px;font-weight:800;color:#7c3aed">' . $totalManual . '</div><div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Manuais</div></div>';
    echo '<div class="card" style="text-align:center;padding:14px"><div style="font-size:24px;font-weight:800;color:#f59e0b">' . $totalReenvios . '</div><div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Reenvios</div></div>';
    echo '<div class="card" style="text-align:center;padding:14px"><div style="font-size:24px;font-weight:800;color:hsl(var(--destructive))">' . $totalFalhas . '</div><div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Falhas</div></div>';
    echo '</div>';

    // Filtro
    echo '<section class="card col12"><form method="get" style="display:flex;gap:10px;align-items:flex-end"><input type="hidden" name="tab" value="sent"><div style="flex:1"><input name="q" value="' . htmlspecialchars($sentQ) . '" placeholder="Buscar..." style="width:100%"></div><button class="btn btnPrimary" type="submit">Filtrar</button>';
    if ($sentQ !== '') { echo '<a class="btn" href="/documents_list.php?tab=sent">Limpar</a>'; }
    echo '</form></section>';

    // Tabela
    echo '<section class="card col12"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px"><div style="font-size:16px;font-weight:700">Histórico de Envios</div><div style="font-size:13px;color:hsl(var(--muted-foreground))">' . $totalEnvios . ' registro(s)</div></div>';

    if (empty($sentLogs)) {
        echo '<div style="padding:50px 20px;text-align:center;color:hsl(var(--muted-foreground))"><div style="font-size:16px;font-weight:600;margin-bottom:6px">Nenhum documento enviado</div><div style="font-size:14px">Use o botão "Enviar Documento" para começar.</div></div>';
    } else {
        echo '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse"><thead><tr style="border-bottom:2px solid hsl(var(--border))"><th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Data</th><th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Documento</th><th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Destinatário</th><th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Tipo</th><th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Método</th><th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Origem</th>';
        if ($hasSendAction) { echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Ação</th>'; }
        if ($hasSendStatus) { echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Status</th>'; }
        echo '<th style="padding:10px 8px;text-align:right;font-size:12px;font-weight:700">Ações</th></tr></thead><tbody>';

        foreach ($sentLogs as $l) {
            $fName = (string)($l['file_name'] ?? '');
            $fPath = (string)($l['file_path'] ?? '');
            $destName = (string)($l['recipient_name'] ?? '');
            $destEmail = (string)($l['recipient_email'] ?? '');
            $destDisplay = $destName !== '' ? $destName : ($destEmail !== '' ? $destEmail : '-');
            $src = ($l['document_source'] ?? '') === 'manual' ? 'Manual' : 'Auto';
            $mth = ($l['send_method'] ?? '') === 'email' ? 'E-mail' : ucfirst((string)($l['send_method'] ?? 'portal'));
            $tp = ($l['recipient_type'] ?? '') === 'professional' ? 'Prof.' : 'Pac.';

            echo '<tr style="border-bottom:1px solid hsl(var(--border))">';
            echo '<td style="padding:10px 8px;font-size:12px;white-space:nowrap">' . date('d/m/Y H:i', strtotime((string)$l['created_at'])) . '</td>';
            echo '<td style="padding:10px 8px;font-size:13px">';
            if ($fPath !== '') { echo '<a href="' . htmlspecialchars($fPath) . '" target="_blank" style="color:hsl(var(--primary));font-weight:600">📄 ' . htmlspecialchars($fName !== '' ? $fName : 'Doc') . '</a>'; }
            else { echo htmlspecialchars($fName !== '' ? $fName : '-'); }
            echo '</td>';
            echo '<td style="padding:10px 8px;font-size:12px">' . htmlspecialchars($destDisplay) . '</td>';
            echo '<td style="padding:10px 8px;font-size:11px">' . $tp . '</td>';
            echo '<td style="padding:10px 8px;font-size:11px">' . $mth . '</td>';
            echo '<td style="padding:10px 8px;font-size:11px">' . $src . '</td>';

            if ($hasSendAction) {
                $action = (string)($l['send_action'] ?? 'envio_inicial');
                echo '<td style="padding:10px 8px;font-size:12px">' . ($action === 'reenvio' ? '🔄 Reenvio' : '📤 Envio') . '</td>';
            }
            if ($hasSendStatus) {
                $status = (string)($l['send_status'] ?? 'enviado');
                $sLabel = 'Enviado'; $sColor = '#0284c7';
                if ($status === 'entregue') { $sLabel = 'Entregue'; $sColor = '#10b981'; }
                elseif ($status === 'falha') { $sLabel = 'Falha'; $sColor = '#ef4444'; }
                echo '<td style="padding:10px 8px"><span style="font-size:11px;padding:3px 8px;border-radius:6px;background:' . $sColor . '20;color:' . $sColor . ';font-weight:700">' . $sLabel . '</span></td>';
            }

            echo '<td style="padding:10px 8px;text-align:right;white-space:nowrap">';
            if ($fPath !== '') {
                echo '<a href="' . htmlspecialchars($fPath) . '" target="_blank" class="btn" style="padding:4px 8px;font-size:11px" title="Ver">👁</a> ';
                echo '<a href="' . htmlspecialchars($fPath) . '" download class="btn" style="padding:4px 8px;font-size:11px" title="Baixar">⬇</a> ';
            }
            echo '<button onclick="reenviar(' . (int)$l['id'] . ')" class="btn" style="padding:4px 8px;font-size:11px" title="Reenviar">🔄</button>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section></div>';

    // Modal Envio
    echo '<div id="sendModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display=\'none\'"><div style="background:#fff;border-radius:12px;padding:24px;max-width:520px;width:100%;max-height:90vh;overflow-y:auto"><h2 style="margin:0 0 16px;font-size:18px;font-weight:700">Enviar Documento</h2><form method="post" action="/documents_list.php?tab=sent" enctype="multipart/form-data"><input type="hidden" name="action" value="send_manual"><div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px"><div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Tipo *</label><select name="recipient_type" id="rt" onchange="ur()" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"><option value="">Selecione</option><option value="professional">Profissional</option><option value="patient">Paciente</option></select></div><div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Destinatário *</label><select name="recipient_id" id="rs" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"><option value="">Selecione tipo</option></select></div></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px"><div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Operadora</label><select name="health_insurer_id" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"><option value="0">Nenhuma</option>';
    foreach ($insurers as $i) { echo '<option value="' . (int)$i['id'] . '">' . htmlspecialchars($i['name']) . '</option>'; }
    echo '</select></div><div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Método *</label><select name="send_method" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"><option value="email">E-mail</option><option value="portal">Portal</option></select></div></div><div style="margin-bottom:12px"><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Arquivo *</label><input type="file" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp" style="width:100%"></div><div style="margin-bottom:16px"><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Obs</label><input name="notes" placeholder="Motivo..." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"></div><div style="display:flex;gap:10px"><button type="button" onclick="document.getElementById(\'sendModal\').style.display=\'none\'" class="btn" style="flex:1">Cancelar</button><button type="submit" class="btn btnPrimary" style="flex:1">Enviar</button></div></form></div></div>';

    // Modal Reenvio
    echo '<div id="resendModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display=\'none\'"><div style="background:#fff;border-radius:12px;padding:24px;max-width:460px;width:100%"><h2 style="margin:0 0 16px;font-size:18px;font-weight:700">Reenviar Documento</h2><form method="post" action="/admin_documents_resend_post.php"><input type="hidden" name="log_id" id="resend_log_id" value=""><div style="margin-bottom:16px"><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Observação</label><input name="resend_notes" placeholder="Motivo do reenvio..." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"></div><div style="display:flex;gap:10px"><button type="button" onclick="document.getElementById(\'resendModal\').style.display=\'none\'" class="btn" style="flex:1">Cancelar</button><button type="submit" class="btn btnPrimary" style="flex:1">Reenviar</button></div></form></div></div>';

    // JS
    echo '<script>var P=[';
    foreach ($profs as $i => $p) { if ($i) echo ','; echo '[' . (int)$p['id'] . ',"' . addslashes($p['name']) . '"]'; }
    echo '];var A=[';
    foreach ($pats as $i => $p) { if ($i) echo ','; echo '[' . (int)$p['id'] . ',"' . addslashes($p['full_name']) . '"]'; }
    echo '];function ur(){var t=document.getElementById("rt").value,s=document.getElementById("rs"),l=t==="professional"?P:t==="patient"?A:[];s.innerHTML="<option value=\\'\\'>Selecione...</option>";for(var i=0;i<l.length;i++){var o=document.createElement("option");o.value=l[i][0];o.textContent=l[i][1];s.appendChild(o);}}function reenviar(id){document.getElementById("resend_log_id").value=id;document.getElementById("resendModal").style.display="flex";}</script>';

    view_footer();
    exit;
}

// Mapear tab para entity_type
$entityTypeMap = [
    'patients' => 'patient',
    'professionals' => 'professional',
    'employees' => 'company'
];
$entityType = $entityTypeMap[$tab];

// Buscar lista de entidades (pessoas) que têm documentos
$entities = [];

if ($tab === 'patients') {
    $sql = "
        SELECT DISTINCT p.id, p.full_name as name,
               (SELECT COUNT(*) FROM documents d WHERE d.entity_type = 'patient' AND d.entity_id = p.id AND d.status = 'active') as document_count
        FROM patients p
        INNER JOIN documents d ON d.entity_type = 'patient' AND d.entity_id = p.id AND d.status = 'active'
        WHERE p.deleted_at IS NULL
    ";
    
    if ($searchQuery !== '') {
        $sql .= " AND p.full_name LIKE ?";
    }
    
    $sql .= " GROUP BY p.id, p.full_name ORDER BY p.full_name ASC";
    
    $stmt = db()->prepare($sql);
    if ($searchQuery !== '') {
        $stmt->execute(['%' . $searchQuery . '%']);
    } else {
        $stmt->execute();
    }
    $entities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sql = "
        SELECT DISTINCT u.id, u.name,
               (SELECT COUNT(*) FROM documents d WHERE d.entity_type = ? AND d.entity_id = u.id AND d.status = 'active') as document_count
        FROM users u
        INNER JOIN documents d ON d.entity_type = ? AND d.entity_id = u.id AND d.status = 'active'
    ";
    
    if ($searchQuery !== '') {
        $sql .= " WHERE u.name LIKE ?";
    }
    
    $sql .= " GROUP BY u.id, u.name ORDER BY u.name ASC";
    
    $stmt = db()->prepare($sql);
    if ($searchQuery !== '') {
        $stmt->execute([$entityType, $entityType, '%' . $searchQuery . '%']);
    } else {
        $stmt->execute([$entityType, $entityType]);
    }
    $entities = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Buscar documentos da entidade selecionada (mais recente ao mais antigo)
$documents = [];
$selectedEntityName = '';

if ($selectedEntityId > 0) {
    // Buscar nome da entidade selecionada
    if ($tab === 'patients') {
        $nameStmt = db()->prepare("SELECT full_name as name FROM patients WHERE id = ?");
        $nameStmt->execute([$selectedEntityId]);
        $nameResult = $nameStmt->fetch(PDO::FETCH_ASSOC);
        $selectedEntityName = $nameResult ? $nameResult['name'] : '';
    } else {
        $nameStmt = db()->prepare("SELECT name FROM users WHERE id = ?");
        $nameStmt->execute([$selectedEntityId]);
        $nameResult = $nameStmt->fetch(PDO::FETCH_ASSOC);
        $selectedEntityName = $nameResult ? $nameResult['name'] : '';
    }
    
    // Buscar documentos
    $docsStmt = db()->prepare("
        SELECT d.id, d.title, d.category, d.status, d.created_at,
               (SELECT MAX(v.version_no) FROM document_versions v WHERE v.document_id = d.id) as last_version,
               (SELECT v.stored_path FROM document_versions v WHERE v.document_id = d.id ORDER BY v.version_no DESC LIMIT 1) as file_path,
               (SELECT v.file_size FROM document_versions v WHERE v.document_id = d.id ORDER BY v.version_no DESC LIMIT 1) as file_size,
               (SELECT u.name FROM document_versions v LEFT JOIN users u ON u.id = v.uploaded_by_user_id WHERE v.document_id = d.id ORDER BY v.version_no DESC LIMIT 1) as uploaded_by_name
        FROM documents d
        WHERE d.entity_type = ? AND d.entity_id = ? AND d.status = 'active'
        ORDER BY d.created_at DESC
    ");
    $docsStmt->execute([$entityType, $selectedEntityId]);
    $documents = $docsStmt->fetchAll(PDO::FETCH_ASSOC);
}

view_header('Gestão Documental');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Gestão Documental</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Documentos consolidados por paciente, profissional ou funcionário</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

// Abas de navegação
echo '<div style="margin-top:20px;border-bottom:2px solid #e5e7eb">';
echo '<div style="display:flex;gap:4px">';

$tabs = [
    'patients' => '<svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>Pacientes',
    'professionals' => '<svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>Profissionais',
    'employees' => '<svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M20 6h-4V4c0-1.1-.9-2-2-2h-4c-1.1 0-2 .9-2 2v2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zM10 4h4v2h-4V4zm10 16H4V8h16v12z"/></svg>Funcionários',
    'sent' => '<svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>Enviados'
];

foreach ($tabs as $tabKey => $tabLabel) {
    $isActive = $tab === $tabKey;
    $activeStyle = $isActive ? 'background:hsl(var(--primary));color:white;border-color:hsl(var(--primary))' : 'background:white;color:#667781;border-color:transparent';
    $href = '/documents_list.php?tab=' . $tabKey;
    echo '<a href="' . $href . '" style="padding:12px 24px;text-decoration:none;font-weight:600;border:2px solid;border-bottom:none;border-radius:8px 8px 0 0;' . $activeStyle . '">' . $tabLabel . '</a>';
}

echo '</div>';
echo '</div>';

echo '</section>';

// Filtro de pesquisa
echo '<section class="card col12" style="margin-top:16px">';
echo '<form method="get" action="/documents_list.php" style="margin-bottom:0">';
echo '<input type="hidden" name="tab" value="' . h($tab) . '">';
if ($selectedEntityId > 0) {
    echo '<input type="hidden" name="entity_id" value="' . $selectedEntityId . '">';
}
echo '<input type="text" name="q" value="' . h($searchQuery) . '" placeholder="Buscar por nome..." style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px">';
echo '</form>';
echo '</section>';

echo '<section class="card col12" style="margin-top:16px">';

// Se nenhum paciente selecionado, mostra lista de nomes
if ($selectedEntityId === 0) {
    if (count($entities) === 0) {
        echo '<div style="padding:40px;text-align:center;color:#667781">';
        echo $searchQuery !== '' ? 'Nenhum resultado para "' . h($searchQuery) . '"' : 'Nenhum registro com documentos';
        echo '</div>';
    } else {
        echo '<div style="overflow:auto">';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>' . ($tab === 'patients' ? 'Paciente' : ($tab === 'professionals' ? 'Profissional' : 'Funcionário')) . '</th>';
        echo '<th>Documentos</th>';
        echo '<th style="text-align:right">Ações</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($entities as $entity) {
            echo '<tr>';
            echo '<td style="font-weight:600">' . h($entity['name']) . '</td>';
            echo '<td>' . (int)$entity['document_count'] . ' documento(s)</td>';
            echo '<td style="text-align:right">';
            echo '<a class="btn btnPrimary" href="/documents_list.php?tab=' . h($tab) . '&entity_id=' . (int)$entity['id'] . ($searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '') . '">Ver documentos</a>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
    }
} else {
    // Paciente selecionado, mostra documentos
    echo '<div style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between">';
    echo '<div>';
    echo '<a href="/documents_list.php?tab=' . h($tab) . ($searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '') . '" class="btn" style="margin-right:12px">← Voltar</a>';
    echo '<span style="font-size:18px;font-weight:700">' . h($selectedEntityName) . '</span>';
    echo '<span style="font-size:14px;color:#667781;margin-left:12px">' . count($documents) . ' documento(s)</span>';
    echo '</div>';
    echo '<a class="btn btnPrimary" href="/documents_upload.php?entity_type=' . h($entityType) . '&entity_id=' . $selectedEntityId . '"><svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>Upload</a>';
    echo '</div>';
    
    if (count($documents) === 0) {
        echo '<div style="padding:40px;text-align:center;color:#667781">Nenhum documento encontrado</div>';
    } else {
        echo '<div style="overflow:auto">';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>Título</th><th>Categoria</th><th>Versão</th><th>Tamanho</th><th>Enviado por</th><th>Data</th><th style="text-align:right">Ações</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($documents as $doc) {
            $fileSize = (int)($doc['file_size'] ?? 0);
            $fileSizeFormatted = $fileSize > 1048576 ? number_format($fileSize / 1048576, 2) . ' MB' : ($fileSize > 0 ? number_format($fileSize / 1024, 2) . ' KB' : '-');
            $version = $doc['last_version'] ? 'v' . $doc['last_version'] : '-';
            
            echo '<tr>';
            echo '<td style="font-weight:600">' . h($doc['title'] ?? 'Sem título') . '</td>';
            echo '<td>' . h($doc['category'] ?? '-') . '</td>';
            echo '<td>' . h($version) . '</td>';
            echo '<td>' . $fileSizeFormatted . '</td>';
            echo '<td>' . h($doc['uploaded_by_name'] ?? '-') . '</td>';
            echo '<td>' . date('d/m/Y', strtotime($doc['created_at'])) . '</td>';
            echo '<td style="text-align:right">';
            if (!empty($doc['file_path'])) {
                echo '<a class="btn" href="' . h($doc['file_path']) . '" target="_blank"><svg style="width:14px;height:14px;margin-right:4px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>Download</a> ';
            }
            echo '<a class="btn" href="/documents_view.php?id=' . (int)$doc['id'] . '"><svg style="width:14px;height:14px;margin-right:4px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>Ver</a>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
    }
}

echo '</section>';

echo '</div>';

view_footer();
