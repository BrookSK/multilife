<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('billing.manage');

$requirementId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($requirementId === 0) {
    header('Location: /faturamento_list.php');
    exit;
}

// Buscar requisito de documento
$stmt = db()->prepare("
    SELECT 
        bdr.*,
        pa.patient_id,
        pa.specialty,
        pa.service_type,
        pa.session_quantity,
        pa.payment_value,
        p.full_name as patient_name,
        u.name as professional_name,
        d.title as document_title,
        d.file_path,
        d.category
    FROM billing_document_requirements bdr
    INNER JOIN patient_assignments pa ON pa.id = bdr.assignment_id
    LEFT JOIN patients p ON p.id = pa.patient_id
    LEFT JOIN users u ON u.id = bdr.professional_user_id
    LEFT JOIN documents d ON d.id = bdr.document_id
    WHERE bdr.id = ?
");
$stmt->execute([$requirementId]);
$requirement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$requirement || $requirement['status'] !== 'uploaded') {
    header('Location: /faturamento_list.php');
    exit;
}

view_header('Revisar Documento');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div>';
echo '<a href="/faturamento_view.php?id=' . (int)$requirement['assignment_id'] . '" class="btn" style="margin-bottom:8px">← Voltar</a>';
echo '<div style="font-size:22px;font-weight:900">Revisar Documento de Comprovação</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Sessão ' . (int)$requirement['session_number'] . ' - ' . h($requirement['patient_name']) . '</div>';
echo '</div>';
echo '</section>';

// Informações do Atendimento
echo '<section class="card col6">';
echo '<h3>Informações do Atendimento</h3>';
echo '<table style="width:100%">';
echo '<tr><td style="font-weight:600;padding:8px 0">Paciente:</td><td>' . h($requirement['patient_name']) . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Profissional:</td><td>' . h($requirement['professional_name']) . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Especialidade:</td><td>' . h($requirement['specialty'] ?? '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Sessão:</td><td>' . (int)$requirement['session_number'] . ' de ' . (int)$requirement['session_quantity'] . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Data da Sessão:</td><td>' . ($requirement['session_date'] ? date('d/m/Y', strtotime($requirement['session_date'])) : '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Valor:</td><td>R$ ' . number_format((float)$requirement['payment_value'], 2, ',', '.') . '</td></tr>';
echo '</table>';
echo '</section>';

// Informações do Documento
echo '<section class="card col6">';
echo '<h3>Documento Enviado</h3>';
echo '<table style="width:100%">';
echo '<tr><td style="font-weight:600;padding:8px 0">Título:</td><td>' . h($requirement['document_title'] ?? '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Categoria:</td><td>' . h($requirement['category'] ?? '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Enviado em:</td><td>' . ($requirement['uploaded_at'] ? date('d/m/Y H:i', strtotime($requirement['uploaded_at'])) : '-') . '</td></tr>';
echo '</table>';

if ($requirement['file_path']) {
    echo '<div style="margin-top:16px">';
    echo '<a class="btn btnPrimary" href="' . h($requirement['file_path']) . '" target="_blank">Visualizar Documento</a>';
    echo '</div>';
}
echo '</section>';

// Visualização do Documento
if ($requirement['file_path']) {
    echo '<section class="card col12">';
    echo '<h3>Pré-visualização</h3>';
    
    $fileExtension = strtolower(pathinfo($requirement['file_path'], PATHINFO_EXTENSION));
    
    if ($fileExtension === 'pdf') {
        echo '<iframe src="' . h($requirement['file_path']) . '" style="width:100%;height:600px;border:1px solid #e5e7eb;border-radius:6px"></iframe>';
    } elseif (in_array($fileExtension, ['jpg', 'jpeg', 'png'])) {
        echo '<img src="' . h($requirement['file_path']) . '" style="max-width:100%;height:auto;border:1px solid #e5e7eb;border-radius:6px">';
    } else {
        echo '<div style="padding:40px;text-align:center;color:#667781">Pré-visualização não disponível para este tipo de arquivo</div>';
    }
    
    echo '</section>';
}

// Formulário de Revisão
echo '<section class="card col12">';
echo '<h3>Revisão</h3>';

echo '<form method="post" action="/faturamento_review_doc_post.php">';
echo '<input type="hidden" name="requirement_id" value="' . $requirementId . '">';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Decisão *</label>';
echo '<div style="display:flex;gap:16px">';
echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer">';
echo '<input type="radio" name="decision" value="approve" required>';
echo '<span style="color:#10b981;font-weight:600">Aprovar Documento</span>';
echo '</label>';
echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer">';
echo '<input type="radio" name="decision" value="reject" required>';
echo '<span style="color:#dc2626;font-weight:600">Rejeitar Documento</span>';
echo '</label>';
echo '</div>';
echo '</div>';

echo '<div style="margin-bottom:16px" id="rejection-reason-field" style="display:none">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Motivo da Rejeição *</label>';
echo '<textarea name="rejection_reason" rows="4" placeholder="Descreva o motivo da rejeição..." style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px"></textarea>';
echo '</div>';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Observações</label>';
echo '<textarea name="notes" rows="3" placeholder="Observações adicionais sobre a revisão..." style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px"></textarea>';
echo '</div>';

echo '<div style="display:flex;gap:10px">';
echo '<button type="submit" class="btn btnPrimary">Confirmar Revisão</button>';
echo '<a href="/faturamento_view.php?id=' . (int)$requirement['assignment_id'] . '" class="btn">Cancelar</a>';
echo '</div>';

echo '</form>';

echo '<script>';
echo 'document.querySelectorAll(\'input[name="decision"]\').forEach(radio => {';
echo '  radio.addEventListener(\'change\', function() {';
echo '    const rejectionField = document.getElementById(\'rejection-reason-field\');';
echo '    if (this.value === \'reject\') {';
echo '      rejectionField.style.display = \'block\';';
echo '      rejectionField.querySelector(\'textarea\').required = true;';
echo '    } else {';
echo '      rejectionField.style.display = \'none\';';
echo '      rejectionField.querySelector(\'textarea\').required = false;';
echo '    }';
echo '  });';
echo '});';
echo '</script>';

echo '</section>';

echo '</div>';

view_footer();
