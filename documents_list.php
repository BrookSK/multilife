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

// Se for aba "sent", tratar separadamente
if ($tab === 'sent') {
    // Garantir tabela
    try { db()->exec("CREATE TABLE IF NOT EXISTS document_send_logs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, document_id INT UNSIGNED NOT NULL DEFAULT 0, document_source VARCHAR(50) NOT NULL DEFAULT 'manual', recipient_type VARCHAR(50) NOT NULL DEFAULT 'professional', recipient_id INT UNSIGNED NULL, recipient_email VARCHAR(255) NULL, recipient_name VARCHAR(255) NULL, assignment_id INT UNSIGNED NULL, demand_id INT UNSIGNED NULL, health_insurer_id INT UNSIGNED NULL, send_method VARCHAR(30) NOT NULL DEFAULT 'email', sent_by_user_id INT UNSIGNED NULL, file_name VARCHAR(255) NULL, file_path VARCHAR(500) NULL, notes TEXT NULL, send_status VARCHAR(30) NOT NULL DEFAULT 'enviado', send_action VARCHAR(30) NOT NULL DEFAULT 'envio_inicial', resent_from_log_id INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (Throwable $e) {}

    // Sub-aba
    $sub = isset($_GET['sub']) ? (string)$_GET['sub'] : 'documentos';
    if (!in_array($sub, ['documentos', 'enviar', 'historico'], true)) { $sub = 'documentos'; }

    // POST envio manual - envia por TODOS os canais (email + portal + whatsapp)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_manual_doc') {
        $rt = trim((string)($_POST['recipient_type'] ?? ''));
        $ri = (int)($_POST['recipient_id'] ?? 0);
        $hi = (int)($_POST['health_insurer_id'] ?? 0);
        $nt = trim((string)($_POST['notes'] ?? ''));
        if ($ri > 0 && $rt !== '' && isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $fn = $_FILES['document']['name'];
            $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','webp'], true) && $_FILES['document']['size'] <= 10485760) {
                $dir = __DIR__ . '/uploads/manual_docs/';
                if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
                $un = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['document']['tmp_name'], $dir . $un)) {
                    $rp = '/uploads/manual_docs/' . $un;
                    $re = '';
                    $rn = '';
                    $rPhone = '';
                    try {
                        if ($rt === 'professional') { $s = db()->prepare("SELECT name, email, phone FROM users WHERE id = ?"); $s->execute([$ri]); $row = $s->fetch(PDO::FETCH_ASSOC); $re = (string)($row['email'] ?? ''); $rn = (string)($row['name'] ?? ''); $rPhone = (string)($row['phone'] ?? ''); }
                        else { $s = db()->prepare("SELECT full_name, email, whatsapp FROM patients WHERE id = ?"); $s->execute([$ri]); $row = $s->fetch(PDO::FETCH_ASSOC); $re = (string)($row['email'] ?? ''); $rn = (string)($row['full_name'] ?? ''); $rPhone = (string)($row['whatsapp'] ?? ''); }
                    } catch (Throwable $e) {}

                    $statusEmail = 'pendente';
                    $statusPortal = 'enviado';
                    $statusWhatsapp = 'pendente';
                    $baseUrl = rtrim((string)admin_setting_get('app.base_url', 'https://multilife.onsolutionsbrasil.com.br'), '/');

                    // 1. ENVIO POR E-MAIL
                    if ($re !== '' && filter_var($re, FILTER_VALIDATE_EMAIL)) {
                        try {
                            require_once __DIR__ . '/app/email_base_template.php';
                            $docIcon = preg_match('/\.pdf$/i', $fn) ? '📄' : (preg_match('/\.(doc|docx)$/i', $fn) ? '📝' : (preg_match('/\.(jpg|jpeg|png|webp)$/i', $fn) ? '🖼️' : '📎'));
                            $body = '<p style="font-size:15px;color:#374151">Olá, <strong>' . htmlspecialchars($rn) . '</strong>!</p>';
                            $body .= '<p style="font-size:14px;color:#4b5563">A equipe MultiLife Care enviou um documento complementar para o seu atendimento.</p>';
                            $body .= '<div style="background:#f9fafb;padding:20px;margin:20px 0;border-radius:10px;border:1px solid #e5e7eb">';
                            $body .= '<h3 style="margin:0 0 12px;font-size:15px;font-weight:700;color:#374151">Documento Enviado</h3>';
                            $body .= '<p style="margin:6px 0;font-size:14px">' . $docIcon . ' <a href="' . $baseUrl . $rp . '" style="color:#0284c7;text-decoration:underline;font-weight:600">' . htmlspecialchars($fn) . '</a></p>';
                            if ($nt !== '') { $body .= '<p style="margin:12px 0 0;font-size:13px;color:#6b7280;border-top:1px solid #e5e7eb;padding-top:10px"><strong>Observação:</strong> ' . nl2br(htmlspecialchars($nt)) . '</p>'; }
                            $body .= '</div>';
                            $portalAtivo = (string)admin_setting_get('feature.portal_profissional_ativo', '0') === '1';
                            if ($portalAtivo) { $body .= '<p style="font-size:14px;color:#4b5563">Este documento também está disponível no seu <strong>Portal do Profissional</strong>, na seção de Documentos.</p>'; }
                            $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
                            $htmlBody = email_base_layout('Documento Complementar Enviado', $body);
                            // Threading - buscar Message-ID original do profissional
                            $threadMsgId = null;
                            $threadPatientName = '';
                            if ($rt === 'professional' && $ri > 0) {
                                try {
                                    $thS = db()->prepare("SELECT ar.sent_message_id, p.full_name FROM authorization_requests ar INNER JOIN patient_assignments pa ON pa.demand_id = ar.demand_id INNER JOIN patients p ON p.id = ar.patient_id WHERE pa.professional_user_id = ? AND ar.sent_message_id IS NOT NULL ORDER BY ar.id DESC LIMIT 1");
                                    $thS->execute([$ri]);
                                    $thRow = $thS->fetch(PDO::FETCH_ASSOC);
                                    if ($thRow) { $threadMsgId = $thRow['sent_message_id']; $threadPatientName = (string)($thRow['full_name'] ?? ''); }
                                } catch (Throwable $e) {}
                            }
                            $threadSubject = $threadPatientName !== '' ? 'Re: Proposta de Atendimento - ' . $threadPatientName : 'Re: Proposta de Atendimento';
                            $smtp = new SmtpClient();
                            $smtp->send((string)admin_setting_get('smtp.out.from_email', ''), (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care'), $re, $threadSubject, $htmlBody, $threadMsgId, $threadMsgId);
                            $statusEmail = 'enviado';
                        } catch (Throwable $e) { $statusEmail = 'falha'; }
                    } else { $statusEmail = 'falha'; }

                    // 2. PORTAL - sempre disponivel (o registro no document_send_logs ja garante)
                    $statusPortal = 'enviado';

                    // 3. ENVIO POR WHATSAPP
                    if ($rPhone !== '') {
                        try {
                            $cleanPhone = preg_replace('/\D+/', '', $rPhone);
                            if (strlen($cleanPhone) === 10 || strlen($cleanPhone) === 11) { $cleanPhone = '55' . $cleanPhone; }
                            if (strlen($cleanPhone) >= 12) {
                                $wApi = null;
                                $wBaseUrl = rtrim((string)admin_setting_get('evolution.base_url', ''), '/');
                                $wApiKey = (string)admin_setting_get('evolution.api_key', '');
                                // Buscar instancia conectada
                                try {
                                    $wInstStmt = db()->prepare("SELECT instance_name FROM whatsapp_instances WHERE status = 'active' AND connection_status = 'connected' ORDER BY is_default DESC, id ASC LIMIT 5");
                                    $wInstStmt->execute();
                                    $wInstList = $wInstStmt->fetchAll(PDO::FETCH_COLUMN);
                                    foreach ($wInstList as $wInstName) {
                                        if ((string)$wInstName === '' || $wBaseUrl === '' || $wApiKey === '') continue;
                                        try {
                                            $tryApi = new EvolutionApiV1($wBaseUrl, $wApiKey, (string)$wInstName);
                                            $connRes = $tryApi->connectionState();
                                            $state = strtolower(trim((string)($connRes['json']['instance']['state'] ?? ($connRes['json']['state'] ?? ''))));
                                            if (in_array($state, ['open', 'connected'], true)) {
                                                $wApi = $tryApi;
                                                break;
                                            }
                                        } catch (Throwable $e) { continue; }
                                    }
                                } catch (Throwable $e) {}
                                if ($wApi === null && $wBaseUrl !== '' && $wApiKey !== '') {
                                    $wDefInst = (string)admin_setting_get('evolution.instance', '');
                                    if ($wDefInst !== '') { try { $wApi = new EvolutionApiV1($wBaseUrl, $wApiKey, $wDefInst); } catch (Throwable $e) {} }
                                }
                                if ($wApi !== null) {
                                    $whatsMsg = "📎 *Documento Complementar*\n\nOlá, " . $rn . "!\n\nA equipe MultiLife Care enviou um documento complementar:\n\n📄 *" . $fn . "*\n" . $baseUrl . $rp;
                                    if ($nt !== '') { $whatsMsg .= "\n\n📝 _" . $nt . "_"; }
                                    $portalAtivoW = (string)admin_setting_get('feature.portal_profissional_ativo', '0') === '1';
                                    if ($portalAtivoW) { $whatsMsg .= "\n\nEste documento também está disponível no Portal do Profissional."; }
                                    $res = $wApi->sendText($cleanPhone, $whatsMsg);
                                    $statusWhatsapp = (isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300) ? 'enviado' : 'falha';
                                } else { $statusWhatsapp = 'falha'; }
                            } else { $statusWhatsapp = 'falha'; }
                        } catch (Throwable $e) { $statusWhatsapp = 'falha'; }
                    } else { $statusWhatsapp = 'falha'; }

                    // Registrar no log (todos os canais)
                    try { db()->prepare("INSERT INTO document_send_logs (document_source, recipient_type, recipient_id, recipient_email, health_insurer_id, send_method, sent_by_user_id, file_name, file_path, notes) VALUES ('manual',?,?,?,?,?,?,?,?,?)")->execute([$rt, $ri, $re, $hi > 0 ? $hi : null, 'todos', auth_user_id(), $fn, $rp, $nt]); } catch (Throwable $e) {}

                    // Mensagem de resultado
                    $resultParts = [];
                    $resultParts[] = 'E-mail: ' . ($statusEmail === 'enviado' ? '✅' : '❌');
                    $resultParts[] = 'Portal: ' . ($statusPortal === 'enviado' ? '✅' : '❌');
                    $resultParts[] = 'WhatsApp: ' . ($statusWhatsapp === 'enviado' ? '✅' : '❌');
                    $allOk = ($statusEmail === 'enviado' && $statusWhatsapp === 'enviado');
                    if ($allOk) {
                        flash_set('success', 'Documento enviado por todos os canais! ' . implode(' | ', $resultParts));
                    } else {
                        flash_set('success', 'Documento processado. ' . implode(' | ', $resultParts));
                    }
                    header('Location: /documents_list.php?tab=sent&sub=enviar');
                    exit;
                }
            }
        }
    }

    // Buscar logs
    $sentLogs = [];
    try { $sentLogs = db()->query("SELECT * FROM document_send_logs ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

    // Separar
    $autoLogs = [];
    $manualLogs = [];
    foreach ($sentLogs as $l) {
        if (($l['document_source'] ?? '') === 'manual') { $manualLogs[] = $l; } else { $autoLogs[] = $l; }
    }

    // Auxiliares
    $insurers = [];
    try { $insurers = db()->query("SELECT id, name FROM health_insurers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    $profs = [];
    try { $profs = db()->query("SELECT u.id, u.name FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.status = 'active' AND r.slug = 'profissional' ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    $pats = [];
    try { $pats = db()->query("SELECT id, full_name FROM patients ORDER BY full_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

    view_header('Documentos Enviados');

    $fl = flash_get('success');
    if ($fl) { echo '<div class="alert alertSuccess">' . htmlspecialchars($fl) . '</div>'; }

    echo '<div class="grid">';
    echo '<section class="card col12">';
    echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
    echo '<div><div style="font-size:22px;font-weight:900">Gerenciamento de Documentos Enviados</div>';
    echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Visualize, envie e acompanhe documentos para profissionais e pacientes</div></div>';
    echo '<a class="btn" href="/documents_list.php">Voltar</a>';
    echo '</div>';

    // Sub-abas
    echo '<div style="margin-top:20px;display:flex;gap:4px;border-bottom:2px solid hsl(var(--border))">';
    $subTabs = ['documentos' => 'Documentos Enviados', 'enviar' => 'Enviar Extras', 'historico' => 'Historico'];
    foreach ($subTabs as $k => $lbl) {
        $act = ($sub === $k);
        $st = $act ? 'background:hsl(var(--primary));color:white' : 'background:hsl(var(--card));color:hsl(var(--muted-foreground))';
        echo '<a href="/documents_list.php?tab=sent&sub=' . $k . '" style="padding:10px 20px;text-decoration:none;font-weight:600;font-size:14px;border-radius:8px 8px 0 0;' . $st . '">' . $lbl . '</a>';
    }
    echo '</div>';
    echo '</section>';

    // === DOCUMENTOS ===
    if ($sub === 'documentos') {
        echo '<section class="card col12">';
        if (empty($autoLogs) && empty($manualLogs)) {
            echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))"><div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum documento enviado ainda</div><div style="font-size:14px">Os documentos aparecerao aqui quando forem enviados.</div></div>';
        } else {
            if (!empty($autoLogs)) {
                echo '<div style="font-size:15px;font-weight:700;margin-bottom:12px">Enviados Automaticamente</div>';
                echo '<div style="overflow:auto"><table><thead><tr><th>Data</th><th>Documento</th><th>Destinatario</th><th>Metodo</th><th>Obs</th><th style="text-align:right">Acoes</th></tr></thead><tbody>';
                foreach ($autoLogs as $l) {
                    $fp = (string)($l['file_path'] ?? '');
                    $fn2 = (string)($l['file_name'] ?? '');
                    $de = (string)($l['recipient_name'] ?? ($l['recipient_email'] ?? '-'));
                    echo '<tr>';
                    echo '<td style="font-size:12px;white-space:nowrap">' . date('d/m/Y H:i', strtotime($l['created_at'])) . '</td>';
                    echo '<td>';
                    if ($fp !== '') { echo '<a href="' . htmlspecialchars($fp) . '" target="_blank" style="color:hsl(var(--primary))">📄 ' . htmlspecialchars($fn2 ?: 'Doc') . '</a>'; } else { echo htmlspecialchars($fn2 ?: '-'); }
                    echo '</td>';
                    echo '<td style="font-size:12px">' . htmlspecialchars($de) . '</td>';
                    echo '<td style="font-size:11px">' . (($l['send_method'] ?? '') === 'email' ? 'E-mail' : 'Portal') . '</td>';
                    echo '<td style="font-size:11px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . htmlspecialchars($l['notes'] ?? '') . '</td>';
                    echo '<td style="text-align:right">';
                    if ($fp !== '') { echo '<a href="' . htmlspecialchars($fp) . '" target="_blank" class="btn" style="padding:4px 10px;font-size:12px">Ver</a> <a href="' . htmlspecialchars($fp) . '" download class="btn" style="padding:4px 10px;font-size:12px">Baixar</a>'; }
                    echo '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
            if (!empty($manualLogs)) {
                echo '<div style="margin-top:20px;padding-top:16px;border-top:1px solid hsl(var(--border));font-size:15px;font-weight:700;margin-bottom:12px">Enviados Manualmente</div>';
                echo '<div style="overflow:auto"><table><thead><tr><th>Data</th><th>Documento</th><th>Destinatario</th><th>Metodo</th><th>Obs</th><th style="text-align:right">Acoes</th></tr></thead><tbody>';
                foreach ($manualLogs as $l) {
                    $fp = (string)($l['file_path'] ?? '');
                    $fn2 = (string)($l['file_name'] ?? '');
                    $de = (string)($l['recipient_name'] ?? ($l['recipient_email'] ?? '-'));
                    echo '<tr>';
                    echo '<td style="font-size:12px;white-space:nowrap">' . date('d/m/Y H:i', strtotime($l['created_at'])) . '</td>';
                    echo '<td>';
                    if ($fp !== '') { echo '<a href="' . htmlspecialchars($fp) . '" target="_blank" style="color:hsl(var(--primary))">📎 ' . htmlspecialchars($fn2 ?: 'Doc') . '</a>'; } else { echo htmlspecialchars($fn2 ?: '-'); }
                    echo '</td>';
                    echo '<td style="font-size:12px">' . htmlspecialchars($de) . '</td>';
                    echo '<td style="font-size:11px">' . (($l['send_method'] ?? '') === 'email' ? 'E-mail' : 'Portal') . '</td>';
                    echo '<td style="font-size:11px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . htmlspecialchars($l['notes'] ?? '') . '</td>';
                    echo '<td style="text-align:right">';
                    if ($fp !== '') { echo '<a href="' . htmlspecialchars($fp) . '" target="_blank" class="btn" style="padding:4px 10px;font-size:12px">Ver</a> <a href="' . htmlspecialchars($fp) . '" download class="btn" style="padding:4px 10px;font-size:12px">Baixar</a>'; }
                    echo '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
        }
        echo '</section>';
    }

    // === ENVIAR ===
    if ($sub === 'enviar') {
        echo '<section class="card col12">';
        echo '<div style="font-size:16px;font-weight:700;margin-bottom:6px">Enviar Documentos Complementares</div>';
        echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:20px">Envie documentos adicionais ao profissional ou paciente.</div>';
        echo '<form method="post" action="/documents_list.php?tab=sent&sub=enviar" enctype="multipart/form-data" style="max-width:700px">';
        echo '<input type="hidden" name="action" value="send_manual_doc">';
        echo '<div class="grid"><div class="col6"><label>Tipo *<select name="recipient_type" id="rt" onchange="ur()" required><option value="">Selecione</option><option value="professional">Profissional</option><option value="patient">Paciente</option></select></label></div>';
        echo '<div class="col6"><label>Destinatario *<select name="recipient_id" id="rs" required><option value="">Selecione tipo</option></select></label></div>';
        echo '<div class="col6"><label>Operadora<select name="health_insurer_id"><option value="0">Nenhuma</option>';
        foreach ($insurers as $i) { echo '<option value="' . (int)$i['id'] . '">' . htmlspecialchars($i['name']) . '</option>'; }
        echo '</select></label></div>';
        echo '<div class="col12"><label>Arquivo *<input type="file" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp"></label></div>';
        echo '<div class="col12"><label>Observacao<input name="notes" placeholder="Motivo do envio..."></label></div>';
        echo '</div>';
        echo '<div style="margin-top:8px;padding:12px;background:#f0f9ff;border-radius:8px;font-size:12px;color:#0369a1">📡 O documento será enviado automaticamente por <strong>E-mail</strong>, <strong>Portal do Profissional</strong> e <strong>WhatsApp</strong>.</div>';
        echo '<div style="margin-top:16px"><button type="submit" class="btn btnPrimary">Enviar Documento</button></div>';
        echo '</form>';
        echo '</section>';
    }

    // === HISTORICO ===
    if ($sub === 'historico') {
        echo '<section class="card col12">';
        echo '<div style="font-size:16px;font-weight:700;margin-bottom:14px">Historico Completo de Envios</div>';
        if (empty($sentLogs)) {
            echo '<div style="padding:30px;text-align:center;color:hsl(var(--muted-foreground))">Nenhum registro.</div>';
        } else {
            echo '<div style="overflow:auto"><table><thead><tr><th>Data</th><th>Documento</th><th>Destinatario</th><th>Tipo Envio</th><th>Metodo</th><th>Obs</th></tr></thead><tbody>';
            foreach ($sentLogs as $l) {
                $fp = (string)($l['file_path'] ?? '');
                $fn2 = (string)($l['file_name'] ?? '');
                $de = (string)($l['recipient_name'] ?? ($l['recipient_email'] ?? '-'));
                $src = ($l['document_source'] ?? '') === 'manual' ? 'Manual' : 'Auto';
                echo '<tr>';
                echo '<td style="font-size:12px;white-space:nowrap">' . date('d/m/Y H:i', strtotime($l['created_at'])) . '</td>';
                echo '<td>';
                if ($fp !== '') { echo '<a href="' . htmlspecialchars($fp) . '" target="_blank" style="color:hsl(var(--primary))">' . htmlspecialchars($fn2 ?: 'Doc') . '</a>'; } else { echo htmlspecialchars($fn2 ?: '-'); }
                echo '</td>';
                echo '<td style="font-size:12px">' . htmlspecialchars($de) . '</td>';
                echo '<td style="font-size:11px">' . $src . '</td>';
                echo '<td style="font-size:11px">' . (($l['send_method'] ?? '') === 'email' ? 'E-mail' : 'Portal') . '</td>';
                echo '<td style="font-size:11px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . htmlspecialchars($l['notes'] ?? '') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';
    }

    echo '</div>';

    // JS
    echo '<script>var P=[';
    foreach ($profs as $i => $p) { if ($i) echo ','; echo '[' . (int)$p['id'] . ',"' . addslashes($p['name']) . '"]'; }
    echo '];var A=[';
    foreach ($pats as $i => $p) { if ($i) echo ','; echo '[' . (int)$p['id'] . ',"' . addslashes($p['full_name']) . '"]'; }
    echo '];function ur(){var t=document.getElementById("rt");if(!t)return;var v=t.value,s=document.getElementById("rs");if(!s)return;var l=v==="professional"?P:v==="patient"?A:[];s.innerHTML="";for(var i=0;i<l.length;i++){var o=document.createElement("option");o.value=l[i][0];o.textContent=l[i][1];s.appendChild(o);}}</script>';

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
