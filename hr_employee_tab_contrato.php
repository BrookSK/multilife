<?php
// Aba de Contrato - Dados Contratuais

$employeeId = (int)$employee['id'];

// Buscar lista de centros de custo
$costCenters = db()->query('SELECT id, name FROM cost_centers WHERE is_active = 1 ORDER BY name ASC')->fetchAll();

// Buscar lista de supervisores (usuários ativos)
$supervisors = db()->query('SELECT id, name FROM users WHERE status = "active" ORDER BY name ASC')->fetchAll();

echo '<form method="post" action="/hr_employee_save_contrato_post.php" style="display:grid;gap:24px">';
echo '<input type="hidden" name="employee_id" value="' . $employeeId . '">';

// Dados Contratuais
echo '<div>';
echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid hsl(var(--border))">Dados Contratuais</h3>';
echo '<div class="grid" style="gap:12px">';

echo '<div class="col6"><label>Matrícula<input name="employee_number" maxlength="20" value="' . h((string)($employee['employee_number'] ?? '')) . '" placeholder="000000"></label></div>';

// Buscar funções disponíveis no sistema
$rolesStmt = db()->query('SELECT id, name, slug FROM roles ORDER BY name ASC');
$availableRoles = $rolesStmt->fetchAll();

echo '<div class="col6"><label>Função no Sistema *<select name="role_id" required>';
echo '<option value="">Selecione a função...</option>';
foreach ($availableRoles as $role) {
    $sel = (!$isNew && isset($employee['role_id']) && (int)$employee['role_id'] === (int)$role['id']) ? ' selected' : '';
    echo '<option value="' . (int)$role['id'] . '"' . $sel . '>' . h((string)$role['name']) . ' (' . h((string)$role['slug']) . ')</option>';
}
echo '</select>';
echo '<span style="font-size:12px;color:hsl(var(--muted-foreground));display:block;margin-top:4px">Define o cargo, departamento e permissões de acesso. <a href="/admin_settings.php" style="color:hsl(var(--primary))">Gerenciar funções</a></span>';
echo '</label></div>';

echo '<div class="col6"><label>Centro de Custo<select name="cost_center_id">';
echo '<option value="">Selecione...</option>';
foreach ($costCenters as $cc) {
    $sel = (!empty($employee['cost_center_id']) && $employee['cost_center_id'] == $cc['id']) ? ' selected' : '';
    echo '<option value="' . (int)$cc['id'] . '"' . $sel . '>' . h((string)$cc['name']) . '</option>';
}
echo '</select></label></div>';

echo '<div class="col6"><label>Tipo de Contrato<select name="contract_type">';
echo '<option value="">Selecione...</option>';
$contractTypes = [
    'clt' => 'CLT',
    'pj' => 'PJ (Pessoa Jurídica)',
    'estagio' => 'Estágio',
    'temporario' => 'Temporário',
    'autonomo' => 'Autônomo'
];
foreach ($contractTypes as $val => $label) {
    $sel = (!empty($employee['contract_type']) && $employee['contract_type'] === $val) ? ' selected' : '';
    echo '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
}
echo '</select></label></div>';

echo '<div class="col4"><label>Data de Admissão<input type="date" name="admission_date" value="' . h((string)($employee['admission_date'] ?? '')) . '"></label></div>';
echo '<div class="col4"><label>Salário Base (R$)<input type="number" step="0.01" min="0" name="base_salary" value="' . h((string)($employee['base_salary'] ?? '')) . '" placeholder="0.00"></label></div>';
echo '<div class="col4"><label>Jornada de Trabalho<input name="work_hours" maxlength="50" value="' . h((string)($employee['work_hours'] ?? '')) . '" placeholder="Ex: 40h semanais, 8h/dia"></label></div>';

echo '<div class="col6"><label>Regime de Trabalho<select name="work_regime">';
echo '<option value="">Selecione...</option>';
$workRegimes = [
    'presencial' => 'Presencial',
    'hibrido' => 'Híbrido',
    'remoto' => 'Remoto'
];
foreach ($workRegimes as $val => $label) {
    $sel = (!empty($employee['work_regime']) && $employee['work_regime'] === $val) ? ' selected' : '';
    echo '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
}
echo '</select></label></div>';

echo '<div class="col6"><label>Supervisor/Gestor Direto<select name="supervisor_user_id">';
echo '<option value="">Selecione...</option>';
foreach ($supervisors as $sup) {
    $sel = (!empty($employee['supervisor_user_id']) && $employee['supervisor_user_id'] == $sup['id']) ? ' selected' : '';
    echo '<option value="' . (int)$sup['id'] . '"' . $sel . '>' . h((string)$sup['name']) . '</option>';
}
echo '</select></label></div>';

echo '</div>';
echo '</div>';

// Status do Funcionário
echo '<div>';
echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid hsl(var(--border))">Status e Desligamento</h3>';
echo '<div class="grid" style="gap:12px">';

echo '<div class="col4"><label>Status *<select name="status" required>';
$statuses = [
    'active' => 'Ativo',
    'inactive' => 'Inativo (Afastado)',
    'terminated' => 'Desligado'
];
foreach ($statuses as $val => $label) {
    $sel = ($employee['status'] === $val) ? ' selected' : '';
    echo '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
}
echo '</select></label></div>';

echo '<div class="col4"><label>Data de Desligamento<input type="date" name="termination_date" value="' . h((string)($employee['termination_date'] ?? '')) . '"></label></div>';
echo '<div class="col4"></div>';

echo '<div class="col12"><label>Motivo do Desligamento<textarea name="termination_reason" rows="3" placeholder="Descreva o motivo do desligamento, se aplicável">' . h((string)($employee['termination_reason'] ?? '')) . '</textarea></label></div>';

echo '<div class="col12"><label>Observações Internas<textarea name="internal_notes" rows="3" placeholder="Anotações internas sobre o funcionário">' . h((string)($employee['internal_notes'] ?? '')) . '</textarea></label></div>';

echo '</div>';
echo '</div>';

// Botões de ação
echo '<div style="display:flex;gap:10px;justify-content:flex-end;padding-top:12px;border-top:2px solid hsl(var(--border))">';
echo '<a class="btn" href="/hr_employee_profile.php?id=' . $employeeId . '&tab=cadastro">← Anterior</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar Alterações</button>';
echo '<a class="btn" href="/hr_employee_profile.php?id=' . $employeeId . '&tab=beneficios">Próximo →</a>';
echo '</div>';

echo '</form>';
