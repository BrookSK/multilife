<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

$id = (int)($_GET['id'] ?? 0);

if ($id === 0) {
    flash_set('error', 'Lançamento não encontrado.');
    header('Location: /finance_entries_list.php');
    exit;
}

// Buscar lançamento
$stmt = db()->prepare('SELECT * FROM financial_entries WHERE id = :id');
$stmt->execute(['id' => $id]);
$entry = $stmt->fetch();

if (!$entry) {
    flash_set('error', 'Lançamento não encontrado.');
    header('Location: /finance_entries_list.php');
    exit;
}

// Verificar se é um lançamento de folha de pagamento
$payrollEntry = null;
if ($entry['entry_type'] === 'expense' && $entry['category'] === 'Folha de Pagamento') {
    $stmt = db()->prepare('SELECT pe.*, e.full_name, e.cpf, e.position, e.department 
                           FROM hr_payroll_entries pe 
                           INNER JOIN hr_employees e ON e.id = pe.employee_id 
                           WHERE pe.financial_entry_id = :id');
    $stmt->execute(['id' => $id]);
    $payrollEntry = $stmt->fetch();
}

$pageTitle = 'Lançamento Financeiro #' . $id;

view_header($pageTitle);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Detalhes do Lançamento</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Visualize as informações completas do lançamento financeiro.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="' . get_back_url('/finance_entries_list.php') . '">← Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Card de informações principais
echo '<section class="card col12">';
echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:16px">Informações Gerais</h3>';

echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px">';

// Tipo
$typeLabels = ['income' => 'Receita', 'expense' => 'Despesa'];
$typeColors = ['income' => '#10b981', 'expense' => '#ef4444'];
$typeLabel = $typeLabels[$entry['entry_type']] ?? $entry['entry_type'];
$typeColor = $typeColors[$entry['entry_type']] ?? '#6b7280';

echo '<div>';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:4px">Tipo</div>';
echo '<div style="font-size:16px;font-weight:600;color:' . $typeColor . '">' . h($typeLabel) . '</div>';
echo '</div>';

// Status
$statusLabels = ['pending' => 'Pendente', 'paid' => 'Pago', 'cancelled' => 'Cancelado'];
$statusColors = ['pending' => '#f59e0b', 'paid' => '#10b981', 'cancelled' => '#6b7280'];
$statusLabel = $statusLabels[$entry['status']] ?? $entry['status'];
$statusColor = $statusColors[$entry['status']] ?? '#6b7280';

echo '<div>';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:4px">Status</div>';
echo '<div style="font-size:16px;font-weight:600;color:' . $statusColor . '">' . h($statusLabel) . '</div>';
echo '</div>';

// Valor
echo '<div>';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:4px">Valor</div>';
echo '<div style="font-size:20px;font-weight:700;color:' . $typeColor . '">R$ ' . number_format((float)$entry['amount'], 2, ',', '.') . '</div>';
echo '</div>';

// Categoria
echo '<div>';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:4px">Categoria</div>';
echo '<div style="font-size:16px;font-weight:600">' . h((string)$entry['category']) . '</div>';
echo '</div>';

echo '</div>';

echo '<div style="margin-top:20px;padding-top:20px;border-top:1px solid hsl(var(--border));display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px">';

// Data de lançamento
if (!empty($entry['entry_date'])) {
    echo '<div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:4px">Data de Lançamento</div>';
    echo '<div style="font-size:15px">' . date('d/m/Y', strtotime($entry['entry_date'])) . '</div>';
    echo '</div>';
}

// Data de vencimento
if (!empty($entry['due_date'])) {
    echo '<div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:4px">Data de Vencimento</div>';
    echo '<div style="font-size:15px">' . date('d/m/Y', strtotime($entry['due_date'])) . '</div>';
    echo '</div>';
}

// Data de pagamento
if (!empty($entry['payment_date'])) {
    echo '<div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:4px">Data de Pagamento</div>';
    echo '<div style="font-size:15px">' . date('d/m/Y', strtotime($entry['payment_date'])) . '</div>';
    echo '</div>';
}

// Centro de custo
if (!empty($entry['cost_center'])) {
    echo '<div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:4px">Centro de Custo</div>';
    echo '<div style="font-size:15px">' . h((string)$entry['cost_center']) . '</div>';
    echo '</div>';
}

// Forma de pagamento
if (!empty($entry['payment_type'])) {
    $paymentTypes = [
        'dinheiro' => 'Dinheiro',
        'cartao_credito' => 'Cartão de Crédito',
        'cartao_debito' => 'Cartão de Débito',
        'pix' => 'PIX',
        'transferencia' => 'Transferência',
        'boleto' => 'Boleto',
        'cheque' => 'Cheque',
    ];
    $paymentLabel = $paymentTypes[$entry['payment_type']] ?? $entry['payment_type'];
    
    echo '<div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:4px">Forma de Pagamento</div>';
    echo '<div style="font-size:15px">' . h($paymentLabel) . '</div>';
    echo '</div>';
}

// Fornecedor/Cliente
if (!empty($entry['supplier_name'])) {
    echo '<div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:4px">' . ($entry['entry_type'] === 'expense' ? 'Fornecedor' : 'Cliente') . '</div>';
    echo '<div style="font-size:15px">' . h((string)$entry['supplier_name']) . '</div>';
    echo '</div>';
}

echo '</div>';

// Descrição
if (!empty($entry['description'])) {
    echo '<div style="margin-top:20px;padding-top:20px;border-top:1px solid hsl(var(--border))">';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:8px">Descrição</div>';
    echo '<div style="font-size:15px;line-height:1.6">' . nl2br(h((string)$entry['description'])) . '</div>';
    echo '</div>';
}

// Observações
if (!empty($entry['notes'])) {
    echo '<div style="margin-top:20px;padding-top:20px;border-top:1px solid hsl(var(--border))">';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:8px">Observações</div>';
    echo '<div style="font-size:15px;line-height:1.6">' . nl2br(h((string)$entry['notes'])) . '</div>';
    echo '</div>';
}

echo '</section>';

// Se for lançamento de folha de pagamento, mostrar informações do funcionário
if ($payrollEntry) {
    echo '<section class="card col12">';
    echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:16px">📋 Informações da Folha de Pagamento</h3>';
    
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px">';
    
    echo '<div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:4px">Funcionário</div>';
    echo '<div style="font-size:16px;font-weight:600">' . h((string)$payrollEntry['full_name']) . '</div>';
    echo '</div>';
    
    if (!empty($payrollEntry['cpf'])) {
        echo '<div>';
        echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:4px">CPF</div>';
        echo '<div style="font-size:15px">' . h((string)$payrollEntry['cpf']) . '</div>';
        echo '</div>';
    }
    
    if (!empty($payrollEntry['position'])) {
        echo '<div>';
        echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:4px">Cargo</div>';
        echo '<div style="font-size:15px">' . h((string)$payrollEntry['position']) . '</div>';
        echo '</div>';
    }
    
    if (!empty($payrollEntry['department'])) {
        echo '<div>';
        echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:4px">Departamento</div>';
        echo '<div style="font-size:15px">' . h((string)$payrollEntry['department']) . '</div>';
        echo '</div>';
    }
    
    echo '<div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:4px">Mês de Referência</div>';
    echo '<div style="font-size:15px">' . date('m/Y', strtotime($payrollEntry['reference_month'] . '-01')) . '</div>';
    echo '</div>';
    
    echo '</div>';
    
    echo '<div style="margin-top:16px;padding:12px;background:#dbeafe;border-left:4px solid #3b82f6;border-radius:4px">';
    echo '<div style="font-size:13px;color:#1e40af">';
    echo '<strong>ℹ️ Lançamento vinculado à folha de pagamento:</strong> Este lançamento foi gerado automaticamente pelo sistema de RH.';
    echo '</div>';
    echo '</div>';
    
    echo '<div style="margin-top:16px">';
    echo '<a class="btn btnPrimary" href="/hr_employee_profile.php?id=' . (int)$payrollEntry['employee_id'] . '&tab=folha">Ver Perfil do Funcionário</a>';
    echo '</div>';
    
    echo '</section>';
}

echo '</div>';

view_footer();
