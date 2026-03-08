<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

$db = db();

// Pré-selecionar tipo se vier na URL
$preselectedType = isset($_GET['type']) && in_array($_GET['type'], ['income', 'expense']) ? $_GET['type'] : '';

// Buscar categorias
$categories = $db->query(
    "SELECT * FROM financial_categories WHERE is_active = 1 ORDER BY type, name"
)->fetchAll();

// Buscar centros de custo
$costCenters = $db->query(
    "SELECT * FROM cost_centers WHERE is_active = 1 ORDER BY name"
)->fetchAll();

// Buscar pacientes
$patients = $db->query(
    "SELECT id, name FROM patients WHERE deleted_at IS NULL ORDER BY name LIMIT 100"
)->fetchAll();

// Buscar profissionais
$professionals = $db->query(
    "SELECT u.id, u.name 
     FROM users u 
     INNER JOIN user_roles ur ON ur.user_id = u.id 
     INNER JOIN roles r ON r.id = ur.role_id 
     WHERE r.slug = 'profissional' AND u.status = 'active' 
     ORDER BY u.name"
)->fetchAll();

view_header('Novo Lançamento Financeiro');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Novo Lançamento Financeiro</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Registre receitas, despesas, contas a pagar e a receber</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px">';
echo '<a class="btn" href="/finance_entries_list.php">Ver Lançamentos</a>';
echo '<a class="btn" href="/finance_dashboard.php">Dashboard</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/finance_entry_create_post.php" id="financeForm">';

// Tipo de lançamento
echo '<div class="grid">';
echo '<div class="col6">';
echo '<label>Tipo de Lançamento *';
echo '<select name="entry_type" id="entryType" required onchange="updateCategoryOptions()">';
echo '<option value="">Selecione...</option>';
echo '<option value="income"' . ($preselectedType === 'income' ? ' selected' : '') . '>Receita (Entrada)</option>';
echo '<option value="expense"' . ($preselectedType === 'expense' ? ' selected' : '') . '>Despesa (Saída)</option>';
echo '</select>';
echo '</label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Categoria *';
echo '<select name="category" id="category" required>';
echo '<option value="">Selecione o tipo primeiro...</option>';
echo '</select>';
echo '</label>';
echo '</div>';
echo '</div>';

// Tipo de pagamento
echo '<div class="grid">';
echo '<div class="col6">';
echo '<label>Tipo de Pagamento *';
echo '<select name="payment_type" id="paymentType" required onchange="togglePaymentFields()">';
echo '<option value="single">Pagamento Único</option>';
echo '<option value="installment">Parcelado</option>';
echo '<option value="recurring">Recorrente</option>';
echo '<option value="continuous">Contínuo</option>';
echo '</select>';
echo '</label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Forma de Pagamento';
echo '<select name="payment_method">';
echo '<option value="">Selecione...</option>';
echo '<option value="PIX">PIX</option>';
echo '<option value="Dinheiro">Dinheiro</option>';
echo '<option value="Cartão de Crédito">Cartão de Crédito</option>';
echo '<option value="Cartão de Débito">Cartão de Débito</option>';
echo '<option value="Boleto">Boleto</option>';
echo '<option value="Transferência">Transferência Bancária</option>';
echo '<option value="Cheque">Cheque</option>';
echo '<option value="Outros">Outros</option>';
echo '</select>';
echo '</label>';
echo '</div>';
echo '</div>';

// Campos de parcelamento (ocultos inicialmente)
echo '<div class="grid" id="installmentFields" style="display:none">';
echo '<div class="col6">';
echo '<label>Número de Parcelas';
echo '<input type="number" name="total_installments" id="totalInstallments" min="2" max="120" value="2">';
echo '</label>';
echo '</div>';
echo '<div class="col6">';
echo '<label>Valor Total';
echo '<input type="number" name="total_amount" id="totalAmount" step="0.01" min="0.01" placeholder="0.00">';
echo '<div class="helpText">O valor será dividido igualmente entre as parcelas</div>';
echo '</label>';
echo '</div>';
echo '</div>';

// Campos de recorrência (ocultos inicialmente)
echo '<div class="grid" id="recurrenceFields" style="display:none">';
echo '<div class="col6">';
echo '<label>Frequência';
echo '<select name="recurrence_frequency" id="recurrenceFrequency">';
echo '<option value="daily">Diária</option>';
echo '<option value="weekly">Semanal</option>';
echo '<option value="biweekly">Quinzenal</option>';
echo '<option value="monthly" selected>Mensal</option>';
echo '<option value="quarterly">Trimestral</option>';
echo '<option value="semiannual">Semestral</option>';
echo '<option value="annual">Anual</option>';
echo '</select>';
echo '</label>';
echo '</div>';
echo '<div class="col6">';
echo '<label>Data Final da Recorrência';
echo '<input type="date" name="recurrence_end_date" id="recurrenceEndDate">';
echo '<div class="helpText">Deixe em branco para recorrência sem fim</div>';
echo '</label>';
echo '</div>';
echo '</div>';

