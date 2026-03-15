<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings');

require_once __DIR__ . '/app/email_template_processor.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;

$template = null;

if ($isEdit) {
    $stmt = db()->prepare('SELECT * FROM email_templates WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $template = $stmt->fetch();
    
    if (!$template) {
        flash_set('error', 'Template não encontrado.');
        header('Location: /admin_email_templates.php');
        exit;
    }
}

$insurersStmt = db()->query('SELECT id, name FROM health_insurers WHERE is_active = 1 ORDER BY name');
$insurers = $insurersStmt->fetchAll();

$events = email_get_available_event_types();

// Obter variáveis disponíveis para o evento selecionado
$selectedEvent = $template ? (string)$template['event_type'] : 'proposal_send';
$availableVars = email_get_available_variables($selectedEvent);

view_header($isEdit ? 'Editar Template de E-mail' : 'Novo Template de E-mail');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">' . ($isEdit ? 'Editar Template' : 'Novo Template') . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Configure template HTML personalizado para envio de e-mails</div>';
echo '</div>';
echo '<div>';
echo '<a class="btn" href="/admin_email_templates.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<form method="post" action="/admin_email_templates_save_post.php">';

if ($isEdit) {
    echo '<input type="hidden" name="id" value="' . $id . '">';
}

echo '<section class="card col12">';
echo '<div class="formSection">';
echo '<div class="formSectionTitle">Informações Básicas</div>';

echo '<label>Nome do Template *';
echo '<input name="name" value="' . h($template ? (string)$template['name'] : '') . '" required>';
echo '<span class="helpText">Nome identificador do template</span>';
echo '</label>';

echo '<label>Tipo de Evento *';
echo '<select name="event_type" id="eventType" required>';
echo '<option value="">Selecione...</option>';
foreach ($events as $key => $label) {
    $sel = ($template && (string)$template['event_type'] === $key) ? ' selected' : '';
    echo '<option value="' . h($key) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';
echo '<span class="helpText">Qual tipo de e-mail este template representa</span>';
echo '</label>';

echo '<label>Operadora';
echo '<select name="health_insurer_id">';
echo '<option value="">Todas as Operadoras</option>';
foreach ($insurers as $ins) {
    $sel = ($template && (int)$template['health_insurer_id'] === (int)$ins['id']) ? ' selected' : '';
    echo '<option value="' . (int)$ins['id'] . '"' . $sel . '>' . h((string)$ins['name']) . '</option>';
}
echo '</select>';
echo '<span class="helpText">Deixe vazio para usar como template padrão para todas as operadoras</span>';
echo '</label>';

echo '<label>Status';
echo '<select name="is_active">';
echo '<option value="1"' . ($template && (int)$template['is_active'] === 1 ? ' selected' : '') . '>Ativo</option>';
echo '<option value="0"' . ($template && (int)$template['is_active'] === 0 ? ' selected' : '') . '>Inativo</option>';
echo '</select>';
echo '</label>';

echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div class="formSection">';
echo '<div class="formSectionTitle">📧 Conteúdo do E-mail</div>';

echo '<div style="padding:12px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--primary));border-radius:8px;margin-bottom:16px">';
echo '<div style="font-size:13px;color:hsl(var(--primary));line-height:1.6">';
echo '<strong>📝 Variáveis Disponíveis (use no assunto e corpo):</strong><br>';
echo '<div id="variablesList" style="margin-top:8px;display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:6px">';
foreach ($availableVars as $varKey => $varDesc) {
    echo '<div style="background:white;padding:6px 10px;border-radius:4px;font-size:12px">';
    echo '<code style="color:#dc2626;font-weight:bold">{' . h($varKey) . '}</code><br>';
    echo '<span style="color:#6b7280">' . h($varDesc) . '</span>';
    echo '</div>';
}
echo '</div>';
echo '</div>';
echo '</div>';

echo '<label>Assunto do E-mail *';
echo '<input name="subject" value="' . h($template ? (string)$template['subject'] : '') . '" required placeholder="Ex: Proposta de Atendimento - {patient_name}">';
echo '<span class="helpText">Use as variáveis acima para personalizar o assunto</span>';
echo '</label>';

echo '<label>Corpo do E-mail (HTML) *';
echo '<textarea name="body_html" rows="20" required style="font-family:monospace;font-size:12px">' . h($template ? (string)$template['body_html'] : '') . '</textarea>';
echo '<span class="helpText">Cole seu HTML completo aqui. Use as variáveis acima para personalização.</span>';
echo '</label>';

echo '<label>Corpo do E-mail (Texto Plano - Fallback)';
echo '<textarea name="body_plain" rows="10" style="font-family:monospace">' . h($template ? (string)$template['body_plain'] : '') . '</textarea>';
echo '<span class="helpText">Versão texto plano para clientes que não suportam HTML (opcional)</span>';
echo '</label>';

echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
echo '<a class="btn" href="/admin_email_templates.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">' . ($isEdit ? 'Salvar Alterações' : 'Criar Template') . '</button>';
echo '</div>';
echo '</section>';

echo '</form>';

echo '</div>';

// JavaScript para atualizar variáveis disponíveis ao mudar evento
echo '<script>
const eventType = document.getElementById("eventType");
const variablesList = document.getElementById("variablesList");

const eventVariables = ' . json_encode(array_map(fn($e) => email_get_available_variables($e), array_keys($events))) . ';
const eventKeys = ' . json_encode(array_keys($events)) . ';

eventType.addEventListener("change", function() {
    const selectedEvent = this.value;
    const eventIndex = eventKeys.indexOf(selectedEvent);
    
    if (eventIndex === -1) {
        return;
    }
    
    const vars = eventVariables[eventIndex];
    let html = "";
    
    for (const [key, desc] of Object.entries(vars)) {
        html += `<div style="background:white;padding:6px 10px;border-radius:4px;font-size:12px">`;
        html += `<code style="color:#dc2626;font-weight:bold">{${key}}</code><br>`;
        html += `<span style="color:#6b7280">${desc}</span>`;
        html += `</div>`;
    }
    
    variablesList.innerHTML = html;
});
</script>';

view_footer();
