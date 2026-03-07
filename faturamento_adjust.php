<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('billing.manage');

$assignmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($assignmentId === 0) {
    header('Location: /faturamento_list.php');
    exit;
}

// Buscar atendimento e fatura
$stmt = db()->prepare("
    SELECT 
        pa.*,
        p.full_name as patient_name,
        u.name as professional_name,
        bi.id as invoice_id,
        bi.total_value,
        bi.adjusted_value,
        bi.final_value,
        bi.adjustment_reason
    FROM patient_assignments pa
    LEFT JOIN patients p ON p.id = pa.patient_id
    LEFT JOIN users u ON u.id = pa.professional_user_id
    LEFT JOIN billing_invoices bi ON bi.assignment_id = pa.id
    WHERE pa.id = ? AND pa.status = 'approved'
");
$stmt->execute([$assignmentId]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    $_SESSION['error'] = 'Atendimento não encontrado ou não está aprovado';
    header('Location: /faturamento_list.php');
    exit;
}

$totalValue = (float)$assignment['payment_value'] * (int)$assignment['session_quantity'];
$currentFinalValue = $assignment['final_value'] ? (float)$assignment['final_value'] : $totalValue;

view_header('Ajustar Valores');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div>';
echo '<a href="/faturamento_view.php?id=' . $assignmentId . '" class="btn" style="margin-bottom:8px">← Voltar</a>';
echo '<div style="font-size:22px;font-weight:900">Ajustar Valores do Faturamento</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Atendimento #' . (int)$assignment['id'] . ' - ' . h($assignment['patient_name']) . '</div>';
echo '</div>';
echo '</section>';

// Valores Atuais
echo '<section class="card col6">';
echo '<h3>Valores Atuais</h3>';
echo '<table style="width:100%">';
echo '<tr><td style="font-weight:600;padding:8px 0">Paciente:</td><td>' . h($assignment['patient_name']) . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Profissional:</td><td>' . h($assignment['professional_name']) . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Sessões:</td><td>' . (int)$assignment['session_quantity'] . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Valor/Sessão:</td><td>R$ ' . number_format((float)$assignment['payment_value'], 2, ',', '.') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0;border-top:2px solid #e5e7eb">Valor Total Original:</td><td style="border-top:2px solid #e5e7eb">R$ ' . number_format($totalValue, 2, ',', '.') . '</td></tr>';

if ($assignment['adjusted_value'] !== null) {
    echo '<tr><td style="font-weight:600;padding:8px 0">Valor Ajustado Anterior:</td><td>R$ ' . number_format((float)$assignment['adjusted_value'], 2, ',', '.') . '</td></tr>';
    echo '<tr><td style="font-weight:600;padding:8px 0">Motivo Anterior:</td><td>' . h($assignment['adjustment_reason'] ?? '-') . '</td></tr>';
}

echo '<tr><td style="font-weight:600;padding:8px 0;border-top:2px solid #e5e7eb">Valor Final Atual:</td><td style="font-size:18px;font-weight:700;color:#00a884;border-top:2px solid #e5e7eb">R$ ' . number_format($currentFinalValue, 2, ',', '.') . '</td></tr>';
echo '</table>';
echo '</section>';

// Alerta
echo '<section class="card col6" style="background:#fef3c7;border-left:4px solid #f59e0b">';
echo '<h3>Atenção</h3>';
echo '<p style="line-height:1.6">Use esta função apenas quando necessário ajustar valores por:</p>';
echo '<ul style="margin:0;padding-left:20px;line-height:1.8">';
echo '<li>Descontos acordados</li>';
echo '<li>Correções de valores</li>';
echo '<li>Cancelamento parcial de sessões</li>';
echo '<li>Outros ajustes autorizados</li>';
echo '</ul>';
echo '<p style="margin-top:16px;font-weight:600;color:#92400e">Todos os ajustes são registrados e auditáveis.</p>';
echo '</section>';

// Formulário de Ajuste
echo '<section class="card col12">';
echo '<h3>Novo Ajuste</h3>';

echo '<form method="post" action="/faturamento_adjust_post.php">';
echo '<input type="hidden" name="assignment_id" value="' . $assignmentId . '">';
echo '<input type="hidden" name="original_value" value="' . $totalValue . '">';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Novo Valor Final *</label>';
echo '<input type="number" name="adjusted_value" step="0.01" min="0" required value="' . $currentFinalValue . '" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px">';
echo '<div style="font-size:12px;color:#667781;margin-top:4px">Valor original: R$ ' . number_format($totalValue, 2, ',', '.') . '</div>';
echo '</div>';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Motivo do Ajuste *</label>';
echo '<textarea name="adjustment_reason" rows="4" required placeholder="Descreva o motivo do ajuste de valor..." style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px"></textarea>';
echo '</div>';

echo '<div style="display:flex;gap:10px">';
echo '<button type="submit" class="btn btnPrimary">Salvar Ajuste</button>';
echo '<a href="/faturamento_view.php?id=' . $assignmentId . '" class="btn">Cancelar</a>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