// Valor e datas
echo '<div class="grid">';
echo '<div class="col4">';
echo '<label>Valor *';
echo '<input type="number" name="amount" id="amount" step="0.01" min="0.01" required placeholder="0.00">';
echo '</label>';
echo '</div>';

echo '<div class="col4">';
echo '<label>Data do Lançamento *';
echo '<input type="date" name="entry_date" required value="' . date('Y-m-d') . '">';
echo '</label>';
echo '</div>';

echo '<div class="col4">';
echo '<label>Data de Vencimento';
echo '<input type="date" name="due_date" id="dueDate">';
echo '</label>';
echo '</div>';
echo '</div>';

// Referências opcionais
echo '<div class="grid">';
echo '<div class="col6">';
echo '<label>Paciente (opcional)';
echo '<select name="patient_id">';
echo '<option value="">Nenhum</option>';
foreach ($patients as $patient) {
    echo '<option value="' . (int)$patient['id'] . '">' . h((string)$patient['name']) . '</option>';
}
echo '</select>';
echo '</label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Profissional (opcional)';
echo '<select name="professional_user_id">';
echo '<option value="">Nenhum</option>';
foreach ($professionals as $prof) {
    echo '<option value="' . (int)$prof['id'] . '">' . h((string)$prof['name']) . '</option>';
}
echo '</select>';
echo '</label>';
echo '</div>';
echo '</div>';

// Informações adicionais
echo '<div class="grid">';
echo '<div class="col6">';
echo '<label>Fornecedor/Cliente';
echo '<input type="text" name="supplier_name" maxlength="200" placeholder="Nome do fornecedor ou cliente">';
echo '</label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Centro de Custo';
echo '<select name="cost_center">';
echo '<option value="">Selecione...</option>';
foreach ($costCenters as $cc) {
    echo '<option value="' . h((string)$cc['name']) . '">' . h((string)$cc['name']) . '</option>';
}
echo '</select>';
echo '</label>';
echo '</div>';
echo '</div>';

echo '<div class="grid">';
echo '<div class="col6">';
echo '<label>Número do Documento';
echo '<input type="text" name="document_number" maxlength="100" placeholder="NF, boleto, recibo, etc">';
echo '</label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Status *';
echo '<select name="status" required>';
echo '<option value="pending">Pendente</option>';
echo '<option value="paid">Pago</option>';
echo '</select>';
echo '</label>';
echo '</div>';
echo '</div>';

// Descrição e observações
echo '<label>Descrição *';
echo '<textarea name="description" required rows="3" placeholder="Descreva o lançamento..."></textarea>';
echo '</label>';

echo '<label>Observações';
echo '<textarea name="notes" rows="2" placeholder="Observações adicionais (opcional)"></textarea>';
echo '</label>';

echo '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:10px">';
echo '<a href="/finance_entries_list.php" class="btn">Cancelar</a>';
echo '<button type="submit" class="btn btnPrimary">Criar Lançamento</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

// JavaScript para controlar campos dinâmicos
echo '<script>';
echo 'const categories = ' . json_encode($categories) . ';';
echo '
function updateCategoryOptions() {
    const entryType = document.getElementById("entryType").value;
    const categorySelect = document.getElementById("category");
    
    categorySelect.innerHTML = "<option value=\"\">Selecione...</option>";
    
    if (!entryType) return;
    
    categories.forEach(cat => {
        if (cat.type === entryType || cat.type === "both") {
            const option = document.createElement("option");
            option.value = cat.name;
            option.textContent = cat.name;
            categorySelect.appendChild(option);
        }
    });
}

function togglePaymentFields() {
    const paymentType = document.getElementById("paymentType").value;
    const installmentFields = document.getElementById("installmentFields");
    const recurrenceFields = document.getElementById("recurrenceFields");
    const amountField = document.getElementById("amount");
    const totalAmountField = document.getElementById("totalAmount");
    
    // Resetar displays
    installmentFields.style.display = "none";
    recurrenceFields.style.display = "none";
    
    // Resetar required
    totalAmountField.removeAttribute("required");
    document.getElementById("recurrenceFrequency").removeAttribute("required");
    
    if (paymentType === "installment") {
        installmentFields.style.display = "grid";
        totalAmountField.setAttribute("required", "required");
        amountField.removeAttribute("required");
    } else if (paymentType === "recurring" || paymentType === "continuous") {
        recurrenceFields.style.display = "grid";
        document.getElementById("recurrenceFrequency").setAttribute("required", "required");
        amountField.setAttribute("required", "required");
    } else {
        amountField.setAttribute("required", "required");
    }
}

// Inicializar
document.addEventListener("DOMContentLoaded", function() {
    togglePaymentFields();
    // Se tipo já está selecionado, carregar categorias
    if (document.getElementById("entryType").value) {
        updateCategoryOptions();
    }
});
';
echo '</script>';

view_footer();
