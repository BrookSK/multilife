<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$requirementId = isset($_GET['requirement_id']) ? (int)$_GET['requirement_id'] : 0;
$userId = auth_user_id();

if ($requirementId === 0) {
    header('Location: /faturamento_profissional.php');
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
        p.full_name as patient_name
    FROM billing_document_requirements bdr
    INNER JOIN patient_assignments pa ON pa.id = bdr.assignment_id
    LEFT JOIN patients p ON p.id = pa.patient_id
    WHERE bdr.id = ? AND bdr.professional_user_id = ?
");
$stmt->execute([$requirementId, $userId]);
$requirement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$requirement) {
    header('Location: /faturamento_profissional.php');
    exit;
}

view_header('Enviar Documento de Comprovação');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div>';
echo '<a href="/faturamento_profissional.php" class="btn" style="margin-bottom:8px">← Voltar</a>';
echo '<div style="font-size:22px;font-weight:900">Enviar Documento de Comprovação</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Sessão ' . (int)$requirement['session_number'] . ' - ' . h($requirement['patient_name']) . '</div>';
echo '</div>';
echo '</section>';

// Informações do Atendimento
echo '<section class="card col6">';
echo '<h3>Informações do Atendimento</h3>';
echo '<table style="width:100%">';
echo '<tr><td style="font-weight:600;padding:8px 0">Paciente:</td><td>' . h($requirement['patient_name']) . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Especialidade:</td><td>' . h($requirement['specialty'] ?? '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Tipo de Serviço:</td><td>' . h($requirement['service_type'] ?? '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Sessão:</td><td>' . (int)$requirement['session_number'] . ' de ' . (int)$requirement['session_quantity'] . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Data da Sessão:</td><td>' . ($requirement['session_date'] ? date('d/m/Y', strtotime($requirement['session_date'])) : '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Valor:</td><td>R$ ' . number_format((float)$requirement['payment_value'], 2, ',', '.') . '</td></tr>';
echo '</table>';
echo '</section>';

// Instruções
echo '<section class="card col6" style="background:#f0f9ff;border-left:4px solid #0284c7">';
echo '<h3>Instruções</h3>';
echo '<ul style="margin:0;padding-left:20px;line-height:1.8">';
echo '<li>Envie o documento que comprove a realização da sessão</li>';
echo '<li>Documentos aceitos: PDF, imagens (JPG, PNG)</li>';
echo '<li>O documento deve conter data, assinatura e identificação do paciente</li>';
echo '<li>Após o envio, o documento será revisado pelo financeiro</li>';
echo '<li>Você será notificado sobre a aprovação ou rejeição</li>';
echo '</ul>';
echo '</section>';

// Formulário de Upload
echo '<section class="card col12">';
echo '<h3>Upload do Documento</h3>';

if ($requirement['status'] === 'rejected' && $requirement['rejection_reason']) {
    echo '<div style="background:#fef2f2;border-left:4px solid #dc2626;padding:16px;margin-bottom:16px">';
    echo '<div style="font-weight:700;color:#991b1b;margin-bottom:8px">Documento Rejeitado</div>';
    echo '<div style="color:#7f1d1d">' . h($requirement['rejection_reason']) . '</div>';
    echo '</div>';
}

echo '<form method="post" action="/faturamento_upload_doc_post.php" enctype="multipart/form-data">';
echo '<input type="hidden" name="requirement_id" value="' . $requirementId . '">';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Título do Documento *</label>';
echo '<input type="text" name="title" required placeholder="Ex: Comprovante de Atendimento - Sessão 1" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px">';
echo '</div>';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Categoria *</label>';
echo '<select name="category" required style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px">';
echo '<option value="">Selecione...</option>';
echo '<option value="Comprovante de Atendimento">Comprovante de Atendimento</option>';
echo '<option value="Nota Fiscal">Nota Fiscal</option>';
echo '<option value="Recibo">Recibo</option>';
echo '<option value="Relatório de Sessão">Relatório de Sessão</option>';
echo '<option value="Outros">Outros</option>';
echo '</select>';
echo '</div>';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Data da Sessão *</label>';
echo '<input type="date" name="session_date" required value="' . h($requirement['session_date'] ?? '') . '" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px">';
echo '</div>';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Arquivo *</label>';
echo '<input type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px">';
echo '<div style="font-size:12px;color:#667781;margin-top:4px">Formatos aceitos: PDF, JPG, PNG (máx. 10MB)</div>';
echo '</div>';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Observações</label>';
echo '<textarea name="notes" rows="4" placeholder="Informações adicionais sobre o atendimento..." style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px"></textarea>';
echo '</div>';

echo '<div style="display:flex;gap:10px">';
echo '<button type="submit" class="btn btnPrimary">Enviar Documento</button>';
echo '<a href="/faturamento_profissional.php" class="btn">Cancelar</a>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
