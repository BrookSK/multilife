<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();
rbac_require_permission('demands.manage');

$db = db();

// Profissional selecionado
$profId = isset($_GET['prof_id']) ? (int)$_GET['prof_id'] : 0;
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

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
