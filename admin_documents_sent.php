<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();

$db = db();

// Garantir estrutura da tabela
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
        patient_id INT UNSIGNED NULL,
        session_id INT UNSIGNED NULL,
        captation_id INT UNSIGNED NULL,
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

// Adicionar colunas novas (compatível com MySQL 5.7+)
$existingCols = [];
try {
    $colsResult = $db->query("SHOW COLUMNS FROM document_send_logs");
    while ($col = $colsResult->fetch(PDO::FETCH_ASSOC)) {
        $existingCols[] = $col['Field'];
    }
} catch (Throwable $e) {}

if (!empty($existingCols)) {
    $colsToAdd = [
        'send_status' => "VARCHAR(30) NOT NULL DEFAULT 'enviado'",
        'send_action' => "VARCHAR(30) NOT NULL DEFAULT 'envio_inicial'",
        'resent_from_log_id' => "INT UNSIGNED NULL",
        'recipient_name' => "VARCHAR(255) NULL",
        'patient_id' => "INT UNSIGNED NULL",
        'session_id' => "INT UNSIGNED NULL",
        'captation_id' => "INT UNSIGNED NULL",
    ];
    foreach ($colsToAdd as $colName => $colDef) {
        if (!in_array($colName, $existingCols, true)) {
            try { $db->exec("ALTER TABLE document_send_logs ADD COLUMN $colName $colDef"); } catch (Throwable $e) {}
        }
    }
}

// Processar envio manual (POST)
$flashSuccess = '';
$flashError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_manual') {
    $recipientType = trim((string)($_POST['recipient_type'] ?? ''));
    $recipientId = (int)($_POST['recipient_id'] ?? 0);
    $healthInsurerId = (int)($_POST['health_insurer_id'] ?? 0);
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
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
                        if ($notes !== '') $body .= '<p style="margin:8px 0 0;font-size:13px;color:#6b7280">' . nl2br(htmlspecialchars($notes)) . '</p>';
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
                        $flashError = 'Arquivo salvo, mas falha ao enviar e-mail: ' . $e->getMessage();
                    }
                } elseif ($sendMethod === 'portal') {
                    $sendStatus = 'enviado';
                }

                try {
                    $db->prepare("INSERT INTO document_send_logs (document_source, recipient_type, recipient_id, recipient_email, recipient_name, health_insurer_id, assignment_id, send_method, sent_by_user_id, file_name, file_path, notes, send_status, send_action) VALUES ('manual',?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$recipientType, $recipientId, $recipientEmail, $recipientName, $healthInsurerId > 0 ? $healthInsurerId : null, $assignmentId > 0 ? $assignmentId : null, $sendMethod, auth_user_id(), $fileName, $relativePath, $notes, $sendStatus, 'envio_inicial']);
                } catch (Throwable $e) {}

                if ($flashError === '') {
                    $flashSuccess = 'Documento enviado com sucesso!';
                }
            } else {
                $flashError = 'Falha ao salvar arquivo.';
            }
        }
    }
}

