<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings');

require_once __DIR__ . '/app/whatsapp_template_processor.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;

$template = null;
$attachments = [];

if ($isEdit) {
    $stmt = db()->prepare('SELECT * FROM whatsapp_message_templates WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $template = $stmt->fetch();
    
    if (!$template) {
        flash_set('error', 'Template não encontrado.');
        header('Location: /admin_whatsapp_templates.php');
        exit;
    }
    
    $attachments = whatsapp_get_template_attachments($id);
}

$insurersStmt = db()->query('SELECT id, name FROM health_insurers WHERE is_active = 1 ORDER BY name');
$insurers = $insurersStmt->fetchAll();

$events = whatsapp_get_available_events();

view_header($isEdit ? 'Editar Template' : 'Novo Template');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">' . ($isEdit ? 'Editar Template' : 'Novo Template') . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Configure mensagem personalizada por operadora e evento</div>';
echo '</div>';
echo '<div>';
echo '<a class="btn" href="/admin_whatsapp_templates.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<form method="post" action="/admin_whatsapp_templates_save_post.php" enctype="multipart/form-data">';

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

echo '<label>Evento que Dispara *';
echo '<select name="event_trigger" id="eventTrigger" required>';
echo '<option value="">Selecione...</option>';
foreach ($events as $key => $label) {
    $sel = ($template && (string)$template['event_trigger'] === $key) ? ' selected' : '';
    echo '<option value="' . h($key) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';
echo '<span class="helpText">Qual evento do sistema dispara este template</span>';
echo '</label>';

echo '<label>Operadora';
echo '<select name="health_insurer_id" id="healthInsurerId">';
echo '<option value="">Todas as Operadoras</option>';
foreach ($insurers as $ins) {
    $sel = ($template && (int)$template['health_insurer_id'] === (int)$ins['id']) ? ' selected' : '';
    echo '<option value="' . (int)$ins['id'] . '"' . $sel . '>' . h((string)$ins['name']) . '</option>';
}
echo '</select>';
echo '<span class="helpText" id="insurerHelp">Obrigatório apenas para evento "Confirmação de Pré-Admissão"</span>';
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
echo '<div class="formSectionTitle">Mensagem</div>';

echo '<div style="padding:12px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--primary));border-radius:8px;margin-bottom:16px">';
echo '<div style="font-size:13px;color:hsl(var(--primary));line-height:1.6">';
echo '<strong>📝 Variáveis Disponíveis:</strong><br>';
echo '<code>{patient_name}</code> - Nome do paciente<br>';
echo '<code>{professional_name}</code> - Nome do profissional<br>';
echo '<code>{specialty}</code> - Especialidade<br>';
echo '<code>{date}</code> - Data do atendimento<br>';
echo '<code>{time}</code> - Hora do atendimento<br>';
echo '<code>{location}</code> - Local<br>';
echo '<code>{health_insurer}</code> - Nome da operadora<br>';
echo '<code>{value}</code> - Valor do procedimento<br>';
echo '<code>{documents_list}</code> - Lista de documentos';
echo '</div>';
echo '</div>';

echo '<label>Corpo da Mensagem *';
echo '<textarea name="message_body" rows="12" required style="font-family:monospace">' . h($template ? (string)$template['message_body'] : '') . '</textarea>';
echo '<span class="helpText">Use as variáveis acima para personalizar a mensagem</span>';
echo '</label>';

echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div class="formSection">';
echo '<div class="formSectionTitle">📎 Anexos (até 5 arquivos)</div>';

if ($isEdit && !empty($attachments)) {
    echo '<div style="margin-bottom:16px">';
    echo '<div style="font-weight:700;margin-bottom:8px">Arquivos Anexados:</div>';
    foreach ($attachments as $att) {
        $sizeKB = round((int)$att['file_size'] / 1024);
        echo '<div style="display:flex;align-items:center;gap:10px;padding:8px;background:hsl(var(--muted));border-radius:6px;margin-bottom:6px">';
        echo '<span>📄 ' . h((string)$att['file_name']) . ' (' . $sizeKB . ' KB)</span>';
        echo '<form method="post" action="/admin_whatsapp_templates_delete_attachment_post.php" style="margin-left:auto" onsubmit="return confirm(\'Confirma exclusão?\');">';
        echo '<input type="hidden" name="id" value="' . (int)$att['id'] . '">';
        echo '<input type="hidden" name="template_id" value="' . $id . '">';
        echo '<button class="btn btnSmall btnDanger" type="submit">Remover</button>';
        echo '</form>';
        echo '</div>';
    }
    echo '</div>';
}

$currentCount = count($attachments);
$remaining = 5 - $currentCount;

if ($remaining > 0) {
    echo '<div style="margin-bottom:12px">';
    echo '<div style="font-weight:700;margin-bottom:8px">Adicionar Novos Arquivos (' . $remaining . ' disponíveis):</div>';
    
    for ($i = 1; $i <= $remaining; $i++) {
        echo '<label>Arquivo ' . $i;
        echo '<input type="file" name="attachments[]" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt">';
        echo '</label>';
    }
    echo '</div>';
}

echo '<div style="padding:12px;background:hsla(var(--warning)/.1);border:1px solid hsl(var(--warning));border-radius:8px">';
echo '<div style="font-size:13px;color:hsl(var(--warning));line-height:1.6">';
echo '<strong>⚠️ Restrições:</strong><br>';
echo '• Máximo 5 arquivos por template<br>';
echo '• Tamanho máximo: 10MB por arquivo<br>';
echo '• Tipos aceitos: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, TXT';
echo '</div>';
echo '</div>';

echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
echo '<a class="btn" href="/admin_whatsapp_templates.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">' . ($isEdit ? 'Salvar Alterações' : 'Criar Template') . '</button>';
echo '</div>';
echo '</section>';

echo '</form>';

echo '</div>';

echo '<script>
const eventTrigger = document.getElementById("eventTrigger");
const healthInsurerId = document.getElementById("healthInsurerId");
const insurerHelp = document.getElementById("insurerHelp");

function updateInsurerRequirement() {
    const requiresInsurer = eventTrigger.value === "pre_admission_confirmation";
    
    if (requiresInsurer) {
        healthInsurerId.required = true;
        insurerHelp.innerHTML = "⚠️ <strong>Obrigatório</strong> para este evento";
        insurerHelp.style.color = "hsl(var(--destructive))";
    } else {
        healthInsurerId.required = false;
        insurerHelp.innerHTML = "Opcional para este evento";
        insurerHelp.style.color = "";
    }
}

eventTrigger.addEventListener("change", updateInsurerRequirement);
updateInsurerRequirement();
</script>';

view_footer();
