<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

$pageTitle = 'Eventos WhatsApp';

// Buscar todos os eventos
$stmt = db()->query("
    SELECT 
        id,
        name,
        system_event,
        status,
        send_to_professional,
        send_to_patient,
        created_at,
        updated_at
    FROM whatsapp_events
    ORDER BY name ASC
");
$events = $stmt->fetchAll();

// Mapear tipos de destinatários
function getRecipientType($sendToProfessional, $sendToPatient): string {
    if ($sendToProfessional && $sendToPatient) {
        return 'Ambos';
    } elseif ($sendToProfessional) {
        return 'Profissional';
    } elseif ($sendToPatient) {
        return 'Paciente';
    }
    return 'Nenhum';
}

view_header('Eventos WhatsApp');
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Eventos WhatsApp</h2>
                    <p class="text-muted mb-0">Gerencie mensagens automáticas baseadas em eventos do sistema</p>
                </div>
                <a href="/admin_whatsapp_events_edit.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-2"></i>Criar Evento
                </a>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <?php if (empty($events)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
                            <p class="text-muted mt-3 mb-0">Nenhum evento configurado</p>
                            <a href="/admin_whatsapp_events_edit.php" class="btn btn-sm btn-primary mt-3">
                                Criar primeiro evento
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Evento</th>
                                        <th>Evento do Sistema</th>
                                        <th>Destinatário</th>
                                        <th>Status</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($events as $event): ?>
                                        <tr>
                                            <td>
                                                <strong><?= h($event['name']) ?></strong>
                                            </td>
                                            <td>
                                                <code class="text-muted"><?= h($event['system_event']) ?></code>
                                            </td>
                                            <td>
                                                <?php
                                                $recipientType = getRecipientType(
                                                    (bool)$event['send_to_professional'],
                                                    (bool)$event['send_to_patient']
                                                );
                                                $badgeClass = match($recipientType) {
                                                    'Ambos' => 'bg-info',
                                                    'Profissional' => 'bg-primary',
                                                    'Paciente' => 'bg-success',
                                                    default => 'bg-secondary'
                                                };
                                                ?>
                                                <span class="badge <?= $badgeClass ?>"><?= h($recipientType) ?></span>
                                            </td>
                                            <td>
                                                <?php if ($event['status'] === 'active'): ?>
                                                    <span class="badge bg-success">Ativo</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inativo</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <a href="/admin_whatsapp_events_edit.php?id=<?= h((string)$event['id']) ?>" 
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-pencil"></i> Editar
                                                </a>
                                                <button type="button" 
                                                        class="btn btn-sm btn-outline-danger"
                                                        onclick="deleteEvent(<?= h((string)$event['id']) ?>, '<?= h($event['name']) ?>')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function deleteEvent(eventId, eventName) {
    if (!confirm(`Tem certeza que deseja excluir o evento "${eventName}"?\n\nEsta ação não pode ser desfeita.`)) {
        return;
    }
    
    fetch('/admin_whatsapp_events_delete_post.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'id=' + eventId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Erro ao excluir evento: ' + (data.error || 'Erro desconhecido'));
        }
    })
    .catch(error => {
        alert('Erro ao excluir evento: ' + error.message);
    });
}
</script>

<?php view_footer(); ?>
