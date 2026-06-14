<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$assignmentId = (int)($_GET['assignment_id'] ?? 0);

$stmt = db()->prepare(
    "SELECT pa.*, p.full_name as patient_name, p.id as patient_id, p.whatsapp as patient_phone,
            u.name as professional_name, u.phone as professional_phone, d.specialty
     FROM patient_assignments pa
     INNER JOIN patients p ON p.id = pa.patient_id
     LEFT JOIN users u ON u.id = pa.professional_user_id
     LEFT JOIN demands d ON d.id = pa.demand_id
     WHERE pa.id = :id"
);
$stmt->execute(['id' => $assignmentId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    flash_set('error', 'Atendimento não encontrado.');
    header('Location: /monitoramento.php');
    exit;
}

// Buscar profissionais disponíveis (ativos, com role profissional)
$stmtProfs = db()->prepare(
    "SELECT u.id, u.name, u.phone, u.email
     FROM users u
     INNER JOIN user_roles ur ON ur.user_id = u.id
     INNER JOIN roles r ON r.id = ur.role_id
     WHERE u.status = 'active' AND r.slug = 'profissional'
     AND u.id != :current_id
     ORDER BY u.name ASC"
);
$stmtProfs->execute(['current_id' => (int)($assignment['professional_user_id'] ?? 0)]);
$professionals = $stmtProfs->fetchAll();

// Buscar profissionais interessados via reação no WhatsApp (lista de espera)
$interestedProfessionals = [];
$demandId = (int)($assignment['demand_id'] ?? 0);
if ($demandId > 0) {
    require_once __DIR__ . '/app/demand_captation_handler.php';
    $interestedProfessionals = demand_get_interested_professionals($demandId);
}

// Buscar outros atendimentos do mesmo paciente com o mesmo profissional
$stmtOthers = db()->prepare(
    "SELECT pa.id, pa.specialty, pa.service_type, pa.session_frequency
     FROM patient_assignments pa
     WHERE pa.patient_id = :pid AND pa.professional_user_id = :uid
     AND pa.status IN ('admitted','awaiting_documents','awaiting_financial_approval','completed','confirmed','approved')
     AND pa.id != :aid
     ORDER BY pa.created_at DESC"
);
$stmtOthers->execute([
    'pid' => (int)$assignment['patient_id'],
    'uid' => (int)($assignment['professional_user_id'] ?? 0),
    'aid' => $assignmentId,
]);
$otherAssignments = $stmtOthers->fetchAll();

// Histórico de substituições
$stmtHist = db()->prepare(
    "SELECT ps.*, u1.name as old_prof_name, u2.name as new_prof_name, u3.name as changed_by_name
     FROM patient_professional_substitutions ps
     LEFT JOIN users u1 ON u1.id = ps.old_professional_user_id
     LEFT JOIN users u2 ON u2.id = ps.new_professional_user_id
     LEFT JOIN users u3 ON u3.id = ps.changed_by_user_id
     WHERE ps.assignment_id = :aid
     ORDER BY ps.created_at DESC LIMIT 20"
);
$stmtHist->execute(['aid' => $assignmentId]);
$history = $stmtHist->fetchAll();

view_header('Substituição de Profissional');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">🔄 Substituição de Profissional</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Trocar o profissional responsável pelo atendimento</div>';
echo '</div>';
echo '<a class="btn" href="/monitoramento.php">Voltar</a>';
echo '</div>';
echo '</section>';

// Info do atendimento
echo '<section class="card col12">';
echo '<div class="grid">';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Paciente:</strong> ' . h((string)$assignment['patient_name']) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Profissional atual:</strong> ' . h((string)($assignment['professional_name'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Especialidade:</strong> ' . h((string)($assignment['specialty'] ?? $assignment['service_type'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Frequência:</strong> ' . h((string)($assignment['session_frequency'] ?? '-')) . '</div></div>';
echo '</div>';
echo '</section>';

// Formulário
echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:12px">Novo Profissional</div>';

// Mostrar profissionais que demonstraram interesse (reagiram no grupo)
if (!empty($interestedProfessionals)) {
    $availableInterested = array_filter($interestedProfessionals, fn($p) => $p['status'] === 'interested');
    if (!empty($availableInterested)) {
        echo '<div style="padding:12px;background:hsla(142,76%,36%,.08);border:1px solid hsl(142,76%,36%);border-radius:8px;margin-bottom:16px">';
        echo '<div style="font-weight:700;margin-bottom:8px;color:hsl(142,76%,36%)">💬 Profissionais que demonstraram interesse (via WhatsApp)</div>';
        echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:8px">Estes profissionais reagiram à mensagem de captação no grupo e estão disponíveis:</div>';
        echo '<div style="display:grid;gap:6px">';
        foreach ($availableInterested as $ip) {
            $ipName = $ip['user_name'] ?? $ip['push_name'] ?? $ip['phone'];
            $ipDate = $ip['reacted_at'] ? date('d/m H:i', strtotime($ip['reacted_at'])) : '';
            $ipEmoji = $ip['emoji'] ?? '';
            $ipUserId = $ip['user_id'] ?? '';
            echo '<div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:#fff;border-radius:6px;border:1px solid hsl(var(--border))">';
            echo '<span style="font-size:18px">' . h($ipEmoji) . '</span>';
            echo '<div style="flex:1"><strong>' . h($ipName) . '</strong>';
            if ($ip['phone']) echo ' <span style="color:hsl(var(--muted-foreground));font-size:12px">(' . h($ip['phone']) . ')</span>';
            echo '<div style="font-size:11px;color:hsl(var(--muted-foreground))">Reagiu em ' . h($ipDate) . '</div>';
            echo '</div>';
            if ($ipUserId) {
                echo '<button type="button" onclick="document.querySelector(\'select[name=new_professional_id]\').value=\'' . (int)$ipUserId . '\'" class="btn" style="padding:4px 10px;font-size:12px;background:#06cf9c;color:#fff;border:none">Selecionar</button>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }
}

echo '<form method="post" action="/monitoramento_substituicao_post.php" style="display:grid;gap:12px">';
echo '<input type="hidden" name="assignment_id" value="' . $assignmentId . '">';

echo '<div class="grid">';
echo '<div class="col6"><label>Selecionar novo profissional<select name="new_professional_id" required>';
echo '<option value="">— Selecione —</option>';
foreach ($professionals as $prof) {
    echo '<option value="' . (int)$prof['id'] . '">' . h((string)$prof['name']) . ' (' . h((string)($prof['phone'] ?? '')) . ')</option>';
}
echo '</select></label></div>';
echo '<div class="col6"><label>Motivo da substituição<select name="reason_type" required>';
echo '<option value="">— Selecione —</option>';
echo '<option value="ferias">Férias do profissional</option>';
echo '<option value="desligamento">Desligamento do profissional</option>';
echo '<option value="pedido_paciente">Pedido do paciente/família</option>';
echo '<option value="indisponibilidade">Indisponibilidade de horário</option>';
echo '<option value="mudanca_regiao">Mudança de região</option>';
echo '<option value="outro">Outro</option>';
echo '</select></label></div>';
echo '<div class="col12"><label>Detalhes/Observações<textarea name="reason_details" rows="3" placeholder="Detalhes adicionais sobre a substituição..."></textarea></label></div>';
echo '</div>';

// Notificações
echo '<div style="padding:12px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--border));border-radius:8px">';
echo '<div style="font-weight:700;margin-bottom:8px">Notificações</div>';
echo '<div style="display:grid;gap:6px">';
echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="notify_patient" value="1" checked> Notificar paciente/família</label>';
echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="notify_old_professional" value="1" checked> Notificar profissional atual</label>';
echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="notify_new_professional" value="1" checked> Notificar novo profissional</label>';
echo '</div>';
echo '</div>';

// Opção de aplicar a todos
if (count($otherAssignments) > 0) {
    echo '<div style="padding:12px;background:hsla(var(--warning)/.1);border:1px solid hsl(var(--border));border-radius:8px">';
    echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer">';
    echo '<input type="checkbox" name="apply_to_all" value="1">';
    echo '<span>Substituir em <strong>todos os atendimentos</strong> deste paciente com o mesmo profissional (' . (count($otherAssignments) + 1) . ' atendimentos)</span>';
    echo '</label>';
    echo '<div style="margin-top:8px;font-size:12px;color:hsl(var(--muted-foreground))">';
    foreach ($otherAssignments as $oa) {
        echo '• ' . h((string)($oa['specialty'] ?? $oa['service_type'] ?? 'Sem especialidade')) . ' (freq: ' . h((string)($oa['session_frequency'] ?? '-')) . ')<br>';
    }
    echo '</div>';
    echo '</div>';
}

echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
echo '<a class="btn" href="/monitoramento.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit" style="background:#8b5cf6">Confirmar Substituição</button>';
echo '</div>';
echo '</form>';
echo '</section>';

// Histórico
if (count($history) > 0) {
    echo '<section class="card col12">';
    echo '<div style="font-weight:900;margin-bottom:8px">Histórico de Substituições</div>';
    echo '<table><thead><tr><th>Data</th><th>De</th><th>Para</th><th>Motivo</th><th>Por</th></tr></thead><tbody>';
    foreach ($history as $h) {
        echo '<tr>';
        echo '<td>' . h((string)$h['created_at']) . '</td>';
        echo '<td>' . h((string)($h['old_prof_name'] ?? '-')) . '</td>';
        echo '<td><strong>' . h((string)($h['new_prof_name'] ?? '-')) . '</strong></td>';
        echo '<td>' . h(mb_strimwidth((string)($h['reason'] ?? ''), 0, 80, '...')) . '</td>';
        echo '<td>' . h((string)($h['changed_by_name'] ?? '-')) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</section>';
}

echo '</div>';
view_footer();
