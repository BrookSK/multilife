<?php
/**
 * Aba "Enviados" - Gerenciamento de Documentos Enviados
 * Incluido via require em documents_list.php
 * O bootstrap ja foi carregado, auth ja verificada.
 */

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
$_cols = [];
try {
    $_r = $db->query("SHOW COLUMNS FROM document_send_logs");
    if ($_r) { while ($_c = $_r->fetch(PDO::FETCH_ASSOC)) { $_cols[] = $_c['Field']; } }
} catch (Throwable $e) {}

$_hasSendStatus = in_array('send_status', $_cols, true);
$_hasSendAction = in_array('send_action', $_cols, true);
$_hasRecipientName = in_array('recipient_name', $_cols, true);

// Adicionar colunas faltantes
if (!empty($_cols)) {
    if (!$_hasSendStatus) { try { $db->exec("ALTER TABLE document_send_logs ADD COLUMN send_status VARCHAR(30) NOT NULL DEFAULT 'enviado'"); $_hasSendStatus = true; } catch (Throwable $e) {} }
    if (!$_hasSendAction) { try { $db->exec("ALTER TABLE document_send_logs ADD COLUMN send_action VARCHAR(30) NOT NULL DEFAULT 'envio_inicial'"); $_hasSendAction = true; } catch (Throwable $e) {} }
    if (!$_hasRecipientName) { try { $db->exec("ALTER TABLE document_send_logs ADD COLUMN recipient_name VARCHAR(255) NULL"); $_hasRecipientName = true; } catch (Throwable $e) {} }
    if (!in_array('resent_from_log_id', $_cols, true)) { try { $db->exec("ALTER TABLE document_send_logs ADD COLUMN resent_from_log_id INT UNSIGNED NULL"); } catch (Throwable $e) {} }
}

// Sub-aba interna
$_subTab = isset($_GET['sub']) ? (string)$_GET['sub'] : 'documentos';
if (!in_array($_subTab, ['documentos', 'enviar', 'historico'], true)) {
    $_subTab = 'documentos';
}

// POST: envio manual
$_flashSuccess = '';
$_flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_manual_doc') {
    $recipientType = trim((string)($_POST['recipient_type'] ?? ''));
    $recipientId = (int)($_POST['recipient_id'] ?? 0);
    $healthInsurerId = (int)($_POST['health_insurer_id'] ?? 0);
    $sendMethod = trim((string)($_POST['send_method'] ?? 'email'));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($recipientId <= 0 || $recipientType === '') {
        $_flashError = 'Selecione um destinatario.';
    } elseif (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $_flashError = 'Selecione um arquivo.';
    } else {
        $fileName = $_FILES['document']['name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','webp'], true)) {
            $_flashError = 'Formato nao permitido.';
        } elseif ($_FILES['document']['size'] > 10485760) {
            $_flashError = 'Arquivo excede 10MB.';
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
                        $body = '<p>Ola, <strong>' . htmlspecialchars($recipientName) . '</strong>!</p>';
                        $body .= '<p>Segue documento enviado pela equipe MultiLife Care:</p>';
                        $body .= '<div style="background:#f9fafb;padding:18px;margin:20px 0;border-radius:8px"><p style="margin:0"><a href="' . $baseUrl . $relativePath . '">' . htmlspecialchars($fileName) . '</a></p></div>';
                        $htmlBody = email_base_layout('Documento Enviado', $body);
                        $smtp = new SmtpClient();
                        $smtp->send((string)admin_setting_get('smtp.out.from_email', ''), (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care'), $recipientEmail, 'Documento - ' . $fileName, $htmlBody);
                        $sendStatus = 'entregue';
                    } catch (Throwable $e) {
                        $sendStatus = 'falha';
                    }
                }

                try {
                    if ($_hasSendStatus && $_hasSendAction && $_hasRecipientName) {
                        $db->prepare("INSERT INTO document_send_logs (document_source, recipient_type, recipient_id, recipient_email, recipient_name, health_insurer_id, send_method, sent_by_user_id, file_name, file_path, notes, send_status, send_action) VALUES ('manual',?,?,?,?,?,?,?,?,?,?,?,?)")
                            ->execute([$recipientType, $recipientId, $recipientEmail, $recipientName, $healthInsurerId > 0 ? $healthInsurerId : null, $sendMethod, auth_user_id(), $fileName, $relativePath, $notes, $sendStatus, 'envio_inicial']);
                    } else {
                        $db->prepare("INSERT INTO document_send_logs (document_source, recipient_type, recipient_id, recipient_email, health_insurer_id, send_method, sent_by_user_id, file_name, file_path, notes) VALUES ('manual',?,?,?,?,?,?,?,?,?)")
                            ->execute([$recipientType, $recipientId, $recipientEmail, $healthInsurerId > 0 ? $healthInsurerId : null, $sendMethod, auth_user_id(), $fileName, $relativePath, $notes]);
                    }
                } catch (Throwable $e) {}
                if ($_flashError === '') { $_flashSuccess = 'Documento enviado com sucesso!'; }
            } else {
                $_flashError = 'Falha ao salvar arquivo.';
            }
        }
    }
}

