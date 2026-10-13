<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

require_once __DIR__ . '/app/finance_invoices_helper.php';
finance_invoices_ensure_schema();

$db = db();

$preDirection = isset($_GET['direction']) && in_array($_GET['direction'], ['income', 'expense'], true) ? (string)$_GET['direction'] : '';
$preEntryId = isset($_GET['financial_entry_id']) ? (int)$_GET['financial_entry_id'] : 0;

// Lançamentos financeiros recentes para vincular (opcional).
$entries = [];
try {
    $entries = $db->query(
        "SELECT id, entry_type, amount, description, entry_date
         FROM financial_entries
         WHERE is_active = 1
         ORDER BY entry_date DESC, id DESC
         LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $entries = [];
}

// Pacientes e profissionais para vínculo opcional.
$patients = $db->query("SELECT id, full_name AS name FROM patients WHERE deleted_at IS NULL ORDER BY full_name LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
$professionals = $db->query(
    "SELECT u.id, u.name FROM users u
     INNER JOIN user_roles ur ON ur.user_id = u.id
     INNER JOIN roles r ON r.id = ur.role_id
     WHERE r.slug = 'profissional' AND u.status = 'active'
     ORDER BY u.name LIMIT 500"
)->fetchAll(PDO::FETCH_ASSOC);

view_header('Nova Nota Fiscal');

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Nova Nota Fiscal</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Suba o arquivo da NF e vincule ao que foi pago ou recebido.</div>';
echo '</div>';
echo '<a class="btn" href="/finance_invoices_list.php">Voltar</a>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/finance_invoice_upload_post.php" enctype="multipart/form-data" style="display:grid;gap:12px;max-width:820px">';

echo '<div class="grid">';
echo '<div class="col6"><label>Direção *<select name="direction" required>';
echo '<option value="">— Selecione —</option>';
echo '<option value="income"' . ($preDirection === 'income' ? ' selected' : '') . '>Recebida (entrada / receita)</option>';
echo '<option value="expense"' . ($preDirection === 'expense' ? ' selected' : '') . '>Paga (saída / despesa)</option>';
echo '</select></label></div>';
echo '<div class="col6"><label>Valor (R$) *<input type="number" name="amount" step="0.01" min="0" required placeholder="0.00"></label></div>';
echo '</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>Número da NF<input type="text" name="invoice_number" maxlength="100" placeholder="Ex: 12345"></label></div>';
echo '<div class="col6"><label>Data de emissão<input type="date" name="issue_date"></label></div>';
echo '</div>';

echo '<div class="grid">';
echo '<div class="col12"><label>Fornecedor / Cliente<input type="text" name="supplier_name" maxlength="200" placeholder="Nome do fornecedor ou cliente"></label></div>';
echo '</div>';

echo '<div class="grid">';
echo '<div class="col12"><label>Vincular a lançamento financeiro (opcional)<select name="financial_entry_id">';
echo '<option value="0">— Nenhum —</option>';
foreach ($entries as $e) {
    $label = '#' . (int)$e['id'] . ' · ' . ($e['entry_type'] === 'income' ? 'Receita' : 'Despesa')
        . ' · R$ ' . number_format((float)$e['amount'], 2, ',', '.')
        . ' · ' . date('d/m/Y', strtotime((string)$e['entry_date']))
        . ' · ' . mb_strimwidth((string)($e['description'] ?? ''), 0, 40, '...');
    $sel = ($preEntryId === (int)$e['id']) ? ' selected' : '';
    echo '<option value="' . (int)$e['id'] . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select></label></div>';
echo '</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>Profissional (opcional)<select name="professional_user_id">';
echo '<option value="0">— Nenhum —</option>';
foreach ($professionals as $p) {
    echo '<option value="' . (int)$p['id'] . '">' . h((string)$p['name']) . '</option>';
}
echo '</select></label></div>';
echo '<div class="col6"><label>Paciente (opcional)<select name="patient_id">';
echo '<option value="0">— Nenhum —</option>';
foreach ($patients as $p) {
    echo '<option value="' . (int)$p['id'] . '">' . h((string)$p['name']) . '</option>';
}
echo '</select></label></div>';
echo '</div>';

echo '<label>Arquivo da NF (PDF, JPG, PNG, XML) *<input type="file" name="invoice_file" accept=".pdf,.jpg,.jpeg,.png,.webp,.xml,application/pdf,image/*,text/xml,application/xml" required></label>';

echo '<label>Observações<textarea name="notes" rows="3" placeholder="Observações adicionais (opcional)"></textarea></label>';

echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
echo '<a class="btn" href="/finance_invoices_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar Nota Fiscal</button>';
echo '</div>';

echo '</form>';
echo '</section>';
echo '</div>';

view_footer();
