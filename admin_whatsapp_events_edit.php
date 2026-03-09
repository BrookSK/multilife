<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

$eventId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$pageTitle = $eventId ? 'Editar Evento WhatsApp' : 'Criar Evento WhatsApp';

// Se editando, buscar dados do evento
$event = null;
$links = [];
$files = [];

if ($eventId) {
    $stmt = db()->prepare("SELECT * FROM whatsapp_events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    
    if (!$event) {
        header('Location: /admin_whatsapp_events.php');
        exit;
    }
    
    // Buscar links do evento
    $stmtLinks = db()->prepare("SELECT * FROM whatsapp_event_links WHERE event_id = ? ORDER BY id ASC");
    $stmtLinks->execute([$eventId]);
    $links = $stmtLinks->fetchAll();
    
    // Buscar arquivos do evento
    $stmtFiles = db()->prepare("SELECT * FROM whatsapp_event_files WHERE event_id = ? ORDER BY id ASC");
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
    '{{profissional_telefone}}' => 'Telefone do profissional',
    '{{paciente_nome}}' => 'Nome do paciente',
    '{{paciente_telefone}}' => 'Telefone do paciente',
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

require __DIR__ . '/app/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1"><?= h($pageTitle) ?></h2>
                    <p class="text-muted mb-0">Configure mensagens automáticas baseadas em eventos do sistema</p>
                </div>
                <a href="/admin_whatsapp_events.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Voltar
                </a>
            </div>
        </div>
    </div>

    <form id="eventForm" method="POST" action="/admin_whatsapp_events_save_post.php" enctype="multipart/form-data">
        <?php if ($eventId): ?>
            <input type="hidden" name="id" value="<?= h((string)$eventId) ?>">
        <?php endif; ?>
        
        <!-- Informações Básicas -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Informações Básicas</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="name" class="form-label">Nome do Evento *</label>
                                <input type="text" 
                                       class="form-control" 
                                       id="name" 
                                       name="name" 
                                       value="<?= h($event['name'] ?? '') ?>"
                                       required>
                                <small class="text-muted">Nome descritivo para identificar o evento</small>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label for="system_event" class="form-label">Evento do Sistema *</label>
                                <select class="form-select" id="system_event" name="system_event" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($systemEvents as $key => $label): ?>
                                        <option value="<?= h($key) ?>" 
                                                <?= ($event['system_event'] ?? '') === $key ? 'selected' : '' ?>>
                                            <?= h($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Qual evento do sistema dispara esta automação</small>
                            </div>
                            
                            <div class="col-md-2 mb-3">
                                <label for="status" class="form-label">Status *</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="active" <?= ($event['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>
                                        Ativo
                                    </option>
                                    <option value="inactive" <?= ($event['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>
                                        Inativo
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Destinatários -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-people me-2"></i>Destinatários</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">Selecione quem receberá a mensagem quando este evento ocorrer:</p>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" 
                                           type="checkbox" 
                                           id="send_to_professional" 
                                           name="send_to_professional" 
                                           value="1"
                                           <?= ($event['send_to_professional'] ?? 0) ? 'checked' : '' ?>
                                           onchange="toggleTemplateSection('professional', this.checked)">
                                    <label class="form-check-label" for="send_to_professional">
                                        <strong>Enviar para Profissional</strong>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" 
                                           type="checkbox" 
                                           id="send_to_patient" 
                                           name="send_to_patient" 
                                           value="1"
                                           <?= ($event['send_to_patient'] ?? 0) ? 'checked' : '' ?>
                                           onchange="toggleTemplateSection('patient', this.checked)">
                                    <label class="form-check-label" for="send_to_patient">
                                        <strong>Enviar para Paciente</strong>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Template Profissional -->
        <div class="row mb-4" id="template_professional_section" style="display: <?= ($event['send_to_professional'] ?? 0) ? 'block' : 'none' ?>">
            <div class="col-12">
                <div class="card shadow-sm border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-file-text me-2"></i>Template para Profissional</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="template_professional" class="form-label">Mensagem</label>
                            <textarea class="form-control font-monospace" 
                                      id="template_professional" 
                                      name="template_professional" 
                                      rows="8"
                                      placeholder="Digite a mensagem que será enviada ao profissional..."><?= h($event['template_professional'] ?? '') ?></textarea>
                            <small class="text-muted">Use as variáveis disponíveis abaixo para personalizar a mensagem</small>
                        </div>
                        
                        <div class="alert alert-info">
                            <strong><i class="bi bi-info-circle me-2"></i>Variáveis Disponíveis:</strong>
                            <div class="row mt-2">
                                <?php foreach ($availableVariables as $var => $desc): ?>
                                    <div class="col-md-4 mb-2">
                                        <code class="text-primary" style="cursor: pointer;" 
                                              onclick="insertVariable('template_professional', '<?= h($var) ?>')"
                                              title="Clique para inserir">
                                            <?= h($var) ?>
                                        </code>
                                        <small class="text-muted d-block"><?= h($desc) ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Template Paciente -->
        <div class="row mb-4" id="template_patient_section" style="display: <?= ($event['send_to_patient'] ?? 0) ? 'block' : 'none' ?>">
            <div class="col-12">
                <div class="card shadow-sm border-success">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-file-text me-2"></i>Template para Paciente</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="template_patient" class="form-label">Mensagem</label>
                            <textarea class="form-control font-monospace" 
                                      id="template_patient" 
                                      name="template_patient" 
                                      rows="8"
                                      placeholder="Digite a mensagem que será enviada ao paciente..."><?= h($event['template_patient'] ?? '') ?></textarea>
                            <small class="text-muted">Use as variáveis disponíveis abaixo para personalizar a mensagem</small>
                        </div>
                        
                        <div class="alert alert-info">
                            <strong><i class="bi bi-info-circle me-2"></i>Variáveis Disponíveis:</strong>
                            <div class="row mt-2">
                                <?php foreach ($availableVariables as $var => $desc): ?>
                                    <div class="col-md-4 mb-2">
                                        <code class="text-primary" style="cursor: pointer;" 
                                              onclick="insertVariable('template_patient', '<?= h($var) ?>')"
                                              title="Clique para inserir">
                                            <?= h($var) ?>
                                        </code>
                                        <small class="text-muted d-block"><?= h($desc) ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Links Adicionais -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-link-45deg me-2"></i>Links Adicionais</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">Adicione links que serão enviados junto com a mensagem:</p>
                        
                        <div id="links_container">
                            <?php if (!empty($links)): ?>
                                <?php foreach ($links as $index => $link): ?>
                                    <div class="row mb-3 link-item">
                                        <div class="col-md-4">
                                            <input type="text" 
                                                   class="form-control" 
                                                   name="link_name[]" 
                                                   placeholder="Nome do link"
                                                   value="<?= h($link['link_name']) ?>">
                                        </div>
                                        <div class="col-md-5">
                                            <input type="url" 
                                                   class="form-control" 
                                                   name="link_url[]" 
                                                   placeholder="https://..."
                                                   value="<?= h($link['link_url']) ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <select class="form-select" name="link_recipient[]">
                                                <option value="both" <?= $link['recipient_type'] === 'both' ? 'selected' : '' ?>>Ambos</option>
                                                <option value="professional" <?= $link['recipient_type'] === 'professional' ? 'selected' : '' ?>>Profissional</option>
                                                <option value="patient" <?= $link['recipient_type'] === 'patient' ? 'selected' : '' ?>>Paciente</option>
                                            </select>
                                        </div>
                                        <div class="col-md-1">
                                            <button type="button" class="btn btn-outline-danger" onclick="removeLink(this)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="addLink()">
                            <i class="bi bi-plus-circle me-2"></i>Adicionar Link
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Arquivos Anexos -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-paperclip me-2"></i>Arquivos Anexos</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">Anexe arquivos que serão enviados automaticamente com a mensagem:</p>
                        
                        <?php if (!empty($files)): ?>
                            <div class="mb-3">
                                <strong>Arquivos atuais:</strong>
                                <ul class="list-group mt-2">
                                    <?php foreach ($files as $file): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <i class="bi bi-file-earmark me-2"></i>
                                                <?= h($file['file_name']) ?>
                                                <small class="text-muted">(<?= h(number_format($file['file_size'] / 1024, 2)) ?> KB)</small>
                                                <span class="badge bg-secondary ms-2"><?= h(ucfirst($file['recipient_type'])) ?></span>
                                            </div>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-danger"
                                                    onclick="deleteFile(<?= h((string)$file['id']) ?>)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label for="new_files" class="form-label">Adicionar novos arquivos</label>
                            <input type="file" 
                                   class="form-control" 
                                   id="new_files" 
                                   name="new_files[]" 
                                   multiple
                                   accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif">
                            <small class="text-muted">Tipos suportados: PDF, DOC, DOCX, JPG, PNG, GIF</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="file_recipient" class="form-label">Enviar arquivos para</label>
                            <select class="form-select" id="file_recipient" name="file_recipient">
                                <option value="both">Ambos (Profissional e Paciente)</option>
                                <option value="professional">Apenas Profissional</option>
                                <option value="patient">Apenas Paciente</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Botões de Ação -->
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between">
                    <a href="/admin_whatsapp_events.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle me-2"></i>Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>Salvar Evento
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
function toggleTemplateSection(type, show) {
    const section = document.getElementById('template_' + type + '_section');
    if (section) {
        section.style.display = show ? 'block' : 'none';
    }
}

function insertVariable(textareaId, variable) {
    const textarea = document.getElementById(textareaId);
    if (!textarea) return;
    
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    
    textarea.value = text.substring(0, start) + variable + text.substring(end);
    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = start + variable.length;
}

function addLink() {
    const container = document.getElementById('links_container');
    const linkItem = document.createElement('div');
    linkItem.className = 'row mb-3 link-item';
    linkItem.innerHTML = `
        <div class="col-md-4">
            <input type="text" class="form-control" name="link_name[]" placeholder="Nome do link">
        </div>
        <div class="col-md-5">
            <input type="url" class="form-control" name="link_url[]" placeholder="https://...">
        </div>
        <div class="col-md-2">
            <select class="form-select" name="link_recipient[]">
                <option value="both">Ambos</option>
                <option value="professional">Profissional</option>
                <option value="patient">Paciente</option>
            </select>
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger" onclick="removeLink(this)">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;
    container.appendChild(linkItem);
}

function removeLink(button) {
    button.closest('.link-item').remove();
}

function deleteFile(fileId) {
    if (!confirm('Tem certeza que deseja excluir este arquivo?')) {
        return;
    }
    
    fetch('/admin_whatsapp_events_delete_file_post.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'file_id=' + fileId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Erro ao excluir arquivo: ' + (data.error || 'Erro desconhecido'));
        }
    })
    .catch(error => {
        alert('Erro ao excluir arquivo: ' + error.message);
    });
}

// Validação do formulário
document.getElementById('eventForm').addEventListener('submit', function(e) {
    const sendToProfessional = document.getElementById('send_to_professional').checked;
    const sendToPatient = document.getElementById('send_to_patient').checked;
    
    if (!sendToProfessional && !sendToPatient) {
        e.preventDefault();
        alert('Selecione pelo menos um destinatário (Profissional ou Paciente)');
        return false;
    }
    
    if (sendToProfessional && !document.getElementById('template_professional').value.trim()) {
        e.preventDefault();
        alert('Preencha o template para Profissional');
        return false;
    }
    
    if (sendToPatient && !document.getElementById('template_patient').value.trim()) {
        e.preventDefault();
        alert('Preencha o template para Paciente');
        return false;
    }
});
</script>

<?php require __DIR__ . '/app/footer.php'; ?>