// Filtros
$q = trim((string)($_GET['q'] ?? ''));
$filterSource = trim((string)($_GET['source'] ?? ''));
$filterType = trim((string)($_GET['type'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? ''));

// Buscar logs com filtros
$logs = [];
try {
    $where = [];
    $params = [];

    // Verificar se a coluna recipient_name existe
    $hasRecipientName = false;
    try {
        $db->query("SELECT recipient_name FROM document_send_logs LIMIT 0");
        $hasRecipientName = true;
    } catch (Throwable $e) {}

    if ($q !== '') {
        if ($hasRecipientName) {
            $where[] = "(dsl.file_name LIKE ? OR dsl.recipient_email LIKE ? OR dsl.recipient_name LIKE ? OR dsl.notes LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
        } else {
            $where[] = "(dsl.file_name LIKE ? OR dsl.recipient_email LIKE ? OR dsl.notes LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
    }
    if ($filterSource !== '') {
        $where[] = "dsl.document_source = ?";
        $params[] = $filterSource;
    }
    if ($filterType !== '') {
        $where[] = "dsl.recipient_type = ?";
        $params[] = $filterType;
    }
    if ($filterStatus !== '') {
        // Verificar se coluna send_status existe
        try {
            $db->query("SELECT send_status FROM document_send_logs LIMIT 0");
            $where[] = "dsl.send_status = ?";
            $params[] = $filterStatus;
        } catch (Throwable $e) {}
    }

    $sql = "SELECT dsl.*, u.name as sent_by_name FROM document_send_logs dsl LEFT JOIN users u ON u.id = dsl.sent_by_user_id";
    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " ORDER BY dsl.created_at DESC LIMIT 200";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Buscar dados auxiliares
$insurers = [];
try { $insurers = $db->query("SELECT id, name FROM health_insurers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

$profs = [];
try { $profs = $db->query("SELECT u.id, u.name FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.status = 'active' AND r.slug = 'profissional' ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

$pats = [];
try { $pats = $db->query("SELECT id, full_name FROM patients ORDER BY full_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

// Buscar atendimentos ativos para o filtro de documentos variáveis
$assignments = [];
try { $assignments = $db->query("SELECT pa.id, CONCAT(p.full_name, ' - ', COALESCE(pa.specialty, 'Geral')) as label FROM patient_assignments pa INNER JOIN patients p ON p.id = pa.patient_id WHERE pa.status IN ('active','em_andamento','ativo') ORDER BY p.full_name LIMIT 300")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

// Estatísticas rápidas
$totalEnvios = count($logs);
$totalAuto = 0;
$totalManual = 0;
$totalFalhas = 0;
$totalReenvios = 0;
foreach ($logs as $l) {
    if (($l['document_source'] ?? '') !== 'manual') $totalAuto++;
    else $totalManual++;
    if (($l['send_status'] ?? '') === 'falha') $totalFalhas++;
    if (($l['send_action'] ?? '') === 'reenvio') $totalReenvios++;
}

view_header('Documentos Enviados');

if ($flashSuccess) echo '<div class="alert alertSuccess">' . htmlspecialchars($flashSuccess) . '</div>';
if ($flashError) echo '<div class="alert alertError">' . htmlspecialchars($flashError) . '</div>';

echo '<div class="grid">';

// Header
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Gerenciamento de Documentos Enviados</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Visualize, envie e reenvie documentos para profissionais e pacientes</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px">';
echo '<button onclick="document.getElementById(\'sendModal\').style.display=\'flex\'" class="btn btnPrimary" style="gap:6px"><svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:4px"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>Enviar Documento</button>';
echo '<a class="btn" href="/documents_list.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Cards de estatísticas
echo '<div class="col12" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">';

echo '<div class="card" style="text-align:center;padding:14px">';
echo '<div style="font-size:24px;font-weight:800;color:hsl(var(--primary))">' . $totalEnvios . '</div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Total de Envios</div>';
echo '</div>';

echo '<div class="card" style="text-align:center;padding:14px">';
echo '<div style="font-size:24px;font-weight:800;color:#0284c7">' . $totalAuto . '</div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Automáticos</div>';
echo '</div>';

echo '<div class="card" style="text-align:center;padding:14px">';
echo '<div style="font-size:24px;font-weight:800;color:#7c3aed">' . $totalManual . '</div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Manuais</div>';
echo '</div>';

echo '<div class="card" style="text-align:center;padding:14px">';
echo '<div style="font-size:24px;font-weight:800;color:#f59e0b">' . $totalReenvios . '</div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Reenvios</div>';
echo '</div>';

echo '<div class="card" style="text-align:center;padding:14px">';
echo '<div style="font-size:24px;font-weight:800;color:hsl(var(--destructive))">' . $totalFalhas . '</div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Falhas</div>';
echo '</div>';

echo '</div>';

// Filtros avançados
echo '<section class="card col12">';
echo '<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">';
echo '<div style="flex:1;min-width:180px"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Buscar</label><input name="q" value="' . htmlspecialchars($q) . '" placeholder="Nome, e-mail ou documento..." style="width:100%"></div>';
echo '<div style="min-width:120px"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Origem</label><select name="source" style="width:100%"><option value="">Todas</option><option value="manual"' . ($filterSource === 'manual' ? ' selected' : '') . '>Manual</option><option value="insurer"' . ($filterSource === 'insurer' ? ' selected' : '') . '>Automático</option></select></div>';
echo '<div style="min-width:120px"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Tipo</label><select name="type" style="width:100%"><option value="">Todos</option><option value="professional"' . ($filterType === 'professional' ? ' selected' : '') . '>Profissional</option><option value="patient"' . ($filterType === 'patient' ? ' selected' : '') . '>Paciente</option></select></div>';
echo '<div style="min-width:120px"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Status</label><select name="status" style="width:100%"><option value="">Todos</option><option value="enviado"' . ($filterStatus === 'enviado' ? ' selected' : '') . '>Enviado</option><option value="entregue"' . ($filterStatus === 'entregue' ? ' selected' : '') . '>Entregue</option><option value="falha"' . ($filterStatus === 'falha' ? ' selected' : '') . '>Falha</option></select></div>';
echo '<div style="display:flex;gap:8px"><button class="btn btnPrimary" type="submit">Filtrar</button>';
if ($q !== '' || $filterSource !== '' || $filterType !== '' || $filterStatus !== '') {
    echo '<a class="btn" href="/admin_documents_sent.php">Limpar</a>';
}
echo '</div>';
echo '</form>';
echo '</section>';

// Tabela principal - Histórico de Envios
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">';
echo '<div style="font-size:16px;font-weight:700">Histórico de Envios</div>';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">' . $totalEnvios . ' registro(s)</div>';
echo '</div>';

if (empty($logs)) {
    echo '<div style="padding:50px 20px;text-align:center;color:hsl(var(--muted-foreground))">';
    echo '<svg width="48" height="48" fill="currentColor" viewBox="0 0 24 24" style="opacity:.4;margin-bottom:12px"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zM6 20V4h7v5h5v11H6z"/></svg>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:6px">Nenhum documento enviado</div>';
    echo '<div style="font-size:14px">Use o botão "Enviar Documento" para começar.</div>';
    echo '</div>';
} else {
    echo '<div style="overflow-x:auto">';
    echo '<table style="width:100%;border-collapse:collapse">';
    echo '<thead><tr style="border-bottom:2px solid hsl(var(--border))">';
    echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700;color:hsl(var(--muted-foreground))">Data/Hora</th>';
    echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700;color:hsl(var(--muted-foreground))">Documento</th>';
    echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700;color:hsl(var(--muted-foreground))">Destinatário</th>';
    echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700;color:hsl(var(--muted-foreground))">Tipo</th>';
    echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700;color:hsl(var(--muted-foreground))">Método</th>';
    echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700;color:hsl(var(--muted-foreground))">Origem</th>';
    echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700;color:hsl(var(--muted-foreground))">Ação</th>';
    echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700;color:hsl(var(--muted-foreground))">Status</th>';
    echo '<th style="padding:10px 8px;text-align:left;font-size:12px;font-weight:700;color:hsl(var(--muted-foreground))">Responsável</th>';
    echo '<th style="padding:10px 8px;text-align:right;font-size:12px;font-weight:700;color:hsl(var(--muted-foreground))">Ações</th>';
    echo '</tr></thead><tbody>';

    foreach ($logs as $l) {
        $src = ($l['document_source'] ?? '') === 'manual' ? 'Manual' : 'Automático';
        $srcColor = ($l['document_source'] ?? '') === 'manual' ? '#7c3aed' : '#0284c7';
        $mth = ($l['send_method'] ?? '') === 'email' ? 'E-mail' : ucfirst($l['send_method'] ?? 'portal');
        $tp = ($l['recipient_type'] ?? '') === 'professional' ? 'Profissional' : 'Paciente';

        // Status badge
        $status = $l['send_status'] ?? 'enviado';
        if ($status === '' || $status === null) $status = 'enviado';
        $statusLabel = match($status) {
            'entregue' => 'Entregue',
            'falha' => 'Falha',
            'pendente' => 'Pendente',
            default => 'Enviado',
        };
        $statusColor = match($status) {
            'entregue' => '#10b981',
            'falha' => '#ef4444',
            'pendente' => '#f59e0b',
            default => '#0284c7',
        };

        // Ação
        $action = $l['send_action'] ?? 'envio_inicial';
        $actionLabel = $action === 'reenvio' ? 'Reenvio' : 'Envio inicial';
        $actionIcon = $action === 'reenvio' ? '🔄' : '📤';

        // Nome do destinatário
        $destName = $l['recipient_name'] ?? '';
        $destEmail = $l['recipient_email'] ?? '';
        $destDisplay = $destName !== '' ? $destName : ($destEmail !== '' ? $destEmail : '-');

        echo '<tr style="border-bottom:1px solid hsl(var(--border))">';

        // Data
        echo '<td style="padding:10px 8px;font-size:12px;white-space:nowrap">' . date('d/m/Y H:i', strtotime($l['created_at'])) . '</td>';

        // Documento (com link para visualizar/baixar)
        echo '<td style="padding:10px 8px;font-size:13px">';
        $fileName = $l['file_name'] ?? '';
        $filePath = $l['file_path'] ?? '';
        if ($filePath !== '') {
            $icon = preg_match('/\.pdf$/i', $fileName) ? '📄' : (preg_match('/\.(jpg|jpeg|png|webp)$/i', $fileName) ? '🖼️' : '📎');
            echo '<a href="' . htmlspecialchars($filePath) . '" target="_blank" style="color:hsl(var(--primary));font-weight:600;text-decoration:none">' . $icon . ' ' . htmlspecialchars($fileName ?: 'Documento') . '</a>';
        } else {
            echo htmlspecialchars($fileName ?: '-');
        }
        echo '</td>';

        // Destinatário
        echo '<td style="padding:10px 8px;font-size:12px">';
        echo '<div style="font-weight:600">' . htmlspecialchars($destDisplay) . '</div>';
        if ($destName !== '' && $destEmail !== '') {
            echo '<div style="font-size:11px;color:hsl(var(--muted-foreground))">' . htmlspecialchars($destEmail) . '</div>';
        }
        echo '</td>';

        // Tipo
        echo '<td style="padding:10px 8px"><span style="font-size:11px;padding:3px 8px;border-radius:6px;background:' . ($tp === 'Profissional' ? '#ede9fe' : '#e0f2fe') . ';color:' . ($tp === 'Profissional' ? '#6d28d9' : '#0369a1') . ';font-weight:600">' . $tp . '</span></td>';

        // Método
        echo '<td style="padding:10px 8px;font-size:12px">' . $mth . '</td>';

        // Origem
        echo '<td style="padding:10px 8px"><span style="font-size:11px;padding:3px 8px;border-radius:6px;background:' . ($src === 'Manual' ? '#f3e8ff' : '#e0f7fa') . ';color:' . $srcColor . ';font-weight:600">' . $src . '</span></td>';

        // Ação
        echo '<td style="padding:10px 8px;font-size:12px">' . $actionIcon . ' ' . $actionLabel . '</td>';

        // Status
        echo '<td style="padding:10px 8px"><span style="font-size:11px;padding:3px 8px;border-radius:6px;background:' . $statusColor . '20;color:' . $statusColor . ';font-weight:700">' . $statusLabel . '</span></td>';

        // Responsável
        echo '<td style="padding:10px 8px;font-size:12px">' . htmlspecialchars($l['sent_by_name'] ?? '-') . '</td>';

        // Ações
        echo '<td style="padding:10px 8px;text-align:right;white-space:nowrap">';
        if ($filePath !== '') {
            echo '<a href="' . htmlspecialchars($filePath) . '" target="_blank" title="Visualizar" style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid hsl(var(--border));margin-right:4px;text-decoration:none"><svg width="14" height="14" fill="hsl(var(--foreground))" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg></a>';
            echo '<a href="' . htmlspecialchars($filePath) . '" download title="Baixar" style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid hsl(var(--border));margin-right:4px;text-decoration:none"><svg width="14" height="14" fill="hsl(var(--foreground))" viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg></a>';
        }
        // Botão Reenviar
        echo '<button onclick="reenviar(' . (int)$l['id'] . ',\'' . addslashes($fileName) . '\',\'' . addslashes($filePath) . '\',\'' . addslashes($destDisplay) . '\')" title="Reenviar" style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid hsl(var(--border));background:white;cursor:pointer"><svg width="14" height="14" fill="hsl(var(--foreground))" viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg></button>';
        echo '</td>';

        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';
echo '</div>';

// Modal de Envio Manual
echo '<div id="sendModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display=\'none\'">';
echo '<div style="background:hsl(var(--card));border-radius:16px;padding:28px;max-width:580px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-elevated)">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">';
echo '<h2 style="margin:0;font-size:18px;font-weight:800">Enviar Documento</h2>';
echo '<button onclick="document.getElementById(\'sendModal\').style.display=\'none\'" style="background:none;border:none;cursor:pointer;font-size:20px;color:hsl(var(--muted-foreground))">✕</button>';
echo '</div>';
echo '<form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="send_manual">';

echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">';
echo '<div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Tipo de Destinatário *</label>';
echo '<select name="recipient_type" id="rt" onchange="ur()" required style="width:100%"><option value="">Selecione...</option><option value="professional">Profissional</option><option value="patient">Paciente</option></select></div>';
echo '<div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Destinatário *</label>';
echo '<select name="recipient_id" id="rs" required style="width:100%"><option value="">Selecione o tipo primeiro</option></select></div>';
echo '</div>';

echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">';
echo '<div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Operadora</label>';
echo '<select name="health_insurer_id" style="width:100%"><option value="0">Nenhuma</option>';
foreach ($insurers as $i) echo '<option value="' . (int)$i['id'] . '">' . htmlspecialchars($i['name']) . '</option>';
echo '</select></div>';
echo '<div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Atendimento</label>';
echo '<select name="assignment_id" style="width:100%"><option value="0">Nenhum (avulso)</option>';
foreach ($assignments as $a) echo '<option value="' . (int)$a['id'] . '">' . htmlspecialchars($a['label']) . '</option>';
echo '</select></div>';
echo '</div>';

echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">';
echo '<div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Método de Envio *</label>';
echo '<select name="send_method" required style="width:100%"><option value="email">E-mail</option><option value="portal">Disponibilizar no Portal</option></select></div>';
echo '<div><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Arquivo *</label>';
echo '<input type="file" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp" style="width:100%;font-size:13px"></div>';
echo '</div>';

echo '<div style="margin-bottom:18px"><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Observação</label>';
echo '<input name="notes" placeholder="Motivo do envio, contexto adicional..." style="width:100%"></div>';

echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
echo '<button type="button" onclick="document.getElementById(\'sendModal\').style.display=\'none\'" class="btn">Cancelar</button>';
echo '<button type="submit" class="btn btnPrimary">Enviar</button>';
echo '</div>';
echo '</form></div></div>';

// Modal de Reenvio
echo '<div id="resendModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display=\'none\'">';
echo '<div style="background:hsl(var(--card));border-radius:16px;padding:28px;max-width:460px;width:100%;box-shadow:var(--shadow-elevated)">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">';
echo '<h2 style="margin:0;font-size:18px;font-weight:800">Reenviar Documento</h2>';
echo '<button onclick="document.getElementById(\'resendModal\').style.display=\'none\'" style="background:none;border:none;cursor:pointer;font-size:20px;color:hsl(var(--muted-foreground))">✕</button>';
echo '</div>';
echo '<form method="post" action="/admin_documents_resend_post.php">';
echo '<input type="hidden" name="log_id" id="resend_log_id" value="">';
echo '<div style="background:hsl(var(--accent));padding:14px;border-radius:10px;margin-bottom:16px">';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:4px">Documento</div>';
echo '<div style="font-size:14px;font-weight:600" id="resend_file_name">-</div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:6px">Destinatário</div>';
echo '<div style="font-size:14px;font-weight:600" id="resend_dest_name">-</div>';
echo '</div>';
echo '<div style="margin-bottom:16px"><label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Observação do reenvio</label>';
echo '<input name="resend_notes" placeholder="Motivo do reenvio..." style="width:100%"></div>';
echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
echo '<button type="button" onclick="document.getElementById(\'resendModal\').style.display=\'none\'" class="btn">Cancelar</button>';
echo '<button type="submit" class="btn btnPrimary">Reenviar</button>';
echo '</div>';
echo '</form></div></div>';

// JavaScript
echo '<script>';
echo 'var P=[';
foreach ($profs as $i => $p) { if ($i) echo ','; echo '[' . (int)$p['id'] . ',"' . addslashes($p['name']) . '"]'; }
echo '];var A=[';
foreach ($pats as $i => $p) { if ($i) echo ','; echo '[' . (int)$p['id'] . ',"' . addslashes($p['full_name']) . '"]'; }
echo '];';
echo 'function ur(){var t=document.getElementById("rt").value,s=document.getElementById("rs"),l=t==="professional"?P:t==="patient"?A:[];s.innerHTML="<option value=\\'\\'>Selecione...</option>";for(var i=0;i<l.length;i++){var o=document.createElement("option");o.value=l[i][0];o.textContent=l[i][1];s.appendChild(o);}}';
echo 'function reenviar(id,fileName,filePath,destName){document.getElementById("resend_log_id").value=id;document.getElementById("resend_file_name").textContent=fileName||"Documento";document.getElementById("resend_dest_name").textContent=destName||"-";document.getElementById("resendModal").style.display="flex";}';
echo '</script>';

view_footer();
