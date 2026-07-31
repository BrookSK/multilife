<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();
rbac_require_permission('appointments.manage');

// Aumentar limites de upload para esta pagina
@ini_set('upload_max_filesize', '50M');
@ini_set('post_max_size', '55M');
@ini_set('max_execution_time', '120');

$db = db();

// Profissional selecionado
$profId = isset($_GET['prof_id']) ? (int)$_GET['prof_id'] : 0;
if ($profId === 0 && isset($_POST['prof_id'])) { $profId = (int)$_POST['prof_id']; }
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

// POST: Envio de fichas de sessao
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_session_docs' && $profId > 0) {
    $docNotes = trim((string)($_POST['doc_notes'] ?? ''));
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $filesProcessed = 0;
    $results = [];

    // Buscar dados do profissional
    $profRow = $db->prepare("SELECT name, email, phone FROM users WHERE id = ?");
    $profRow->execute([$profId]);
    $profInfo = $profRow->fetch(PDO::FETCH_ASSOC);
    $profName = (string)($profInfo['name'] ?? '');
    $profEmail = (string)($profInfo['email'] ?? '');
    $profPhone = (string)($profInfo['phone'] ?? '');
    $baseUrl = rtrim((string)admin_setting_get('app.base_url', 'https://multilife.onsolutionsbrasil.com.br'), '/');

    $fileFields = ['ficha_evolucao' => 'Ficha de Evolucao', 'ficha_produtividade' => 'Ficha de Produtividade'];

    // Primeiro: salvar todos os arquivos
    $uploadedFiles = [];
    $missingFiles = [];
    foreach ($fileFields as $fieldName => $label) {
        if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
            $errCode = isset($_FILES[$fieldName]) ? (int)$_FILES[$fieldName]['error'] : 4;
            if ($errCode !== 4) {
                $errMsgs = [1 => 'Excede limite do servidor', 2 => 'Excede limite do form', 3 => 'Upload parcial', 6 => 'Sem pasta temporaria', 7 => 'Falha ao gravar'];
                $missingFiles[] = $label . ' (' . ($errMsgs[$errCode] ?? "erro $errCode") . ')';
            } else {
                $missingFiles[] = $label;
            }
            error_log("[ADMIN_SESSIONS] Arquivo nao enviado: $fieldName error=$errCode");
            continue;
        }
        $file = $_FILES[$fieldName];
        $fn = $file['name'];
        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
        if ($file['size'] > 52428800) {
            $missingFiles[] = $label . ' (arquivo muito grande - max 50MB)';
            continue;
        }

        $dir = __DIR__ . '/uploads/manual_docs/';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $un = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . $un)) {
            $missingFiles[] = $label . ' (falha upload)';
            continue;
        }
        $rp = '/uploads/manual_docs/' . $un;
        $uploadedFiles[] = ['label' => $label, 'file_name' => $fn, 'file_path' => $rp];
        error_log("[ADMIN_SESSIONS] Arquivo salvo: $fieldName -> $rp");
    }

    if (!empty($uploadedFiles)) {
        $filesProcessed = count($uploadedFiles);
        $stEmail = 'pendente'; $stWhats = 'pendente';

        // ENVIO UNICO POR E-MAIL (todas as fichas no mesmo email)
        if ($profEmail !== '' && filter_var($profEmail, FILTER_VALIDATE_EMAIL)) {
            try {
                require_once __DIR__ . '/app/email_base_template.php';
                $body = '<p style="font-size:15px;color:#374151">Ola, <strong>' . htmlspecialchars($profName) . '</strong>!</p>';
                $body .= '<p style="font-size:14px;color:#4b5563">A equipe administrativa enviou os documentos das sessoes. Confira abaixo:</p>';

                foreach ($uploadedFiles as $idx => $uf) {
                    if ($idx > 0) {
                        $body .= '<div style="border-top:2px solid hsl(180 65% 46%);margin:24px 0;opacity:.3"></div>';
                    }
                    $docIcon = preg_match('/\.pdf$/i', $uf['file_name']) ? '📄' : '🖼️';
                    $body .= '<div style="background:#f9fafb;padding:20px;margin:16px 0;border-radius:10px;border:1px solid #e5e7eb;border-left:4px solid hsl(180 65% 46%)">';
                    $body .= '<h3 style="margin:0 0 10px;font-size:15px;font-weight:700;color:#374151">' . $docIcon . ' ' . $uf['label'] . '</h3>';
                    $body .= '<p style="margin:6px 0;font-size:14px"><a href="' . $baseUrl . $uf['file_path'] . '" style="color:#0284c7;text-decoration:underline;font-weight:600">' . htmlspecialchars($uf['file_name']) . '</a></p>';
                    $body .= '</div>';
                }

                if ($docNotes !== '') {
                    $body .= '<div style="background:#fffbeb;padding:14px;border-radius:8px;margin:16px 0;border:1px solid #fde68a">';
                    $body .= '<p style="margin:0;font-size:13px;color:#92400e"><strong>Observacao:</strong> ' . nl2br(htmlspecialchars($docNotes)) . '</p>';
                    $body .= '</div>';
                }

                // Informar fichas nao anexadas
                if (!empty($missingFiles)) {
                    $body .= '<div style="background:#f3f4f6;padding:12px;border-radius:8px;margin:16px 0;border:1px solid #d1d5db">';
                    $body .= '<p style="margin:0;font-size:12px;color:#6b7280">⚠️ Nao anexado(s): ' . htmlspecialchars(implode(', ', $missingFiles)) . '</p>';
                    $body .= '</div>';
                }

                $body .= '<p style="font-size:14px;color:#4b5563;margin-top:20px">Estes documentos tambem estao disponiveis no seu <strong>Portal do Profissional</strong>.</p>';
                $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';

                $emailTitle = count($uploadedFiles) > 1 ? 'Fichas de Sessao' : $uploadedFiles[0]['label'];
                $emailSubject = count($uploadedFiles) > 1
                    ? 'Fichas de Sessao - ' . implode(' e ', array_map(function($f) { return $f['label']; }, $uploadedFiles))
                    : $uploadedFiles[0]['label'] . ' - ' . $uploadedFiles[0]['file_name'];

                $htmlBody = email_base_layout($emailTitle, $body);
                $smtpInstance = new SmtpClient();
                $smtpInstance->send((string)admin_setting_get('smtp.out.from_email', ''), (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care'), $profEmail, $emailSubject, $htmlBody);
                $stEmail = 'enviado';
            } catch (Throwable $e) { $stEmail = 'falha'; error_log("[ADMIN_SESSIONS] Erro e-mail: " . $e->getMessage()); }
        }

        // ENVIO UNICO POR WHATSAPP (uma mensagem com todos os docs)
        if ($profPhone !== '') {
            try {
                $cleanPhone = preg_replace('/\D+/', '', $profPhone);
                if (strlen($cleanPhone) === 10 || strlen($cleanPhone) === 11) { $cleanPhone = '55' . $cleanPhone; }
                if (strlen($cleanPhone) >= 12) {
                    $api = null;
                    $wBaseUrl = rtrim((string)admin_setting_get('evolution.base_url', ''), '/');
                    $wApiKey = (string)admin_setting_get('evolution.api_key', '');
                    $wInstance = (string)admin_setting_get('evolution.instance', '');

                    // Buscar instancia CONECTADA (nao confiar apenas na padrao)
                    try {
                        $instStmt = $db->prepare("SELECT instance_name FROM whatsapp_instances WHERE status = 'active' AND connection_status = 'connected' ORDER BY is_default DESC, id ASC LIMIT 5");
                        $instStmt->execute();
                        $connectedInstances = $instStmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        foreach ($connectedInstances as $instName) {
                            if ((string)$instName === '' || $wBaseUrl === '' || $wApiKey === '') continue;
                            try {
                                $tryApi = new EvolutionApiV1($wBaseUrl, $wApiKey, (string)$instName);
                                $connRes = $tryApi->connectionState();
                                $state = strtolower(trim((string)($connRes['json']['instance']['state'] ?? ($connRes['json']['state'] ?? ''))));
                                if (in_array($state, ['open', 'connected'], true)) {
                                    $api = $tryApi;
                                    error_log("[ADMIN_SESSIONS] WhatsApp: usando instancia conectada '$instName'");
                                    break;
                                }
                            } catch (Throwable $e) { continue; }
                        }
                    } catch (Throwable $e) {}

                    // Fallback: instancia padrao (pode falhar)
                    if ($api === null && $wBaseUrl !== '' && $wApiKey !== '' && $wInstance !== '') {
                        try {
                            $api = new EvolutionApiV1($wBaseUrl, $wApiKey, $wInstance);
                            error_log("[ADMIN_SESSIONS] WhatsApp: fallback para instancia padrao '$wInstance'");
                        } catch (Throwable $e) {}
                    }

                    if ($api !== null) {
                        $msg = "📋 *Documentos das Sessoes*\n\nOla, " . $profName . "!\n\nA equipe enviou os seguintes documentos:\n";
                        foreach ($uploadedFiles as $uf) {
                            $msg .= "\n📄 *" . $uf['label'] . "*\n" . $baseUrl . $uf['file_path'] . "\n";
                        }
                        if ($docNotes !== '') { $msg .= "\n📝 _" . $docNotes . "_"; }
                        $msg .= "\n\nDocumentos disponiveis tambem no Portal do Profissional.";
                        error_log("[ADMIN_SESSIONS] WhatsApp: enviando para $cleanPhone via instancia " . $api->getInstance());
                        $res = $api->sendText($cleanPhone, $msg);
                        $httpCode = (int)($res['status'] ?? 0);
                        $stWhats = ($httpCode >= 200 && $httpCode < 300) ? 'enviado' : 'falha';
                        if ($stWhats === 'falha') { error_log("[ADMIN_SESSIONS] WhatsApp falha HTTP=$httpCode response=" . json_encode($res['json'] ?? '')); }
                    } else {
                        $stWhats = 'falha';
                        error_log("[ADMIN_SESSIONS] WhatsApp: nenhuma instancia disponivel");
                    }
                }
            } catch (Throwable $e) { $stWhats = 'falha'; error_log("[ADMIN_SESSIONS] Erro WhatsApp: " . $e->getMessage()); }
        }

        // Registrar no log (cada arquivo separadamente para historico)
        foreach ($uploadedFiles as $uf) {
            $noteText = $uf['label'] . ' - Atendimento #' . $assignmentId . ' Sessao #' . $sessionId . ($docNotes !== '' ? ' - ' . $docNotes : '');
            try { $db->prepare("INSERT INTO document_send_logs (document_source, recipient_type, recipient_id, recipient_email, assignment_id, send_method, sent_by_user_id, file_name, file_path, notes) VALUES ('manual','professional',?,?,?,'todos',?,?,?,?)")->execute([$profId, $profEmail, $assignmentId > 0 ? $assignmentId : null, auth_user_id(), $uf['file_name'], $uf['file_path'], $noteText]); } catch (Throwable $e) {}
        }

        $results[] = 'E-mail: ' . ($stEmail === 'enviado' ? '✅' : '❌') . ' | Portal: ✅ | WhatsApp: ' . ($stWhats === 'enviado' ? '✅' : '❌');
        flash_set('success', $filesProcessed . ' documento(s) enviado(s)! ' . implode(' — ', $results));
    } else {
        $errorDetail = !empty($missingFiles) ? ' Motivo: ' . implode(', ', $missingFiles) : '';
        flash_set('error', 'Selecione pelo menos um arquivo para enviar.' . $errorDetail);
    }
    header('Location: /admin_professional_sessions.php?prof_id=' . $profId);
    exit;
}

// Buscar lista de profissionais
$profs = [];
try {
    $sql = "SELECT u.id, u.name, u.email, u.phone, u.specialty FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.status = 'active' AND r.slug = 'profissional'";
    if ($q !== '') {
        $sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.specialty LIKE ?)";
        $stmt = $db->prepare($sql . " ORDER BY u.name");
        $stmt->execute(["%$q%", "%$q%", "%$q%"]);
    } else {
        $stmt = $db->query($sql . " ORDER BY u.name");
    }
    $profs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Se profissional selecionado, buscar dados
$profData = null;
$patients = [];
$pendingDocs = [];
$allSessions = [];

if ($profId > 0) {
    // Dados do profissional
    try {
        $s = $db->prepare("SELECT id, name, email, phone, specialty FROM users WHERE id = ?");
        $s->execute([$profId]);
        $profData = $s->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    if ($profData) {
        // Pacientes/Captacoes do profissional
        try {
            $pStmt = $db->prepare("
                SELECT p.id, p.full_name, p.cpf, p.phone_primary,
                    pa.id as assignment_id, pa.specialty, pa.service_type,
                    pa.session_quantity, pa.status, pa.created_at,
                    (SELECT COUNT(*) FROM billing_document_requirements bdr WHERE bdr.assignment_id = pa.id AND bdr.status = 'pending') as pending_docs
                FROM patient_assignments pa
                INNER JOIN patients p ON p.id = pa.patient_id
                WHERE pa.professional_user_id = ?
                ORDER BY pa.created_at DESC
            ");
            $pStmt->execute([$profId]);
            $patients = $pStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        // Documentos pendentes (sessoes)
        try {
            $dStmt = $db->prepare("
                SELECT bdr.id, bdr.session_number, bdr.session_date, bdr.status as doc_status,
                    bdr.file_path, bdr.uploaded_at,
                    pa.id as assignment_id, pa.session_quantity,
                    p.full_name as patient_name, pa.specialty
                FROM billing_document_requirements bdr
                INNER JOIN patient_assignments pa ON pa.id = bdr.assignment_id
                INNER JOIN patients p ON p.id = pa.patient_id
                WHERE bdr.professional_user_id = ?
                ORDER BY pa.id ASC, bdr.session_number ASC
            ");
            $dStmt->execute([$profId]);
            $allSessions = $dStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        $pendingDocs = array_filter($allSessions, function($d) {
            return in_array($d['doc_status'], ['pending', 'rejected'], true);
        });
    }
}

view_header('Gestao de Sessoes - Admin');

echo '<div class="grid">';

// Header
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Gestao Administrativa de Sessoes</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Acesse captacoes, sessoes e documentos dos profissionais sem precisar entrar na conta deles</div>';
echo '</div>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</section>';

// Busca de profissional
echo '<section class="card col12">';
echo '<div style="font-size:15px;font-weight:700;margin-bottom:12px">Selecionar Profissional</div>';
echo '<form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">';
echo '<div style="flex:1;min-width:200px"><input name="q" value="' . htmlspecialchars($q) . '" placeholder="Buscar por nome, e-mail ou especialidade..."></div>';
echo '<button class="btn btnPrimary" type="submit">Buscar</button>';
if ($q !== '' || $profId > 0) { echo '<a class="btn" href="/admin_professional_sessions.php">Limpar</a>'; }
echo '</form>';

// Lista de profissionais para selecionar
if ($profId === 0 && !empty($profs)) {
    echo '<div style="margin-top:16px;overflow:auto"><table><thead><tr><th>Nome</th><th>E-mail</th><th>Especialidade</th><th style="text-align:right">Acoes</th></tr></thead><tbody>';
    foreach ($profs as $p) {
        echo '<tr>';
        echo '<td style="font-weight:600">' . htmlspecialchars($p['name']) . '</td>';
        echo '<td style="font-size:12px">' . htmlspecialchars($p['email'] ?? '') . '</td>';
        echo '<td style="font-size:12px">' . htmlspecialchars($p['specialty'] ?? '-') . '</td>';
        echo '<td style="text-align:right"><a class="btn btnPrimary" href="/admin_professional_sessions.php?prof_id=' . (int)$p['id'] . '" style="font-size:12px;padding:6px 12px">Acessar</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

// Se profissional selecionado, mostrar painel completo
if ($profData) {
    // Info do profissional
    echo '<section class="card col12" style="background:hsl(var(--accent))">';
    echo '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">';
    echo '<div>';
    echo '<div style="font-size:18px;font-weight:800">' . htmlspecialchars($profData['name']) . '</div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-top:4px">' . htmlspecialchars($profData['email'] ?? '') . ' | ' . htmlspecialchars($profData['phone'] ?? '') . ' | ' . htmlspecialchars($profData['specialty'] ?? '') . '</div>';
    echo '</div>';
    echo '<a class="btn" href="/admin_professional_sessions.php' . ($q !== '' ? '?q=' . urlencode($q) : '') . '">Trocar Profissional</a>';
    echo '</div>';
    echo '</section>';

    // Resumo
    $totalPatients = count($patients);
    $totalPending = count($pendingDocs);
    $totalSessions = count($allSessions);
    $totalActive = count(array_filter($patients, function($p) { return in_array($p['status'], ['admitted', 'awaiting_financial_approval', 'approved']); }));

    echo '<div class="col12" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px">';
    echo '<div class="card" style="text-align:center;padding:14px"><div style="font-size:24px;font-weight:800;color:hsl(var(--primary))">' . $totalPatients . '</div><div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Captacoes</div></div>';
    echo '<div class="card" style="text-align:center;padding:14px"><div style="font-size:24px;font-weight:800;color:#10b981">' . $totalActive . '</div><div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Ativos</div></div>';
    echo '<div class="card" style="text-align:center;padding:14px"><div style="font-size:24px;font-weight:800;color:#0284c7">' . $totalSessions . '</div><div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Sessoes</div></div>';
    echo '<div class="card" style="text-align:center;padding:14px"><div style="font-size:24px;font-weight:800;color:hsl(var(--destructive))">' . $totalPending . '</div><div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Pendentes</div></div>';
    echo '</div>';

    // Captacoes/Atendimentos
    echo '<section class="card col12">';
    echo '<div style="font-size:16px;font-weight:700;margin-bottom:14px">Captacoes / Atendimentos</div>';
    if (empty($patients)) {
        echo '<div style="padding:30px;text-align:center;color:hsl(var(--muted-foreground))">Nenhuma captacao encontrada para este profissional.</div>';
    } else {
        echo '<div style="overflow:auto"><table><thead><tr><th>Paciente</th><th>CPF</th><th>Especialidade</th><th>Sessoes</th><th>Status</th><th>Docs Pendentes</th><th style="text-align:right">Acoes</th></tr></thead><tbody>';
        foreach ($patients as $pat) {
            $stColors = ['admitted' => '#f59e0b', 'awaiting_financial_approval' => '#0284c7', 'approved' => '#10b981', 'completed' => '#667781'];
            $stLabels = ['admitted' => 'Admitido', 'awaiting_financial_approval' => 'Aguardando Aprovacao', 'approved' => 'Aprovado', 'completed' => 'Concluido'];
            $stColor = $stColors[$pat['status']] ?? '#667781';
            $stLabel = $stLabels[$pat['status']] ?? $pat['status'];
            $pend = (int)$pat['pending_docs'];
            echo '<tr>';
            echo '<td style="font-weight:600">' . htmlspecialchars($pat['full_name']) . '</td>';
            echo '<td style="font-size:12px">' . htmlspecialchars($pat['cpf'] ?? '-') . '</td>';
            echo '<td style="font-size:12px">' . htmlspecialchars($pat['specialty'] ?? '-') . '</td>';
            echo '<td>' . (int)$pat['session_quantity'] . '</td>';
            echo '<td><span style="color:' . $stColor . ';font-weight:600;font-size:12px">' . $stLabel . '</span></td>';
            echo '<td>' . ($pend > 0 ? '<span style="color:#ef4444;font-weight:700">' . $pend . '</span>' : '<span style="color:#10b981">0</span>') . '</td>';
            echo '<td style="text-align:right"><a class="btn" href="/patients_view.php?id=' . (int)$pat['id'] . '" style="font-size:12px;padding:4px 10px">Ver Paciente</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';

    // Sessoes e Documentos
    echo '<section class="card col12">';
    echo '<div style="font-size:16px;font-weight:700;margin-bottom:14px">Sessoes e Documentos</div>';

    if (empty($allSessions)) {
        echo '<div style="padding:30px;text-align:center;color:hsl(var(--muted-foreground))">Nenhuma sessao registrada.</div>';
    } else {
        // Agrupar por assignment
        $byAssignment = [];
        foreach ($allSessions as $sess) {
            $byAssignment[$sess['assignment_id']][] = $sess;
        }

        foreach ($byAssignment as $aId => $sessions) {
            $first = $sessions[0];
            $totalSess = (int)($first['session_quantity'] ?? count($sessions));
            echo '<div style="margin-bottom:20px">';
            echo '<div style="padding:10px 14px;background:hsla(var(--primary)/.06);border-radius:8px 8px 0 0;border:1px solid hsl(var(--border));border-bottom:none;display:flex;justify-content:space-between;align-items:center">';
            echo '<div><strong>' . htmlspecialchars($first['patient_name']) . '</strong> <span style="color:hsl(var(--muted-foreground));font-size:12px">— ' . htmlspecialchars($first['specialty'] ?? '') . ' • ' . $totalSess . ' sessoes</span></div>';
            echo '</div>';
            echo '<div style="overflow:auto;border:1px solid hsl(var(--border));border-radius:0 0 8px 8px"><table style="margin:0"><thead><tr><th>Sessao</th><th>Data</th><th>Status</th><th>Documento</th><th style="text-align:right">Acoes</th></tr></thead><tbody>';

            foreach ($sessions as $sess) {
                $docSt = $sess['doc_status'] ?? 'pending';
                $stLabel = 'Pendente'; $stColor = '#f59e0b';
                if ($docSt === 'uploaded') { $stLabel = 'Enviado'; $stColor = '#0284c7'; }
                elseif ($docSt === 'approved') { $stLabel = 'Aprovado'; $stColor = '#10b981'; }
                elseif ($docSt === 'paid') { $stLabel = 'Pago'; $stColor = '#059669'; }
                elseif ($docSt === 'rejected') { $stLabel = 'Rejeitado'; $stColor = '#ef4444'; }

                echo '<tr>';
                echo '<td style="font-weight:600">Sessao ' . (int)$sess['session_number'] . '/' . $totalSess . '</td>';
                echo '<td>' . ($sess['session_date'] ? date('d/m/Y', strtotime($sess['session_date'])) : '-') . '</td>';
                echo '<td><span style="color:' . $stColor . ';font-weight:600;font-size:12px">' . $stLabel . '</span></td>';
                echo '<td style="font-size:12px">';
                if (!empty($sess['file_path'])) {
                    echo '<a href="' . htmlspecialchars($sess['file_path']) . '" target="_blank" style="color:hsl(var(--primary))">Ver documento</a>';
                } else {
                    echo '<span style="color:hsl(var(--muted-foreground))">-</span>';
                }
                echo '</td>';
                echo '<td style="text-align:right;white-space:nowrap">';
                // Enviar Ficha de Evolucao (admin pode enviar no lugar do profissional)
                if (in_array($docSt, ['pending', 'rejected'], true)) {
                    echo '<a class="btn btnPrimary" href="/faturamento_upload_doc.php?requirement_id=' . (int)$sess['id'] . '&admin=1" style="font-size:11px;padding:4px 10px">Enviar Ficha</a> ';
                }
                // Concluir pendencia (aprovar)
                if ($docSt === 'uploaded') {
                    echo '<a class="btn" href="/faturamento_approve_doc.php?requirement_id=' . (int)$sess['id'] . '" style="font-size:11px;padding:4px 10px;background:#10b981;color:white;border-color:#10b981">Aprovar</a> ';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo '</div>';
        }
    }
    echo '</section>';

    // === SEÇÃO: Documentos das Sessões (Envio de Fichas) ===
    echo '<section class="card col12">';
    echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">';
    echo '<div style="font-size:16px;font-weight:700">Documentos das Sessoes</div>';
    echo '</div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:16px">Selecione o atendimento e a sessao para vincular os documentos corretamente.</div>';

    echo '<form method="post" action="/admin_professional_sessions.php?prof_id=' . $profId . '" enctype="multipart/form-data">';
    echo '<input type="hidden" name="action" value="send_session_docs">';
    echo '<input type="hidden" name="prof_id" value="' . $profId . '">';

    // Selecao de Atendimento
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px">';
    echo '<div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Atendimento *</label>';
    echo '<select name="assignment_id" id="selAssignment" onchange="loadSessions()" required>';
    echo '<option value="">Selecione o atendimento...</option>';
    foreach ($patients as $pat) {
        $patLabel = htmlspecialchars($pat['full_name']) . ' - ' . htmlspecialchars($pat['specialty'] ?? '') . ' (' . (int)$pat['session_quantity'] . ' sessoes)';
        echo '<option value="' . (int)$pat['assignment_id'] . '">' . $patLabel . '</option>';
    }
    echo '</select></div>';

    // Selecao de Sessao
    echo '<div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Sessao *</label>';
    echo '<select name="session_id" id="selSession" required>';
    echo '<option value="">Selecione o atendimento primeiro</option>';
    echo '</select></div>';
    echo '</div>';

    // Uploads
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">';
    echo '<div style="padding:16px;border:1px solid hsl(var(--border));border-radius:10px">';
    echo '<div style="font-size:14px;font-weight:700;margin-bottom:8px">📋 Ficha de Evolucao</div>';
    echo '<input type="file" name="ficha_evolucao" style="width:100%;font-size:13px">';
    echo '</div>';
    echo '<div style="padding:16px;border:1px solid hsl(var(--border));border-radius:10px">';
    echo '<div style="font-size:14px;font-weight:700;margin-bottom:8px">📊 Ficha de Produtividade</div>';
    echo '<input type="file" name="ficha_produtividade" style="width:100%;font-size:13px">';
    echo '</div>';
    echo '</div>';

    echo '<div style="margin-bottom:12px"><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Observacao (opcional)</label><input name="doc_notes" placeholder="Complemento, detalhes..." style="width:100%"></div>';

    echo '<div style="display:flex;align-items:center;gap:12px">';
    echo '<button type="submit" class="btn btnPrimary">Enviar Documentos</button>';
    echo '<span style="font-size:12px;color:hsl(var(--muted-foreground))">Envio automatico por E-mail, Portal e WhatsApp</span>';
    echo '</div>';
    echo '</form>';

    // JavaScript para carregar sessoes do atendimento selecionado
    $sessionsJs = [];
    foreach ($allSessions as $s) {
        $sessionsJs[] = ['id' => (int)$s['id'], 'aid' => (int)$s['assignment_id'], 'num' => (int)$s['session_number'], 'date' => (string)($s['session_date'] ?? ''), 'st' => (string)($s['doc_status'] ?? 'pending')];
    }
    echo '<script>';
    echo 'var SD=' . json_encode($sessionsJs, JSON_UNESCAPED_UNICODE) . ';';
    echo 'function loadSessions(){var a=document.getElementById("selAssignment").value,s=document.getElementById("selSession");s.innerHTML="<option>Selecione...</option>";if(!a)return;for(var i=0;i<SD.length;i++)if(SD[i].aid==a){var o=document.createElement("option");o.value=SD[i].id;o.textContent="Sessao "+SD[i].num+(SD[i].date?" - "+SD[i].date:"")+" ("+SD[i].st+")";s.appendChild(o)}}';
    echo '</script>';
    echo '</section>';

    // Documentos da Operadora enviados ao profissional
    echo '<section class="card col12">';
    echo '<div style="font-size:16px;font-weight:700;margin-bottom:14px">Documentos Enviados ao Profissional</div>';

    $sentDocs = [];
    try {
        $profEmail = $profData['email'] ?? '';
        if ($profEmail !== '') {
            $sdStmt = $db->prepare("SELECT * FROM document_send_logs WHERE (recipient_id = ? OR recipient_email = ?) AND file_path IS NOT NULL AND file_path != '' ORDER BY created_at DESC");
            $sdStmt->execute([$profId, $profEmail]);
        } else {
            $sdStmt = $db->prepare("SELECT * FROM document_send_logs WHERE recipient_id = ? AND file_path IS NOT NULL AND file_path != '' ORDER BY created_at DESC");
            $sdStmt->execute([$profId]);
        }
        $sentDocs = $sdStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    if (empty($sentDocs)) {
        echo '<div style="padding:20px;text-align:center;color:hsl(var(--muted-foreground));font-size:14px">Nenhum documento enviado a este profissional.</div>';
    } else {
        echo '<div style="overflow:auto"><table><thead><tr><th>Data</th><th>Documento</th><th>Origem</th><th>Metodo</th><th style="text-align:right">Acoes</th></tr></thead><tbody>';
        foreach ($sentDocs as $sd) {
            $src = ($sd['document_source'] ?? '') === 'manual' ? 'Manual' : 'Auto';
            echo '<tr>';
            echo '<td style="font-size:12px;white-space:nowrap">' . date('d/m/Y H:i', strtotime($sd['created_at'])) . '</td>';
            echo '<td><a href="' . htmlspecialchars($sd['file_path']) . '" target="_blank" style="color:hsl(var(--primary));font-weight:600">' . htmlspecialchars($sd['file_name'] ?? 'Doc') . '</a></td>';
            echo '<td style="font-size:11px">' . $src . '</td>';
            echo '<td style="font-size:11px">' . (($sd['send_method'] ?? '') === 'email' ? 'E-mail' : 'Portal') . '</td>';
            echo '<td style="text-align:right"><a href="' . htmlspecialchars($sd['file_path']) . '" target="_blank" class="btn" style="font-size:11px;padding:4px 10px">Ver</a> <a href="' . htmlspecialchars($sd['file_path']) . '" download class="btn" style="font-size:11px;padding:4px 10px">Baixar</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}

echo '</div>';
view_footer();
