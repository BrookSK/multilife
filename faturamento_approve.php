<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
// rbac_require_permission('billing.manage'); // TODO: Configurar permissão no sistema

$assignmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($assignmentId === 0) {
    header('Location: /faturamento_list.php');
    exit;
}

// Buscar atendimento
$stmt = db()->prepare("
    SELECT 
        pa.*,
        p.full_name as patient_name,
        u.name as professional_name
    FROM patient_assignments pa
    LEFT JOIN patients p ON p.id = pa.patient_id
    LEFT JOIN users u ON u.id = pa.professional_user_id
    WHERE pa.id = ? AND pa.status = 'awaiting_financial_approval'
");
$stmt->execute([$assignmentId]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    $_SESSION['error'] = 'Atendimento não encontrado ou não está aguardando aprovação financeira';
    header('Location: /faturamento_list.php');
    exit;
}

// Verificar se todos os documentos foram aprovados
$docsStmt = db()->prepare("
    SELECT COUNT(*) as pending_count
    FROM billing_document_requirements
    WHERE assignment_id = ? AND status != 'approved'
");
$docsStmt->execute([$assignmentId]);
$docsCheck = $docsStmt->fetch(PDO::FETCH_ASSOC);

if ((int)$docsCheck['pending_count'] > 0) {
    $_SESSION['error'] = 'Ainda existem documentos pendentes de aprovação';
    header('Location: /faturamento_view.php?id=' . $assignmentId);
    exit;
}

// Calcular valores usando novos campos ou fallback para payment_value
$agreedValue = isset($assignment['agreed_value']) && $assignment['agreed_value'] > 0 
    ? (float)$assignment['agreed_value'] 
    : (float)$assignment['payment_value'];
$authorizedValue = isset($assignment['authorized_value']) && $assignment['authorized_value'] > 0 
    ? (float)$assignment['authorized_value'] 
    : (float)$assignment['payment_value'];

$totalRevenue = $agreedValue * (int)$assignment['session_quantity'];      // RECEITA: cliente paga
$totalCost = $authorizedValue * (int)$assignment['session_quantity'];   // CUSTO: profissional recebe
$totalProfit = $totalRevenue - $totalCost;                               // LUCRO: receita - custo

view_header('Aprovar Financeiro');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div>';
echo '<a href="/faturamento_view.php?id=' . $assignmentId . '" class="btn" style="margin-bottom:8px">← Voltar</a>';
echo '<div style="font-size:22px;font-weight:900">Aprovação Financeira</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Atendimento #' . (int)$assignment['id'] . ' - ' . h($assignment['patient_name']) . '</div>';
echo '</div>';
echo '</section>';

// Resumo do Atendimento
echo '<section class="card col6">';
echo '<h3>Resumo do Atendimento</h3>';
echo '<table style="width:100%">';
echo '<tr><td style="font-weight:600;padding:8px 0">Paciente:</td><td>' . h($assignment['patient_name']) . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Profissional:</td><td>' . h($assignment['professional_name']) . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Especialidade:</td><td>' . h($assignment['specialty'] ?? '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Tipo de Serviço:</td><td>' . h($assignment['service_type'] ?? '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Sessões:</td><td>' . (int)$assignment['session_quantity'] . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Valor Acordado/Sessão:</td><td>R$ ' . number_format($agreedValue, 2, ',', '.') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Valor Autorizado/Sessão:</td><td>R$ ' . number_format($authorizedValue, 2, ',', '.') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0;border-top:2px solid #e5e7eb">Custo Total:</td><td style="font-size:16px;font-weight:700;color:#dc2626">R$ ' . number_format($totalCost, 2, ',', '.') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Receita Total:</td><td style="font-size:16px;font-weight:700;color:#00a884">R$ ' . number_format($totalRevenue, 2, ',', '.') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0;border-top:2px solid #e5e7eb">Lucro Real:</td><td style="font-size:18px;font-weight:700;color:' . ($totalProfit >= 0 ? '#00a884' : '#dc2626') . ';border-top:2px solid #e5e7eb">R$ ' . number_format($totalProfit, 2, ',', '.') . '</td></tr>';
echo '</table>';
echo '</section>';

// Alerta de Confirmação
echo '<section class="card col6" style="background:#f0f9ff;border-left:4px solid #0284c7">';
echo '<h3>Confirmação</h3>';
echo '<p style="line-height:1.6">Ao aprovar financeiramente este atendimento, você confirma que:</p>';
echo '<ul style="margin:0;padding-left:20px;line-height:1.8">';
echo '<li>Todos os documentos foram revisados e aprovados</li>';
echo '<li>Os valores estão corretos</li>';
echo '<li>O atendimento está pronto para finalização</li>';
echo '</ul>';
echo '<p style="margin-top:16px;font-weight:600;color:#0369a1">Após a aprovação, o atendimento poderá ser finalizado e os valores serão registrados no financeiro.</p>';
echo '</section>';

// Formulário de Aprovação
echo '<section class="card col12">';
echo '<h3>Aprovar Financeiro</h3>';

echo '<form method="post" action="/faturamento_approve_post.php">';
echo '<input type="hidden" name="assignment_id" value="' . $assignmentId . '">';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Observações</label>';
echo '<textarea name="notes" rows="4" placeholder="Observações sobre a aprovação financeira..." style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px"></textarea>';
echo '</div>';

echo '<div style="display:flex;gap:10px">';
echo '<button type="submit" class="btn btnPrimary">Aprovar Financeiro</button>';
echo '<a href="/faturamento_view.php?id=' . $assignmentId . '" class="btn">Cancelar</a>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
