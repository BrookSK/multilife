<?php
// Aba de Benefícios

$employeeId = (int)$employee['id'];

echo '<form method="post" action="/hr_employee_save_beneficios_post.php" style="display:grid;gap:24px">';
echo '<input type="hidden" name="employee_id" value="' . $employeeId . '">';

echo '<div>';
echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid hsl(var(--border))">Benefícios do Funcionário</h3>';
echo '<div class="grid" style="gap:16px">';

// Vale Transporte
echo '<div class="col12" style="padding:16px;background:hsl(var(--muted));border-radius:8px">';
echo '<label style="display:flex;align-items:center;gap:12px;cursor:pointer">';
$checked = !empty($employee['benefit_transport']) ? ' checked' : '';
echo '<input type="checkbox" name="benefit_transport" value="1"' . $checked . ' style="width:20px;height:20px">';
echo '<div>';
echo '<div style="font-weight:700;font-size:16px">Vale Transporte</div>';
echo '<div style="font-size:14px;color:hsl(var(--muted-foreground));margin-top:4px">Funcionário recebe vale transporte</div>';
echo '</div>';
echo '</label>';
echo '</div>';

// Vale Alimentação
echo '<div class="col6" style="padding:16px;background:hsl(var(--muted));border-radius:8px">';
echo '<div style="font-weight:700;font-size:16px;margin-bottom:8px">💳 Vale Alimentação</div>';
echo '<label>Valor Mensal (R$)<input type="number" step="0.01" min="0" name="benefit_food_value" value="' . h((string)($employee['benefit_food_value'] ?? '')) . '" placeholder="0.00"></label>';
echo '</div>';

// Vale Refeição
echo '<div class="col6" style="padding:16px;background:hsl(var(--muted));border-radius:8px">';
echo '<div style="font-weight:700;font-size:16px;margin-bottom:8px">🍽️ Vale Refeição</div>';
echo '<label>Valor Mensal (R$)<input type="number" step="0.01" min="0" name="benefit_meal_value" value="' . h((string)($employee['benefit_meal_value'] ?? '')) . '" placeholder="0.00"></label>';
echo '</div>';

// Plano de Saúde
echo '<div class="col6" style="padding:16px;background:hsl(var(--muted));border-radius:8px">';
echo '<label style="display:flex;align-items:center;gap:12px;cursor:pointer">';
$checked = !empty($employee['benefit_health_plan']) ? ' checked' : '';
echo '<input type="checkbox" name="benefit_health_plan" value="1"' . $checked . ' style="width:20px;height:20px">';
echo '<div>';
echo '<div style="font-weight:700;font-size:16px">🏥 Plano de Saúde</div>';
echo '<div style="font-size:14px;color:hsl(var(--muted-foreground));margin-top:4px">Funcionário possui plano de saúde</div>';
echo '</div>';
echo '</label>';
echo '</div>';

// Plano Odontológico
echo '<div class="col6" style="padding:16px;background:hsl(var(--muted));border-radius:8px">';
echo '<label style="display:flex;align-items:center;gap:12px;cursor:pointer">';
$checked = !empty($employee['benefit_dental_plan']) ? ' checked' : '';
echo '<input type="checkbox" name="benefit_dental_plan" value="1"' . $checked . ' style="width:20px;height:20px">';
echo '<div>';
echo '<div style="font-weight:700;font-size:16px">🦷 Plano Odontológico</div>';
echo '<div style="font-size:14px;color:hsl(var(--muted-foreground));margin-top:4px">Funcionário possui plano odontológico</div>';
echo '</div>';
echo '</label>';
echo '</div>';

// Outros Benefícios
echo '<div class="col12">';
echo '<label>Outros Benefícios<textarea name="benefit_other" rows="4" placeholder="Descreva outros benefícios concedidos (ex: auxílio home office, gympass, etc.)">' . h((string)($employee['benefit_other'] ?? '')) . '</textarea></label>';
echo '</div>';

echo '</div>';
echo '</div>';

// Resumo Financeiro
$totalBeneficios = 0;
if (!empty($employee['benefit_food_value'])) $totalBeneficios += (float)$employee['benefit_food_value'];
if (!empty($employee['benefit_meal_value'])) $totalBeneficios += (float)$employee['benefit_meal_value'];

if ($totalBeneficios > 0) {
    echo '<div style="padding:20px;background:linear-gradient(135deg, hsl(var(--primary)) 0%, hsl(var(--primary)/0.8) 100%);border-radius:12px;color:white">';
    echo '<div style="font-size:14px;opacity:0.9;margin-bottom:4px">Total de Benefícios Mensais</div>';
    echo '<div style="font-size:32px;font-weight:900">R$ ' . number_format($totalBeneficios, 2, ',', '.') . '</div>';
    echo '</div>';
}

// Botões de ação
echo '<div style="display:flex;gap:10px;justify-content:flex-end;padding-top:12px;border-top:2px solid hsl(var(--border))">';
echo '<a class="btn" href="/hr_employee_profile.php?id=' . $employeeId . '&tab=contrato">← Anterior</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar Alterações</button>';
echo '<a class="btn" href="/hr_employee_profile.php?id=' . $employeeId . '&tab=dependentes">Próximo →</a>';
echo '</div>';

echo '</form>';