// Buscar logs
$sentLogs = [];
try {
    $sentLogs = $db->query("SELECT * FROM document_send_logs ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Separar automaticos e manuais
$autoLogs = [];
$manualLogs = [];
foreach ($sentLogs as $l) {
    if (($l['document_source'] ?? '') === 'manual') {
        $manualLogs[] = $l;
    } else {
        $autoLogs[] = $l;
    }
}

// Dados auxiliares
$insurers = [];
try { $insurers = $db->query("SELECT id, name FROM health_insurers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
$profs = [];
try { $profs = $db->query("SELECT u.id, u.name FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.status = 'active' AND r.slug = 'profissional' ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
$pats = [];
try { $pats = $db->query("SELECT id, full_name FROM patients ORDER BY full_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

view_header('Documentos Enviados');

if ($_flashSuccess !== '') { echo '<div class="alert alertSuccess">' . htmlspecialchars($_flashSuccess) . '</div>'; }
if ($_flashError !== '') { echo '<div class="alert alertError">' . htmlspecialchars($_flashError) . '</div>'; }

echo '<div class="grid">';

// Header
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Gerenciamento de Documentos Enviados</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Visualize, envie e acompanhe documentos para profissionais e pacientes</div>';
echo '</div>';
echo '<a class="btn" href="/documents_list.php">Voltar</a>';
echo '</div>';

// Navegacao interna (sub-abas)
echo '<div style="margin-top:20px;display:flex;gap:4px;border-bottom:2px solid hsl(var(--border));padding-bottom:0">';

$_subTabs = [
    'documentos' => 'Documentos Enviados',
    'enviar' => 'Enviar Extras',
    'historico' => 'Historico',
];
foreach ($_subTabs as $_key => $_label) {
    $_isActive = ($_subTab === $_key);
    $_style = $_isActive
        ? 'background:hsl(var(--primary));color:white;border-color:hsl(var(--primary))'
        : 'background:white;color:hsl(var(--muted-foreground));border-color:transparent';
    echo '<a href="/documents_list.php?tab=sent&sub=' . $_key . '" style="padding:10px 20px;text-decoration:none;font-weight:600;font-size:14px;border:2px solid;border-bottom:none;border-radius:8px 8px 0 0;' . $_style . '">' . $_label . '</a>';
}
echo '</div>';
echo '</section>';

// =====================================================
// SUB-ABA: Documentos Enviados
// =====================================================
if ($_subTab === 'documentos') {
    echo '<section class="card col12">';
    echo '<div style="font-size:16px;font-weight:700;margin-bottom:14px">Documentos Enviados Automaticamente</div>';

    $docsAuto = $autoLogs;
    if (empty($docsAuto)) {
        echo '<div style="padding:30px;text-align:center;color:hsl(var(--muted-foreground))">';
        echo '<div style="font-size:14px">Nenhum documento enviado automaticamente ainda.</div>';
        echo '<div style="font-size:13px;margin-top:6px">Os documentos configurados nas operadoras serao exibidos aqui apos a pre-admissao.</div>';
        echo '</div>';
    } else {
        echo '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">';
        echo '<thead><tr style="border-bottom:2px solid hsl(var(--border))">';
        echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Data/Hora</th>';
        echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Documento</th>';
        echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Destinatario</th>';
        echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Metodo</th>';
        echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Obs</th>';
        echo '<th style="padding:10px 8px;text-align:right;font-size:12px;font-weight:700">Acoes</th>';
        echo '</tr></thead><tbody>';
        foreach ($docsAuto as $l) {
            $fName = (string)($l['file_name'] ?? '');
            $fPath = (string)($l['file_path'] ?? '');
            $dest = (string)($l['recipient_name'] ?? ($l['recipient_email'] ?? '-'));
            echo '<tr style="border-bottom:1px solid hsl(var(--border))">';
            echo '<td style="padding:10px 8px;font-size:12px;white-space:nowrap">' . date('d/m/Y H:i', strtotime((string)$l['created_at'])) . '</td>';
            echo '<td style="padding:10px 8px;font-size:13px;font-weight:600">';
            if ($fPath !== '') {
                echo '<a href="' . htmlspecialchars($fPath) . '" target="_blank" style="color:hsl(var(--primary));text-decoration:none">📄 ' . htmlspecialchars($fName !== '' ? $fName : 'Documento') . '</a>';
            } else {
                echo htmlspecialchars($fName !== '' ? $fName : '-');
            }
            echo '</td>';
            echo '<td style="padding:10px 8px;font-size:12px">' . htmlspecialchars($dest) . '</td>';
            echo '<td style="padding:10px 8px;font-size:12px">' . (($l['send_method'] ?? '') === 'email' ? 'E-mail' : 'Portal') . '</td>';
            echo '<td style="padding:10px 8px;font-size:11px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . htmlspecialchars((string)($l['notes'] ?? '')) . '</td>';
            echo '<td style="padding:10px 8px;text-align:right;white-space:nowrap">';
            if ($fPath !== '') {
                echo '<a href="' . htmlspecialchars($fPath) . '" target="_blank" class="btn" style="padding:4px 10px;font-size:12px">Visualizar</a> ';
                echo '<a href="' . htmlspecialchars($fPath) . '" download class="btn" style="padding:4px 10px;font-size:12px">Baixar</a>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    // Documentos manuais tambem
    if (!empty($manualLogs)) {
        echo '<div style="margin-top:24px;padding-top:16px;border-top:1px solid hsl(var(--border))">';
        echo '<div style="font-size:15px;font-weight:700;margin-bottom:12px">Documentos Enviados Manualmente</div>';
        echo '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">';
        echo '<thead><tr style="border-bottom:2px solid hsl(var(--border))">';
        echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Data/Hora</th>';
        echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Documento</th>';
        echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Destinatario</th>';
        echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Metodo</th>';
        echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Obs</th>';
        echo '<th style="padding:10px 8px;text-align:right;font-size:12px;font-weight:700">Acoes</th>';
        echo '</tr></thead><tbody>';
        foreach ($manualLogs as $l) {
            $fName = (string)($l['file_name'] ?? '');
            $fPath = (string)($l['file_path'] ?? '');
            $dest = (string)($l['recipient_name'] ?? ($l['recipient_email'] ?? '-'));
            echo '<tr style="border-bottom:1px solid hsl(var(--border))">';
            echo '<td style="padding:10px 8px;font-size:12px;white-space:nowrap">' . date('d/m/Y H:i', strtotime((string)$l['created_at'])) . '</td>';
            echo '<td style="padding:10px 8px;font-size:13px;font-weight:600">';
            if ($fPath !== '') {
                echo '<a href="' . htmlspecialchars($fPath) . '" target="_blank" style="color:hsl(var(--primary));text-decoration:none">📎 ' . htmlspecialchars($fName !== '' ? $fName : 'Documento') . '</a>';
            } else {
                echo htmlspecialchars($fName !== '' ? $fName : '-');
            }
            echo '</td>';
            echo '<td style="padding:10px 8px;font-size:12px">' . htmlspecialchars($dest) . '</td>';
            echo '<td style="padding:10px 8px;font-size:12px">' . (($l['send_method'] ?? '') === 'email' ? 'E-mail' : 'Portal') . '</td>';
            echo '<td style="padding:10px 8px;font-size:11px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . htmlspecialchars((string)($l['notes'] ?? '')) . '</td>';
            echo '<td style="padding:10px 8px;text-align:right;white-space:nowrap">';
            if ($fPath !== '') {
                echo '<a href="' . htmlspecialchars($fPath) . '" target="_blank" class="btn" style="padding:4px 10px;font-size:12px">Visualizar</a> ';
                echo '<a href="' . htmlspecialchars($fPath) . '" download class="btn" style="padding:4px 10px;font-size:12px">Baixar</a>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '</div>';
    }

    echo '</section>';
}

// =====================================================
// SUB-ABA: Enviar Extras
// =====================================================
if ($_subTab === 'enviar') {
    echo '<section class="card col12">';
    echo '<div style="font-size:16px;font-weight:700;margin-bottom:6px">Enviar Documentos Complementares</div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:20px">Envie documentos adicionais que nao foram enviados automaticamente ou que a operadora encaminhou posteriormente.</div>';

    echo '<form method="post" action="/documents_list.php?tab=sent&sub=enviar" enctype="multipart/form-data" style="max-width:700px">';
    echo '<input type="hidden" name="action" value="send_manual_doc">';

    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">';
    echo '<div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Tipo de Destinatario *</label>';
    echo '<select name="recipient_type" id="rt" onchange="ur()" required>';
    echo '<option value="">Selecione...</option><option value="professional">Profissional</option><option value="patient">Paciente</option>';
    echo '</select></div>';
    echo '<div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Destinatario *</label>';
    echo '<select name="recipient_id" id="rs" required>';
    echo '<option value="">Selecione o tipo primeiro</option>';
    echo '</select></div>';
    echo '</div>';

    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">';
    echo '<div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Operadora</label>';
    echo '<select name="health_insurer_id"><option value="0">Nenhuma</option>';
    foreach ($insurers as $i) { echo '<option value="' . (int)$i['id'] . '">' . htmlspecialchars($i['name']) . '</option>'; }
    echo '</select></div>';
    echo '<div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Metodo de Envio *</label>';
    echo '<select name="send_method" required>';
    echo '<option value="email">E-mail</option><option value="portal">Disponibilizar no Portal</option>';
    echo '</select></div>';
    echo '</div>';

    echo '<div style="margin-bottom:14px"><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Arquivo *</label>';
    echo '<input type="file" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp">';
    echo '</div>';

    echo '<div style="margin-bottom:20px"><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Observacao</label>';
    echo '<input name="notes" placeholder="Motivo do envio, contexto adicional...">';
    echo '</div>';

    echo '<button type="submit" class="btn btnPrimary">Enviar Documento</button>';
    echo '</form>';
    echo '</section>';
}

// =====================================================
// SUB-ABA: Historico
// =====================================================
if ($_subTab === 'historico') {
    echo '<section class="card col12">';
    echo '<div style="font-size:16px;font-weight:700;margin-bottom:14px">Historico Completo de Envios</div>';

    if (empty($sentLogs)) {
        echo '<div style="padding:30px;text-align:center;color:hsl(var(--muted-foreground))">Nenhum registro no historico.</div>';
    } else {
        echo '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">';
        echo '<thead><tr style="border-bottom:2px solid hsl(var(--border))">';
        echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Data/Hora</th>';
        echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Documento</th>';
        echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Destinatario</th>';
        echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Tipo Envio</th>';
        echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Metodo</th>';
        if ($_hasSendStatus) { echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Status</th>'; }
        if ($_hasSendAction) { echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Acao</th>'; }
        echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700">Responsavel</th>';
        echo '</tr></thead><tbody>';

        foreach ($sentLogs as $l) {
            $fName = (string)($l['file_name'] ?? '');
            $fPath = (string)($l['file_path'] ?? '');
            $dest = (string)($l['recipient_name'] ?? ($l['recipient_email'] ?? '-'));
            $src = ($l['document_source'] ?? '') === 'manual' ? 'Manual' : 'Automatico';
            $srcBg = ($l['document_source'] ?? '') === 'manual' ? '#f3e8ff' : '#e0f7fa';
            $srcColor = ($l['document_source'] ?? '') === 'manual' ? '#7c3aed' : '#0284c7';
            $mth = ($l['send_method'] ?? '') === 'email' ? 'E-mail' : 'Portal';

            // Responsavel
            $sentByName = '-';
            if (!empty($l['sent_by_user_id'])) {
                try {
                    $uStmt = $db->prepare("SELECT name FROM users WHERE id = ?");
                    $uStmt->execute([$l['sent_by_user_id']]);
                    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                    if ($uRow) { $sentByName = (string)$uRow['name']; }
                } catch (Throwable $e) {}
            }

            echo '<tr style="border-bottom:1px solid hsl(var(--border))">';
            echo '<td style="padding:10px 8px;font-size:12px;white-space:nowrap">' . date('d/m/Y H:i', strtotime((string)$l['created_at'])) . '</td>';
            echo '<td style="padding:10px 8px;font-size:13px">';
            if ($fPath !== '') {
                echo '<a href="' . htmlspecialchars($fPath) . '" target="_blank" style="color:hsl(var(--primary))">' . htmlspecialchars($fName !== '' ? $fName : 'Doc') . '</a>';
            } else {
                echo htmlspecialchars($fName !== '' ? $fName : '-');
            }
            echo '</td>';
            echo '<td style="padding:10px 8px;font-size:12px">' . htmlspecialchars($dest) . '</td>';
            echo '<td style="padding:10px 8px"><span style="font-size:11px;padding:3px 8px;border-radius:6px;background:' . $srcBg . ';color:' . $srcColor . ';font-weight:600">' . $src . '</span></td>';
            echo '<td style="padding:10px 8px;font-size:12px">' . $mth . '</td>';

            if ($_hasSendStatus) {
                $status = (string)($l['send_status'] ?? 'enviado');
                $sLabel = 'Enviado'; $sColor = '#0284c7';
                if ($status === 'entregue') { $sLabel = 'Entregue'; $sColor = '#10b981'; }
                elseif ($status === 'falha') { $sLabel = 'Falha'; $sColor = '#ef4444'; }
                echo '<td style="padding:10px 8px"><span style="font-size:11px;padding:3px 8px;border-radius:6px;background:' . $sColor . '20;color:' . $sColor . ';font-weight:700">' . $sLabel . '</span></td>';
            }

            if ($_hasSendAction) {
                $action = (string)($l['send_action'] ?? 'envio_inicial');
                $aLabel = ($action === 'reenvio') ? 'Reenvio' : 'Envio inicial';
                echo '<td style="padding:10px 8px;font-size:12px">' . $aLabel . '</td>';
            }

            echo '<td style="padding:10px 8px;font-size:12px">' . htmlspecialchars($sentByName) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}

echo '</div>';

// JavaScript para o formulario de envio
echo '<script>';
echo 'var P=[';
foreach ($profs as $i => $p) { if ($i) echo ','; echo '[' . (int)$p['id'] . ',"' . addslashes($p['name']) . '"]'; }
echo '];var A=[';
foreach ($pats as $i => $p) { if ($i) echo ','; echo '[' . (int)$p['id'] . ',"' . addslashes($p['full_name']) . '"]'; }
echo '];';
echo 'function ur(){var t=document.getElementById("rt").value,s=document.getElementById("rs");if(!s)return;var l=t==="professional"?P:t==="patient"?A:[];s.innerHTML="<option value=\\'\\'>Selecione...</option>";for(var i=0;i<l.length;i++){var o=document.createElement("option");o.value=l[i][0];o.textContent=l[i][1];s.appendChild(o);}}';
echo '</script>';

view_footer();
