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
        bi.final_value
    FROM patient_assignments pa
    LEFT JOIN patients p ON p.id = pa.patient_id
    LEFT JOIN users u ON u.id = pa.professional_user_id
    LEFT JOIN billing_invoices bi ON bi.assignment_id = pa.id
    WHERE pa.id = ? AND pa.status = 'approved'
");
$stmt->execute([$assignmentId]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment || !$assignment['invoice_id']) {
    $_SESSION['error'] = 'Atendimento não encontrado ou não está aprovado';
    header('Location: /faturamento_list.php');
    exit;
}

$finalValue = (float)$assignment['final_value'];

view_header('Finalizar Atendimento');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div>';
echo '<a href="/faturamento_view.php?id=' . $assignmentId . '" class="btn" style="margin-bottom:8px">← Voltar</a>';
echo '<div style="font-size:22px;font-weight:900">Finalizar Atendimento</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Atendimento #' . (int)$assignment['id'] . ' - ' . h($assignment['patient_name']) . '</div>';
echo '</div>';
echo '</section>';

// Resumo Final
echo '<section class="card col6">';
echo '<h3>Resumo Final</h3>';
echo '<table style="width:100%">';
echo '<tr><td style="font-weight:600;padding:8px 0">Paciente:</td><td>' . h($assignment['patient_name']) . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Profissional:</td><td>' . h($assignment['professional_name']) . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Especialidade:</td><td>' . h($assignment['specialty'] ?? '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Sessões Realizadas:</td><td>' . (int)$assignment['session_quantity'] . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Valor Total:</td><td>R$ ' . number_format((float)$assignment['total_value'], 2, ',', '.') . '</td></tr>';

if ($assignment['adjusted_value'] !== null) {
    echo '<tr><td style="font-weight:600;padding:8px 0">Valor Ajustado:</td><td>R$ ' . number_format((float)$assignment['adjusted_value'], 2, ',', '.') . '</td></tr>';
}

echo '<tr><td style="font-weight:600;padding:8px 0;border-top:2px solid #e5e7eb">Valor Final:</td><td style="font-size:20px;font-weight:700;color:#00a884;border-top:2px solid #e5e7eb">R$ ' . number_format($finalValue, 2, ',', '.') . '</td></tr>';
echo '</table>';
echo '</section>';

// Informações sobre Finalização
echo '<section class="card col6" style="background:#f0fdf4;border-left:4px solid #10b981">';
echo '<h3>O que acontece ao finalizar?</h3>';
echo '<ul style="margin:0;padding-left:20px;line-height:1.8">';
echo '<li>O atendimento será marcado como <strong>Concluído</strong></li>';
echo '<li>O card na Captação mudará para <strong>Concluído</strong></li>';
echo '<li>Um lançamento financeiro será criado</li>';
echo '<li>O valor será registrado no financeiro</li>';
echo '<li>Esta ação não pode ser desfeita</li>';
echo '</ul>';
echo '</section>';

// Formulário de Finalização
echo '<section class="card col12">';
echo '<h3>Confirmar Finalização</h3>';

echo '<form method="post" action="/faturamento_complete_post.php">';
echo '<input type="hidden" name="assignment_id" value="' . $assignmentId . '">';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Data de Pagamento</label>';
echo '<input type="date" name="payment_date" value="' . date('Y-m-d') . '" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px">';
echo '</div>';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Referência de Pagamento</label>';
echo '<input type="text" name="payment_reference" placeholder="Ex: Transferência, Boleto #123, etc." style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px">';
echo '</div>';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Observações Finais</label>';
echo '<textarea name="notes" rows="4" placeholder="Observações sobre a finalização do atendimento..." style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px"></textarea>';
echo '</div>';

echo '<div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:16px;margin-bottom:16px">';
echo '<div style="font-weight:700;color:#92400e;margin-bottom:8px">⚠️ Atenção</div>';
echo '<div style="color:#78350f">Ao finalizar, o atendimento será marcado como concluído e os valores serão registrados no financeiro. Esta ação não pode ser desfeita.</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px">';
echo '<button type="submit" class="btn btnPrimary">Finalizar Atendimento</button>';
echo '<a href="/faturamento_view.php?id=' . $assignmentId . '" class="btn">Cancelar</a>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
