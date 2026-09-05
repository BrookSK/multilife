<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$userId = auth_user_id();

// Buscar pacientes atribuídos ao profissional
$patientsStmt = db()->prepare("
    SELECT 
        p.id,
        p.full_name,
        p.cpf,
        p.phone_primary,
        pa.specialty,
        pa.service_type,
        pa.session_quantity,
        pa.status,
        pa.created_at,
        (SELECT COUNT(*) FROM billing_document_requirements bdr 
         WHERE bdr.assignment_id = pa.id AND bdr.professional_user_id = ? AND bdr.status = 'pending') as pending_docs
    FROM patient_assignments pa
    INNER JOIN patients p ON p.id = pa.patient_id
    WHERE pa.professional_user_id = ?
    AND pa.status IN ('admitted', 'awaiting_financial_approval', 'approved', 'completed')
    ORDER BY pa.created_at DESC
");
$patientsStmt->execute([$userId, $userId]);
$patients = $patientsStmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar documentos pendentes (sem duplicatas por sessão)
$pendingDocsStmt = db()->prepare("
    SELECT 
        bdr.id,
        bdr.session_number,
        bdr.session_date,
        bdr.status,
        pa.id as assignment_id,
        pa.demand_id,
        p.full_name as patient_name,
        pa.specialty,
        pa.service_type,
        pa.session_quantity
    FROM billing_document_requirements bdr
    INNER JOIN patient_assignments pa ON pa.id = bdr.assignment_id
    INNER JOIN patients p ON p.id = pa.patient_id
    WHERE bdr.professional_user_id = ?
    AND bdr.status IN ('pending', 'rejected')
    AND bdr.id = (
        SELECT MIN(b2.id) FROM billing_document_requirements b2 
        WHERE b2.assignment_id = bdr.assignment_id 
        AND b2.session_number = bdr.session_number
        AND b2.status IN ('pending', 'rejected')
    )
    ORDER BY pa.id ASC, bdr.session_number ASC
    LIMIT 30
");
$pendingDocsStmt->execute([$userId]);
$pendingDocs = $pendingDocsStmt->fetchAll(PDO::FETCH_ASSOC);

view_header('Meus Registros');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Meus Registros</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Pacientes atribuídos e documentos pendentes</div>';
echo '</div>';
echo '<a href="/atendimentos_finalizados.php" class="btn">📁 Meus atendimentos finalizados</a>';
echo '</div>';
echo '</section>';

// Alertas de Documentos Pendentes
if (count($pendingDocs) > 0) {
    echo '<section class="card col12" style="background:#fef3c7;border-left:4px solid #f59e0b">';
    echo '<div style="display:flex;align-items:center;gap:12px">';
    echo '<svg style="width:24px;height:24px;color:#f59e0b" fill="currentColor" viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>';
    echo '<div>';
    echo '<div style="font-weight:700;color:#92400e">Você tem ' . count($pendingDocs) . ' documento(s) pendente(s)</div>';
    echo '<div style="font-size:14px;color:#78350f">Envie os documentos de comprovação para liberar o pagamento</div>';
    echo '</div>';
    echo '<a href="/faturamento_profissional.php" class="btn btnPrimary" style="margin-left:auto">Ver Pendências</a>';
    echo '</div>';
    echo '</section>';
}

// Resumo
echo '<section class="card col12">';
echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px">';

$totalPatients = count($patients);
$totalPending = count($pendingDocs);
$totalActive = count(array_filter($patients, fn($p) => in_array($p['status'], ['admitted', 'awaiting_financial_approval', 'approved'])));

echo '<div style="padding:20px;background:#f0f9ff;border-radius:8px;border-left:4px solid #0284c7">';
echo '<div style="font-size:14px;color:#0369a1;font-weight:600;margin-bottom:8px">Total de Pacientes</div>';
echo '<div style="font-size:32px;font-weight:700;color:#0c4a6e">' . $totalPatients . '</div>';
echo '</div>';

echo '<div style="padding:20px;background:#f0fdf4;border-radius:8px;border-left:4px solid #10b981">';
echo '<div style="font-size:14px;color:#059669;font-weight:600;margin-bottom:8px">Atendimentos Ativos</div>';
echo '<div style="font-size:32px;font-weight:700;color:#065f46">' . $totalActive . '</div>';
echo '</div>';

echo '<div style="padding:20px;background:#fef3c7;border-radius:8px;border-left:4px solid #f59e0b">';
echo '<div style="font-size:14px;color:#d97706;font-weight:600;margin-bottom:8px">Documentos Pendentes</div>';
echo '<div style="font-size:32px;font-weight:700;color:#92400e">' . $totalPending . '</div>';
echo '</div>';

echo '</div>';
echo '</section>';

// Lista de Pacientes
echo '<section class="card col12">';
echo '<h3>Meus Pacientes</h3>';

if (count($patients) === 0) {
    echo '<div style="padding:40px;text-align:center;color:#667781">';
    echo '<svg style="width:48px;height:48px;margin:0 auto 16px;opacity:0.3" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum paciente atribuído</div>';
    echo '<div style="font-size:14px">Aguarde a atribuição de pacientes pela equipe</div>';
    echo '</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Paciente</th><th>CPF</th><th>Telefone</th><th>Especialidade</th><th>Sessões</th><th>Status</th><th>Docs Pendentes</th><th style="text-align:right">Ações</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($patients as $patient) {
        $statusColors = [
            'admitted' => '#f59e0b',
            'awaiting_financial_approval' => '#0284c7',
            'approved' => '#10b981',
            'completed' => '#667781'
        ];
        $statusLabels = [
            'admitted' => 'Aguardando Docs',
            'awaiting_financial_approval' => 'Aguardando Aprovação',
            'approved' => 'Aprovado',
            'completed' => 'Concluído'
        ];
        $statusColor = $statusColors[$patient['status']] ?? '#667781';
        $statusLabel = $statusLabels[$patient['status']] ?? $patient['status'];
        
        echo '<tr>';
        echo '<td style="font-weight:600">' . h($patient['full_name']) . '</td>';
        echo '<td>' . h($patient['cpf'] ?? '-') . '</td>';
        echo '<td>' . h($patient['phone_primary'] ?? '-') . '</td>';
        echo '<td>' . h($patient['specialty'] ?? '-') . '</td>';
        echo '<td>' . (int)$patient['session_quantity'] . '</td>';
        echo '<td><span style="color:' . $statusColor . ';font-weight:600">' . $statusLabel . '</span></td>';
        
        $pendingCount = (int)$patient['pending_docs'];
        if ($pendingCount > 0) {
            echo '<td style="color:#ef4444;font-weight:600">' . $pendingCount . '</td>';
        } else {
            echo '<td style="color:#10b981">-</td>';
        }
        
        echo '<td style="text-align:right">';
        echo '<a class="btn" href="/patients_view.php?id=' . (int)$patient['id'] . '">Ver Paciente</a>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';

// Documentos Pendentes
if (count($pendingDocs) > 0) {
    echo '<section class="card col12">';
    echo '<h3>Documentos Pendentes</h3>';
    
    // Agrupar por atendimento (assignment_id)
    $byAssignment = [];
    foreach ($pendingDocs as $doc) {
        $byAssignment[$doc['assignment_id']][] = $doc;
    }
    
    foreach ($byAssignment as $assignId => $docs) {
        $first = $docs[0];
        $totalSess = (int)($first['session_quantity'] ?? count($docs));
        $serviceLabel = $first['service_type'] ?? $first['specialty'] ?? 'Atendimento';
        
        // Header do atendimento
        echo '<div style="margin-top:12px;padding:10px 14px;background:hsla(var(--primary)/.06);border-radius:8px 8px 0 0;border:1px solid hsl(var(--border));border-bottom:none">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center">';
        echo '<div>';
        echo '<strong style="font-size:14px">' . h($first['patient_name']) . '</strong>';
        echo ' <span style="color:hsl(var(--muted-foreground));font-size:12px">— ' . h($serviceLabel) . '</span>';
        echo '</div>';
        echo '<span style="font-size:12px;color:hsl(var(--muted-foreground))">' . h($first['specialty'] ?? '') . ' • ' . $totalSess . ' sessões</span>';
        echo '</div>';
        echo '</div>';
        
        // Tabela de sessões deste atendimento
        echo '<div style="overflow:auto;border:1px solid hsl(var(--border));border-radius:0 0 8px 8px;margin-bottom:16px">';
        echo '<table style="margin:0">';
        echo '<thead><tr><th>Sessão</th><th>Data</th><th>Status</th><th style="text-align:right">Ações</th></tr></thead><tbody>';
        
        foreach ($docs as $doc) {
            $statusColor = $doc['status'] === 'rejected' ? '#dc2626' : '#ef4444';
            $statusText = $doc['status'] === 'rejected' ? 'Rejeitado' : 'Pendente';
            
            echo '<tr>';
            echo '<td style="font-weight:600">Sessão ' . (int)$doc['session_number'] . '/' . $totalSess . '</td>';
            echo '<td>' . ($doc['session_date'] ? date('d/m/Y', strtotime($doc['session_date'])) : '-') . '</td>';
            echo '<td><span style="color:' . $statusColor . ';font-weight:600">' . $statusText . '</span></td>';
            echo '<td style="text-align:right">';
            echo '<a class="btn btnPrimary" href="/faturamento_upload_doc.php?requirement_id=' . (int)$doc['id'] . '" style="font-size:12px;padding:6px 12px">Enviar Documento</a>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
    }
    
    echo '<div style="margin-top:8px;text-align:center">';
    echo '<a href="/faturamento_profissional.php" class="btn">Ver Todas as Pendências</a>';
    echo '</div>';
    
    echo '</section>';
}

echo '</div>';

// Documentos da Operadora (manuais, formulários, termos)
$insurerDocsStmt = db()->prepare("
    SELECT DISTINCT hid.id, hid.file_name, hid.file_path, hid.mime_type,
           hi.name as insurer_name
    FROM health_insurer_documents hid
    INNER JOIN health_insurers hi ON hi.id = hid.health_insurer_id
    INNER JOIN patient_assignments pa ON pa.health_insurer_id = hi.id
    WHERE pa.professional_user_id = ?
    AND pa.status IN ('admitted', 'awaiting_financial_approval', 'approved')
    ORDER BY hi.name ASC, hid.file_name ASC
");
$insurerDocsStmt->execute([$userId]);
$insurerDocsList = $insurerDocsStmt->fetchAll(PDO::FETCH_ASSOC);

// Documentos enviados para este profissional (via admin - manuais e automáticos)
$manualDocsForProf = [];
try {
    // Garantir que a tabela existe
    db()->exec("CREATE TABLE IF NOT EXISTS document_send_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        document_id INT UNSIGNED NOT NULL DEFAULT 0,
        document_source VARCHAR(50) NOT NULL DEFAULT 'manual',
        recipient_type VARCHAR(50) NOT NULL DEFAULT 'professional',
        recipient_id INT UNSIGNED NULL,
        recipient_email VARCHAR(255) NULL,
        send_method VARCHAR(30) NOT NULL DEFAULT 'email',
        sent_by_user_id INT UNSIGNED NULL,
        file_name VARCHAR(255) NULL,
        file_path VARCHAR(500) NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}
try {
    // Buscar e-mail do profissional
    $emailStmt = db()->prepare("SELECT email FROM users WHERE id = ?");
    $emailStmt->execute([$userId]);
    $profEmail = (string)($emailStmt->fetchColumn() ?: '');

    // Buscar docs por recipient_id OU recipient_email
    if ($profEmail !== '') {
        $manualDocsStmt = db()->prepare("
            SELECT id, file_name, file_path, notes, created_at, send_method, document_source
            FROM document_send_logs
            WHERE (recipient_id = ? OR recipient_email = ?)
            AND file_path IS NOT NULL
            AND file_path != ''
            ORDER BY created_at DESC
        ");
        $manualDocsStmt->execute([$userId, $profEmail]);
    } else {
        $manualDocsStmt = db()->prepare("
            SELECT id, file_name, file_path, notes, created_at, send_method, document_source
            FROM document_send_logs
            WHERE recipient_id = ?
            AND file_path IS NOT NULL
            AND file_path != ''
            ORDER BY created_at DESC
        ");
        $manualDocsStmt->execute([$userId]);
    }
    $manualDocsForProf = $manualDocsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if (!empty($insurerDocsList) || !empty($manualDocsForProf)) {
    echo '<section class="card col12">';
    echo '<h3>Documentos da Operadora</h3>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:16px">Manuais, formulários e termos obrigatórios das operadoras dos seus atendimentos ativos.</div>';
    
    // Agrupar por operadora
    $byInsurer = [];
    foreach ($insurerDocsList as $doc) {
        $byInsurer[$doc['insurer_name']][] = $doc;
    }
    
    foreach ($byInsurer as $insurerName => $docs) {
        echo '<div style="margin-bottom:16px">';
        echo '<div style="font-size:13px;font-weight:700;color:hsl(var(--foreground));margin-bottom:8px">' . h($insurerName) . '</div>';
        echo '<div style="display:flex;flex-direction:column;gap:6px">';
        foreach ($docs as $doc) {
            $icon = preg_match('/\.pdf$/i', $doc['file_name']) ? '📄' : (preg_match('/\.(doc|docx)$/i', $doc['file_name']) ? '📝' : (preg_match('/\.(xls|xlsx)$/i', $doc['file_name']) ? '📊' : '📎'));
            echo '<a href="' . h($doc['file_path']) . '" target="_blank" style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:hsl(var(--secondary));border-radius:6px;text-decoration:none;color:hsl(var(--foreground));font-size:13px;font-weight:500">';
            echo '<span>' . $icon . '</span>';
            echo '<span>' . h($doc['file_name']) . '</span>';
            echo '<span style="margin-left:auto;font-size:11px;color:hsl(var(--primary))">Abrir ↗</span>';
            echo '</a>';
        }
        echo '</div>';
        echo '</div>';
    }
    
    // Documentos enviados manualmente pela equipe - agrupados por atendimento/sessao
    if (!empty($manualDocsForProf)) {
        echo '<div style="margin-top:24px;padding-top:20px;border-top:1px solid hsl(var(--border))">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">';
        echo '<div style="font-size:15px;font-weight:700;color:hsl(var(--foreground))">Documentos Recebidos</div>';
        echo '<div style="font-size:12px;color:hsl(var(--muted-foreground))">' . count($manualDocsForProf) . ' documento(s)</div>';
        echo '</div>';
        echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:14px">Documentos enviados pela equipe administrativa.</div>';

        // Agrupar por atendimento e sessao
        $grouped = [];
        foreach ($manualDocsForProf as $doc) {
            $notes = (string)($doc['notes'] ?? '');
            $atendKey = 'Outros Documentos';
            $sessKey = 'Geral';
            if (preg_match('/Atendimento #(\d+)/i', $notes, $m)) {
                $atendKey = 'Atendimento #' . $m[1];
            }
            if (preg_match('/Sessao #?(\d+)/i', $notes, $sm)) {
                $sessKey = 'Sessao ' . $sm[1];
            }
            $grouped[$atendKey][$sessKey][] = $doc;
        }

        echo '<div>';
        foreach ($grouped as $atendName => $sessions) {
            $totalAtend = 0;
            foreach ($sessions as $docs) { $totalAtend += count($docs); }
            echo '<div style="margin-bottom:8px;border:1px solid hsl(var(--border));border-radius:8px;overflow:hidden">';
            echo '<div onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display===\'none\'?\'block\':\'none\'" style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:hsl(var(--secondary));cursor:pointer;user-select:none">';
            echo '<div style="font-size:13px;font-weight:700;color:hsl(var(--foreground))">' . h($atendName) . ' (' . $totalAtend . ')</div>';
            echo '<span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:hsla(180,65%,46%,.15);color:hsl(var(--primary));font-size:12px;font-weight:700">+</span>';
            echo '</div>';
            echo '<div style="display:none;padding:4px 8px">';

            foreach ($sessions as $sessName => $docs) {
                if ($sessName !== 'Geral' || count($sessions) > 1) {
                    echo '<div style="margin:8px 0 4px 8px;font-size:12px;font-weight:600;color:hsl(var(--muted-foreground))">' . h($sessName) . '</div>';
                }
                foreach ($docs as $doc) {
                    $icon = preg_match('/\.pdf$/i', $doc['file_name']) ? '📄' : (preg_match('/\.(jpg|jpeg|png|webp)$/i', $doc['file_name']) ? '🖼️' : '📎');
                    echo '<a href="' . h($doc['file_path']) . '" target="_blank" style="display:flex;align-items:center;gap:10px;padding:8px 12px;margin:2px 0;border-radius:6px;text-decoration:none;color:hsl(var(--foreground));font-size:13px">';
                    echo '<span>' . $icon . '</span>';
                    echo '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . h($doc['file_name']) . '</span>';
                    echo '<span style="flex-shrink:0;font-size:11px;color:hsl(var(--primary))">Abrir ↗</span>';
                    echo '</a>';
                }
            }
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }
    
    echo '</section>';
}

// Documentos enviados manualmente (exibir mesmo se não houver docs da operadora)
if (!empty($manualDocsForProf) && empty($insurerDocsList)) {
    echo '<section class="card col12">';
    echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">';
    echo '<h3 style="margin:0">Documentos Recebidos</h3>';
    echo '<div style="font-size:12px;color:hsl(var(--muted-foreground))">' . count($manualDocsForProf) . ' documento(s)</div>';
    echo '</div>';
    echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:14px">Documentos enviados pela equipe administrativa.</div>';

    // Mesma estrutura de agrupamento
    $grouped2 = [];
    foreach ($manualDocsForProf as $doc) {
        $notes = (string)($doc['notes'] ?? '');
        $atendKey = 'Outros Documentos';
        $sessKey = 'Geral';
        if (preg_match('/Atendimento #(\d+)/i', $notes, $m)) { $atendKey = 'Atendimento #' . $m[1]; }
        if (preg_match('/Sessao #?(\d+)/i', $notes, $sm)) { $sessKey = 'Sessao ' . $sm[1]; }
        $grouped2[$atendKey][$sessKey][] = $doc;
    }

    echo '<div>';
    foreach ($grouped2 as $atendName => $sessions) {
        $totalAtend = 0;
        foreach ($sessions as $docs) { $totalAtend += count($docs); }
        echo '<div style="margin-bottom:8px;border:1px solid hsl(var(--border));border-radius:8px;overflow:hidden">';
        echo '<div onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display===\'none\'?\'block\':\'none\'" style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:hsl(var(--secondary));cursor:pointer;user-select:none">';
        echo '<div style="font-size:13px;font-weight:700;color:hsl(var(--foreground))">' . h($atendName) . ' (' . $totalAtend . ')</div>';
        echo '<span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:hsla(180,65%,46%,.15);color:hsl(var(--primary));font-size:12px;font-weight:700">+</span>';
        echo '</div>';
        echo '<div style="display:none;padding:4px 8px">';
        foreach ($sessions as $sessName => $docs) {
            if ($sessName !== 'Geral' || count($sessions) > 1) {
                echo '<div style="margin:8px 0 4px 8px;font-size:12px;font-weight:600;color:hsl(var(--muted-foreground))">' . h($sessName) . '</div>';
            }
            foreach ($docs as $doc) {
                $icon = preg_match('/\.pdf$/i', $doc['file_name']) ? '📄' : (preg_match('/\.(jpg|jpeg|png|webp)$/i', $doc['file_name']) ? '🖼️' : '📎');
                echo '<a href="' . h($doc['file_path']) . '" target="_blank" style="display:flex;align-items:center;gap:10px;padding:8px 12px;margin:2px 0;border-radius:6px;text-decoration:none;color:hsl(var(--foreground));font-size:13px">';
                echo '<span>' . $icon . '</span>';
                echo '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . h($doc['file_name']) . '</span>';
                echo '<span style="flex-shrink:0;font-size:11px;color:hsl(var(--primary))">Abrir ↗</span>';
                echo '</a>';
            }
        }
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
    echo '</section>';
}

view_footer();
