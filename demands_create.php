<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

// Garante colunas do refactor cliente/operadora + location_raw.
if (function_exists('clients_ensure_schema')) {
    try { clients_ensure_schema(); } catch (Throwable $e) {}
}
try { db()->exec("ALTER TABLE demands ADD COLUMN location_raw TEXT NULL"); } catch (Throwable $e) { /* já existe */ }

// Buscar especialidades
$specialtiesStmt = db()->query("SELECT id, name FROM specialties WHERE status = 'active' ORDER BY name ASC");
$specialties = $specialtiesStmt->fetchAll();

// Clientes (contratantes) e operadoras (convênios) para classificar o card.
$clients = function_exists('clients_list') ? clients_list(true) : [];
$operators = function_exists('operators_list') ? operators_list(null, true) : [];

view_header('Novo card');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Novo card de demanda</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Registro manual de captação. Informe os dados essenciais do atendimento. O endereço é um campo único — a separação é feita depois.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/demands_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/demands_create_post.php" style="display:grid;gap:12px;max-width:820px">';

// Identificação
echo '<div class="grid">';
echo '<div class="col6"><label>Nome do paciente *<input name="patient_name" required maxlength="200" placeholder="Ex: João da Silva"></label></div>';
echo '<div class="col6"><label>Título do card<input name="title" maxlength="200" placeholder="Opcional — gerado do paciente + especialidade"></label></div>';
echo '</div>';

// Cliente / Operadora
echo '<div class="grid">';
echo '<div class="col6"><label>Cliente (contratante)<select name="client_id">';
echo '<option value="">— Selecione —</option>';
foreach ($clients as $cl) {
    echo '<option value="' . (int)$cl['id'] . '">' . h((string)$cl['name']) . '</option>';
}
echo '</select></label></div>';
echo '<div class="col6"><label>Operadora / Convênio<select name="health_insurer_id">';
echo '<option value="">— Selecione —</option>';
foreach ($operators as $op) {
    $label = (string)$op['name'];
    if (!empty($op['client_name'])) {
        $label .= ' (' . (string)$op['client_name'] . ')';
    }
    echo '<option value="' . (int)$op['id'] . '">' . h($label) . '</option>';
}
echo '</select></label></div>';
echo '</div>';

// Serviço
echo '<div class="grid">';
echo '<div class="col6"><label>Especialidade *<select name="specialty" required>';
echo '<option value="">Selecione...</option>';
foreach ($specialties as $spec) {
    echo '<option value="' . h((string)$spec['name']) . '">' . h((string)$spec['name']) . '</option>';
}
echo '</select></label></div>';
echo '<div class="col6"><label>Frequência<select name="frequency">';
echo '<option value="">Selecione...</option>';
$freqOpts = function_exists('frequency_get_options') ? frequency_get_options() : [];
foreach ($freqOpts as $fo) {
    echo '<option value="' . h($fo['code']) . '">' . h($fo['label']) . ' — ' . h($fo['description']) . '</option>';
}
echo '</select></label></div>';
echo '<div class="col6"><label>Urgência<select name="urgency">';
echo '<option value="">Normal</option>';
echo '<option value="urgente">Urgente</option>';
echo '<option value="normal">Normal</option>';
echo '<option value="baixa">Baixa</option>';
echo '</select></label></div>';
echo '<div class="col6"><label>Valor do procedimento (R$)<input type="number" name="procedure_value" step="0.01" min="0" placeholder="Ex: 150.00"></label></div>';
echo '</div>';

// Endereço ÚNICO
echo '<label>Endereço (campo único)<textarea name="location_raw" rows="2" placeholder="Rua, número, bairro, cidade/UF, complemento — tudo em um campo"></textarea></label>';

// Origem e observações
echo '<div class="grid">';
echo '<div class="col6"><label>Origem (e-mail)<input type="email" name="origin_email" maxlength="190" placeholder="origem@empresa.com"></label></div>';
echo '<div class="col6"><label>Status inicial<select name="status">';
echo '<option value="aguardando_captacao">Aguardando captação</option>';
echo '<option value="tratamento_manual">Tratamento manual</option>';
echo '</select></label></div>';
echo '</div>';

echo '<label>Observações<textarea name="description" rows="4" placeholder="Detalhes adicionais do atendimento..."></textarea></label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/demands_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';

echo '</form>';

echo '</div>';

view_footer();
