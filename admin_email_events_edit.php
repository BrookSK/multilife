<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('email.manage');

$eventId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$pageTitle = $eventId ? 'Editar Evento de E-mail' : 'Criar Evento de E-mail';

// Se editando, buscar dados do evento
$event = null;
$links = [];
$files = [];

if ($eventId) {
    $stmt = db()->prepare("SELECT * FROM email_events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    
    if (!$event) {
        header('Location: /admin_email_events.php');
        exit;
    }
    
    // Buscar links do evento
    $stmtLinks = db()->prepare("SELECT * FROM email_event_links WHERE event_id = ? ORDER BY id ASC");
    $stmtLinks->execute([$eventId]);
    $links = $stmtLinks->fetchAll();
    
    // Buscar arquivos do evento
    $stmtFiles = db()->prepare("SELECT * FROM email_event_files WHERE event_id = ? ORDER BY id ASC");
    $stmtFiles->execute([$eventId]);
    $files = $stmtFiles->fetchAll();
}

// Lista de eventos do sistema disponíveis
$systemEvents = [
    'attendance_assigned' => 'Atendimento atribuído ao profissional',
    'professional_received_attendance' => 'Profissional recebeu atendimento',
    'professional_form_delayed' => 'Profissional atrasou envio do formulário',
    'professional_form_submitted' => 'Profissional enviou formulário',
    'attendance_completed' => 'Atendimento finalizado',
    'preadmission_started' => 'Pré-admissão iniciada',
    'preadmission_approved' => 'Pré-admissão aprovada',
    'preadmission_pending' => 'Pré-admissão pendente',
    'patient_registered' => 'Paciente cadastrado',
    'appointment_scheduled' => 'Consulta agendada',
    'appointment_cancelled' => 'Consulta cancelada',
];

// Variáveis disponíveis para templates
$availableVariables = [
    '{{profissional_nome}}' => 'Nome do profissional',
    '{{profissional_email}}' => 'E-mail do profissional',
    '{{paciente_nome}}' => 'Nome do paciente',
    '{{paciente_email}}' => 'E-mail do paciente',
    '{{id_atendimento}}' => 'ID do atendimento',
    '{{data_atendimento}}' => 'Data do atendimento',
    '{{data_consulta}}' => 'Data da consulta',
    '{{horario_consulta}}' => 'Horário da consulta',
    '{{link_atendimento}}' => 'Link para o atendimento',
    '{{link_consulta}}' => 'Link para a consulta',
    '{{id_preadmissao}}' => 'ID da pré-admissão',
    '{{data_inicio}}' => 'Data de início',
    '{{data_aprovacao}}' => 'Data de aprovação',
    '{{data_prazo}}' => 'Data do prazo',
    '{{id_paciente}}' => 'ID do paciente',
    '{{data_cadastro}}' => 'Data de cadastro',
    '{{motivo_cancelamento}}' => 'Motivo do cancelamento',
];

view_header($pageTitle);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">' . h($pageTitle) . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Configure e-mails automáticos baseados em eventos do sistema</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_email_events.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<form id="eventForm" method="POST" action="/admin_email_events_save_post.php" enctype="multipart/form-data">';

if ($eventId) {
    echo '<input type="hidden" name="id" value="' . h((string)$eventId) . '">';
}

// Informações Básicas
echo '<section class="card col12">';
echo '<div class="cardTitle">Informações Básicas</div>';
echo '<div style="display:grid;gap:16px">';

echo '<label>Nome do Evento *';
echo '<input type="text" name="name" value="' . h($event['name'] ?? '') . '" required>';
echo '<span class="helpText">Nome descritivo para identificar o evento</span>';
echo '</label>';

echo '<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">';
echo '<label>Evento do Sistema *';
echo '<select name="system_event" required>';
echo '<option value="">Selecione...</option>';
foreach ($systemEvents as $key => $label) {
    $selected = ($event['system_event'] ?? '') === $key ? 'selected' : '';
    echo '<option value="' . h($key) . '" ' . $selected . '>' . h($label) . '</option>';
}
echo '</select>';
echo '<span class="helpText">Qual evento do sistema dispara esta automação</span>';
echo '</label>';

echo '<label>Status *';
echo '<select name="status" required>';
echo '<option value="active" ' . (($event['status'] ?? 'active') === 'active' ? 'selected' : '') . '>Ativo</option>';
echo '<option value="inactive" ' . (($event['status'] ?? '') === 'inactive' ? 'selected' : '') . '>Inativo</option>';
echo '</select>';
echo '</label>';
echo '</div>';

echo '</div>';
echo '</section>';

// Destinatários
echo '<section class="card col12">';
echo '<div class="cardTitle">Destinatários</div>';
echo '<div style="margin-bottom:12px;color:hsl(var(--muted-foreground))">Selecione quem receberá o e-mail quando este evento ocorrer:</div>';
echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">';

$sendToProfChecked = ($event['send_to_professional'] ?? 0) ? 'checked' : '';
$sendToPatChecked = ($event['send_to_patient'] ?? 0) ? 'checked' : '';

echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer">';
echo '<input type="checkbox" name="send_to_professional" value="1" ' . $sendToProfChecked . ' onchange="toggleTemplateSection(\'professional\', this.checked)">';
echo '<strong>Enviar para Profissional</strong>';
echo '</label>';

echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer">';
echo '<input type="checkbox" name="send_to_patient" value="1" ' . $sendToPatChecked . ' onchange="toggleTemplateSection(\'patient\', this.checked)">';
echo '<strong>Enviar para Paciente</strong>';
echo '</label>';

echo '</div>';
echo '</section>';

// Template Profissional
$displayProf = ($event['send_to_professional'] ?? 0) ? 'block' : 'none';
echo '<section class="card col12" id="template_professional_section" style="display:' . $displayProf . '">';
echo '<div class="cardTitle">Template para Profissional</div>';

echo '<label>Assunto do E-mail *';
echo '<input type="text" name="subject_professional" value="' . h($event['subject_professional'] ?? '') . '" placeholder="Ex: Novo Atendimento - {{paciente_nome}}">';
echo '<span class="helpText">Use variáveis para personalizar o assunto</span>';
echo '</label>';

echo '<label style="margin-top:16px">Mensagem HTML';
echo '<textarea name="template_professional_html" rows="12" style="font-family:monospace;font-size:13px" placeholder="<h2>Olá {{profissional_nome}},</h2>...">' . h($event['template_professional_html'] ?? '') . '</textarea>';
echo '<span class="helpText">Template em HTML para e-mails ricos</span>';
echo '</label>';

echo '<label style="margin-top:16px">Mensagem Texto Plano';
echo '<textarea name="template_professional_text" rows="8" placeholder="Olá {{profissional_nome}},...">' . h($event['template_professional_text'] ?? '') . '</textarea>';
echo '<span class="helpText">Versão em texto plano (fallback)</span>';
echo '</label>';

echo '<div style="margin-top:16px;padding:12px;background:hsla(var(--info)/.08);border-radius:8px">';
echo '<strong style="font-size:13px">💡 Variáveis Disponíveis:</strong>';
echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:8px;font-size:12px">';
foreach ($availableVariables as $var => $desc) {
    echo '<div>';
    echo '<code style="color:hsl(var(--primary));cursor:pointer" onclick="insertVariable(\'template_professional_html\', \'' . h($var) . '\')">' . h($var) . '</code>';
    echo '<div style="color:hsl(var(--muted-foreground));font-size:11px">' . h($desc) . '</div>';
    echo '</div>';
}
echo '</div>';
echo '</div>';

echo '</section>';

// Template Paciente
$displayPat = ($event['send_to_patient'] ?? 0) ? 'block' : 'none';
echo '<section class="card col12" id="template_patient_section" style="display:' . $displayPat . '">';
echo '<div class="cardTitle">Template para Paciente</div>';

echo '<label>Assunto do E-mail *';
echo '<input type="text" name="subject_patient" value="' . h($event['subject_patient'] ?? '') . '" placeholder="Ex: Consulta Agendada!">';
echo '<span class="helpText">Use variáveis para personalizar o assunto</span>';
echo '</label>';

echo '<label style="margin-top:16px">Mensagem HTML';
echo '<textarea name="template_patient_html" rows="12" style="font-family:monospace;font-size:13px" placeholder="<h2>Olá {{paciente_nome}},</h2>...">' . h($event['template_patient_html'] ?? '') . '</textarea>';
echo '<span class="helpText">Template em HTML para e-mails ricos</span>';
echo '</label>';

echo '<label style="margin-top:16px">Mensagem Texto Plano';
echo '<textarea name="template_patient_text" rows="8" placeholder="Olá {{paciente_nome}},...">' . h($event['template_patient_text'] ?? '') . '</textarea>';
echo '<span class="helpText">Versão em texto plano (fallback)</span>';
echo '</label>';

echo '<div style="margin-top:16px;padding:12px;background:hsla(var(--info)/.08);border-radius:8px">';
echo '<strong style="font-size:13px">💡 Variáveis Disponíveis:</strong>';
echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:8px;font-size:12px">';
foreach ($availableVariables as $var => $desc) {
    echo '<div>';
    echo '<code style="color:hsl(var(--primary));cursor:pointer" onclick="insertVariable(\'template_patient_html\', \'' . h($var) . '\')">' . h($var) . '</code>';
    echo '<div style="color:hsl(var(--muted-foreground));font-size:11px">' . h($desc) . '</div>';
    echo '</div>';
}
echo '</div>';
echo '</div>';

echo '</section>';

// Links Adicionais
echo '<section class="card col12">';
echo '<div class="cardTitle">Links Adicionais</div>';
echo '<div style="margin-bottom:12px;color:hsl(var(--muted-foreground))">Adicione links que serão enviados junto com o e-mail:</div>';

echo '<div id="links_container">';
if (!empty($links)) {
    foreach ($links as $link) {
        echo '<div class="link-item" style="display:grid;grid-template-columns:2fr 3fr 1fr auto;gap:12px;margin-bottom:12px">';
        echo '<input type="text" name="link_name[]" placeholder="Nome do link" value="' . h($link['link_name']) . '">';
        echo '<input type="url" name="link_url[]" placeholder="https://..." value="' . h($link['link_url']) . '">';
        echo '<select name="link_recipient[]">';
        echo '<option value="both" ' . ($link['recipient_type'] === 'both' ? 'selected' : '') . '>Ambos</option>';
        echo '<option value="professional" ' . ($link['recipient_type'] === 'professional' ? 'selected' : '') . '>Profissional</option>';
        echo '<option value="patient" ' . ($link['recipient_type'] === 'patient' ? 'selected' : '') . '>Paciente</option>';
        echo '</select>';
        echo '<button type="button" class="btn btnDanger btnSmall" onclick="removeLink(this)">Remover</button>';
        echo '</div>';
    }
}
echo '</div>';

echo '<button type="button" class="btn btnSmall" onclick="addLink()">+ Adicionar Link</button>';
echo '</section>';

// Arquivos Anexos
echo '<section class="card col12">';
echo '<div class="cardTitle">Arquivos Anexos</div>';

if (!empty($files)) {
    echo '<div style="margin-bottom:16px">';
    echo '<strong>Arquivos atuais:</strong>';
    echo '<div style="margin-top:8px;display:grid;gap:8px">';
    foreach ($files as $file) {
        echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border:1px solid hsl(var(--border));border-radius:8px">';
        echo '<div>';
        echo '<strong>' . h($file['file_name']) . '</strong>';
        echo ' <span style="color:hsl(var(--muted-foreground));font-size:12px">(' . h(number_format($file['file_size'] / 1024, 2)) . ' KB)</span>';
        echo ' <span class="badge badgeSmall">' . h(ucfirst($file['recipient_type'])) . '</span>';
        echo '</div>';
        echo '<button type="button" class="btn btnDanger btnSmall" onclick="deleteFile(' . (int)$file['id'] . ')">Excluir</button>';
        echo '</div>';
    }
    echo '</div>';
    echo '</div>';
}

echo '<label>Adicionar novos arquivos';
echo '<input type="file" name="new_files[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif">';
echo '<span class="helpText">Tipos suportados: PDF, DOC, DOCX, JPG, PNG, GIF</span>';
echo '</label>';

echo '<label style="margin-top:12px">Enviar arquivos para';
echo '<select name="file_recipient">';
echo '<option value="both">Ambos (Profissional e Paciente)</option>';
echo '<option value="professional">Apenas Profissional</option>';
echo '<option value="patient">Apenas Paciente</option>';
echo '</select>';
echo '</label>';

echo '</section>';

// Botões de Ação
echo '<section class="card col12">';
echo '<div style="display:flex;justify-content:space-between">';
echo '<a href="/admin_email_events.php" class="btn">Cancelar</a>';
echo '<button type="submit" class="btn btnPrimary">Salvar Evento</button>';
echo '</div>';
echo '</section>';

echo '</form>';
echo '</div>';

echo '<script>';
echo 'function toggleTemplateSection(type, show) {';
echo '  const section = document.getElementById("template_" + type + "_section");';
echo '  if (section) section.style.display = show ? "block" : "none";';
echo '}';

echo 'function insertVariable(textareaId, variable) {';
echo '  const textarea = document.getElementsByName(textareaId)[0];';
echo '  if (!textarea) return;';
echo '  const start = textarea.selectionStart;';
echo '  const end = textarea.selectionEnd;';
echo '  const text = textarea.value;';
echo '  textarea.value = text.substring(0, start) + variable + text.substring(end);';
echo '  textarea.focus();';
echo '  textarea.selectionStart = textarea.selectionEnd = start + variable.length;';
echo '}';

echo 'function addLink() {';
echo '  const container = document.getElementById("links_container");';
echo '  const linkItem = document.createElement("div");';
echo '  linkItem.className = "link-item";';
echo '  linkItem.style.cssText = "display:grid;grid-template-columns:2fr 3fr 1fr auto;gap:12px;margin-bottom:12px";';
echo '  linkItem.innerHTML = `';
echo '    <input type="text" name="link_name[]" placeholder="Nome do link">';
echo '    <input type="url" name="link_url[]" placeholder="https://...">'; 
echo '    <select name="link_recipient[]">';
echo '      <option value="both">Ambos</option>';
echo '      <option value="professional">Profissional</option>';
echo '      <option value="patient">Paciente</option>';
echo '    </select>';
echo '    <button type="button" class="btn btnDanger btnSmall" onclick="removeLink(this)">Remover</button>`;';
echo '  container.appendChild(linkItem);';
echo '}';

echo 'function removeLink(button) {';
echo '  button.closest(".link-item").remove();';
echo '}';

echo 'function deleteFile(fileId) {';
echo '  if (!confirm("Tem certeza que deseja excluir este arquivo?")) return;';
echo '  fetch("/admin_email_events_delete_file_post.php", {';
echo '    method: "POST",';
echo '    headers: {"Content-Type": "application/x-www-form-urlencoded"},';
echo '    body: "file_id=" + fileId';
echo '  })';
echo '  .then(response => response.json())';
echo '  .then(data => {';
echo '    if (data.success) window.location.reload();';
echo '    else alert("Erro ao excluir arquivo: " + (data.error || "Erro desconhecido"));';
echo '  })';
echo '  .catch(error => alert("Erro ao excluir arquivo: " + error.message));';
echo '}';

echo 'document.getElementById("eventForm").addEventListener("submit", function(e) {';
echo '  const sendToProfessional = document.getElementsByName("send_to_professional")[0].checked;';
echo '  const sendToPatient = document.getElementsByName("send_to_patient")[0].checked;';
echo '  if (!sendToProfessional && !sendToPatient) {';
echo '    e.preventDefault();';
echo '    alert("Selecione pelo menos um destinatário (Profissional ou Paciente)");';
echo '    return false;';
echo '  }';
echo '});';
echo '</script>';

view_footer();
