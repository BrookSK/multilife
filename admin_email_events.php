<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('email.manage');

$pageTitle = 'Eventos de E-mail';

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
    FROM email_events
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

view_header('Eventos de E-mail');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Eventos de E-mail</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Gerencie e-mails automáticos baseados em eventos do sistema</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_settings.php">Voltar</a>';
echo '<a class="btn btnPrimary" href="/admin_email_events_edit.php">+ Criar Evento</a>';
echo '</div>';
echo '</div>';
echo '</section>';

if (empty($events)) {
    echo '<section class="card col12">';
    echo '<div style="padding:60px 20px;text-align:center">';
    echo '<div style="font-size:48px;margin-bottom:16px">📧</div>';
    echo '<div style="font-size:18px;font-weight:600;margin-bottom:8px">Nenhum evento configurado</div>';
    echo '<div style="color:hsl(var(--muted-foreground));margin-bottom:20px">Crie seu primeiro evento de e-mail</div>';
    echo '<a href="/admin_email_events_edit.php" class="btn btnPrimary">Criar Evento</a>';
    echo '</div>';
    echo '</section>';
} else {
    echo '<section class="card col12">';
    echo '<table class="dataTable">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Evento</th>';
    echo '<th>Evento do Sistema</th>';
    echo '<th>Destinatário</th>';
    echo '<th>Status</th>';
    echo '<th style="text-align:right">Ações</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    foreach ($events as $event) {
        $recipientType = getRecipientType(
            (bool)$event['send_to_professional'],
            (bool)$event['send_to_patient']
        );
        
        $badgeClass = match($recipientType) {
            'Ambos' => 'badge badgeInfo',
            'Profissional' => 'badge badgePrimary',
            'Paciente' => 'badge badgeSuccess',
            default => 'badge'
        };
        
        $statusBadge = $event['status'] === 'active' 
            ? '<span class="badge badgeSuccess">Ativo</span>' 
            : '<span class="badge">Inativo</span>';
        
        echo '<tr>';
        echo '<td><strong>' . h($event['name']) . '</strong></td>';
        echo '<td><code style="font-size:12px;color:hsl(var(--muted-foreground))">' . h($event['system_event']) . '</code></td>';
        echo '<td><span class="' . $badgeClass . '">' . h($recipientType) . '</span></td>';
        echo '<td>' . $statusBadge . '</td>';
        echo '<td style="text-align:right">';
        echo '<a href="/admin_email_events_edit.php?id=' . (int)$event['id'] . '" class="btn btnSmall">Editar</a> ';
        echo '<button type="button" class="btn btnSmall btnDanger" onclick="deleteEvent(' . (int)$event['id'] . ', \'' . h($event['name']) . '\')">Excluir</button>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '</section>';
}

echo '</div>';

echo '<script>';
echo 'function deleteEvent(eventId, eventName) {';
echo '  if (!confirm("Tem certeza que deseja excluir o evento \\"" + eventName + "\\"?\\n\\nEsta ação não pode ser desfeita.")) return;';
echo '  fetch("/admin_email_events_delete_post.php", {';
echo '    method: "POST",';
echo '    headers: {"Content-Type": "application/x-www-form-urlencoded"},';
echo '    body: "id=" + eventId';
echo '  })';
echo '  .then(response => response.json())';
echo '  .then(data => {';
echo '    if (data.success) {';
echo '      window.location.reload();';
echo '    } else {';
echo '      alert("Erro ao excluir evento: " + (data.error || "Erro desconhecido"));';
echo '    }';
echo '  })';
echo '  .catch(error => alert("Erro ao excluir evento: " + error.message));';
echo '}';
echo '</script>';

view_footer();
