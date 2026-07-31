<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$assignmentId = (int)($_GET['assignment_id'] ?? 0);

$stmt = db()->prepare(
    "SELECT pa.*, p.full_name as patient_name, p.id as patient_id, u.name as professional_name, d.specialty
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

// Buscar outros atendimentos do mesmo paciente
$stmtOthers = db()->prepare(
    "SELECT pa.id, pa.specialty, pa.session_frequency, pa.session_quantity, u.name as professional_name
     FROM patient_assignments pa
     LEFT JOIN users u ON u.id = pa.professional_user_id
     WHERE pa.patient_id = :pid AND pa.status IN ('admitted','awaiting_documents','awaiting_financial_approval','completed','confirmed','approved')
     AND pa.id != :aid
     ORDER BY pa.created_at DESC"
);
$stmtOthers->execute(['pid' => (int)$assignment['patient_id'], 'aid' => $assignmentId]);
$otherAssignments = $stmtOthers->fetchAll();

// Buscar histórico de alterações
$stmtHist = db()->prepare(
    "SELECT fc.*, u.name as changed_by_name
     FROM patient_frequency_changes fc
     LEFT JOIN users u ON u.id = fc.changed_by_user_id
     WHERE fc.assignment_id = :aid
     ORDER BY fc.created_at DESC LIMIT 20"
);
$stmtHist->execute(['aid' => $assignmentId]);
$history = $stmtHist->fetchAll();

view_header('Desmame - Alterar Frequência');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">📉 Desmame - Alterar Frequência</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Alterar a frequência de atendimento do paciente</div>';
echo '</div>';
echo '<a class="btn" href="/monitoramento.php">Voltar</a>';
echo '</div>';
echo '</section>';

// Info do atendimento
echo '<section class="card col12">';
echo '<div class="grid">';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Paciente:</strong> ' . h((string)$assignment['patient_name']) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Profissional:</strong> ' . h((string)($assignment['professional_name'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Especialidade:</strong> ' . h((string)($assignment['specialty'] ?? $assignment['service_type'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Frequência atual:</strong> ' . h(function_exists('frequency_translate') ? frequency_translate((string)($assignment['session_frequency'] ?? 'Não definida')) : (string)($assignment['session_frequency'] ?? 'Não definida')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Qtd. Sessões:</strong> ' . (int)($assignment['session_quantity'] ?? 0) . '</div></div>';
echo '</div>';
echo '</section>';

// Formulário
echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:12px">Nova Frequência</div>';
echo '<form method="post" action="/monitoramento_desmame_post.php" style="display:grid;gap:12px">';
echo '<input type="hidden" name="assignment_id" value="' . $assignmentId . '">';

echo '<div class="grid">';
echo '<div class="col6"><label>Nova frequência<select name="new_frequency" id="freqSelect" required>';
// Usar tabela padronizada
if (function_exists('frequency_get_options')) {
    $freqOpts = frequency_get_options();
    echo '<option value="">— Selecione —</option>';
    foreach ($freqOpts as $fo) {
        $sel = ($fo['code'] === frequency_normalize((string)($assignment['session_frequency'] ?? ''))) ? ' selected' : '';
        echo '<option value="' . h($fo['code']) . '"' . $sel . ' data-weekdays=\'' . json_encode($fo['weekdays']) . '\'>';
        echo h($fo['label']) . ' — ' . h($fo['description']);
        echo '</option>';
    }
} else {
    $freqOptions = ['1x por semana', '2x por semana', '3x por semana', '4x por semana', '5x por semana', '6x por semana', 'Diário', '1x a cada 15 dias', '1x por mês', '2x por mês'];
    echo '<option value="">— Selecione —</option>';
    foreach ($freqOptions as $fo) {
        $sel = (strcasecmp($fo, (string)($assignment['session_frequency'] ?? '')) === 0) ? ' selected' : '';
        echo '<option value="' . h($fo) . '"' . $sel . '>' . h($fo) . '</option>';
    }
}
echo '</select></label></div>';
echo '<div class="col6"><label>Nova qtd. de sessões (opcional)<input type="number" name="new_session_quantity" min="1" value="' . (int)($assignment['session_quantity'] ?? '') . '"></label></div>';

// Seleção de novos dias da semana
$currentWeekdays = [];
if (!empty($assignment['weekdays'])) {
    $currentWeekdays = json_decode((string)$assignment['weekdays'], true) ?: [];
}
echo '<div class="col12"><label style="font-weight:700">Novos dias da semana</label>';
echo '<div id="weekdaysError" style="display:none;margin-top:4px;margin-bottom:4px;color:hsl(var(--destructive));font-size:13px;font-weight:700"></div>';
echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">';
$diasSemana = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
foreach ($diasSemana as $num => $nome) {
    $checked = in_array($num, $currentWeekdays, true) ? ' checked' : '';
    echo '<label style="display:flex;align-items:center;gap:4px;padding:8px 12px;border:1px solid hsl(var(--border));border-radius:8px;cursor:pointer;font-size:13px;font-weight:700">';
    echo '<input type="checkbox" name="weekdays[]" value="' . $num . '"' . $checked . ' class="wd-check"> ' . $nome;
    echo '</label>';
}
echo '</div></div>';
echo '<div class="col12"><label>Motivo da alteração<textarea name="reason" rows="3" required placeholder="Ex: Paciente apresentou melhora significativa, reduzindo necessidade de atendimento..."></textarea></label></div>';
echo '</div>';

// Opção de aplicar a todos
if (count($otherAssignments) > 0) {
    echo '<div style="padding:12px;background:hsla(var(--warning)/.1);border:1px solid hsl(var(--border));border-radius:8px">';
    echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer">';
    echo '<input type="checkbox" name="apply_to_all" value="1">';
    echo '<span>Aplicar a <strong>todos os atendimentos</strong> deste paciente (' . (count($otherAssignments) + 1) . ' atendimentos)</span>';
    echo '</label>';
    if (count($otherAssignments) > 0) {
        echo '<div style="margin-top:8px;font-size:12px;color:hsl(var(--muted-foreground))">';
        foreach ($otherAssignments as $oa) {
            echo '• ' . h((string)($oa['specialty'] ?? 'Sem especialidade')) . ' - ' . h((string)($oa['professional_name'] ?? '-')) . ' (freq: ' . h((string)($oa['session_frequency'] ?? '-')) . ')<br>';
        }
        echo '</div>';
    }
    echo '</div>';
}

echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
echo '<a class="btn" href="/monitoramento.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit" style="background:#f59e0b">Confirmar Desmame</button>';
echo '</div>';
echo '</form>';

// Validação e auto-seleção de dias
echo '<script>';
echo 'document.addEventListener("DOMContentLoaded", function() {';
echo '  var freqSelect = document.getElementById("freqSelect");';
echo '  var checks = document.querySelectorAll(".wd-check");';
echo '  ';
echo '  // Auto-selecionar dias quando mudar a frequência';
echo '  if (freqSelect) {';
echo '    freqSelect.addEventListener("change", function() {';
echo '      var opt = this.options[this.selectedIndex];';
echo '      var weekdays = opt.getAttribute("data-weekdays");';
echo '      if (weekdays) {';
echo '        var days = JSON.parse(weekdays);';
echo '        checks.forEach(function(cb) {';
echo '          cb.checked = days.indexOf(parseInt(cb.value)) !== -1;';
echo '        });';
echo '      }';
echo '    });';
echo '    // Disparar ao carregar se já tem valor selecionado';
echo '    if (freqSelect.value) freqSelect.dispatchEvent(new Event("change"));';
echo '  }';
echo '});';
echo 'document.querySelector("form[action=\'/monitoramento_desmame_post.php\']").addEventListener("submit", function(e) {';
echo '  var freqSelect = document.getElementById("freqSelect");';
echo '  var freq = freqSelect ? freqSelect.value : "";';
echo '  var opt = freqSelect.options[freqSelect.selectedIndex];';
echo '  var weekdays = opt ? opt.getAttribute("data-weekdays") : "[]";';
echo '  var required = weekdays ? JSON.parse(weekdays).length : 0;';
echo '  var checked = document.querySelectorAll(".wd-check:checked").length;';
echo '  var errDiv = document.getElementById("weekdaysError");';
echo '  if (required > 0 && checked !== required) {';
echo '    e.preventDefault();';
echo '    errDiv.style.display = "block";';
echo '    errDiv.textContent = "Selecione exatamente " + required + " dia(s) para a frequência \'" + freq + "\'. Você selecionou " + checked + ".";';
echo '    return false;';
echo '  }';
echo '  if (checked === 0) {';
echo '    e.preventDefault();';
echo '    errDiv.style.display = "block";';
echo '    errDiv.textContent = "Selecione pelo menos 1 dia da semana.";';
echo '    return false;';
echo '  }';
echo '  errDiv.style.display = "none";';
echo '});';
echo '</script>';

echo '</section>';

// Histórico
if (count($history) > 0) {
    echo '<section class="card col12">';
    echo '<div style="font-weight:900;margin-bottom:8px">Histórico de Alterações</div>';
    echo '<table><thead><tr><th>Data</th><th>De</th><th>Para</th><th>Motivo</th><th>Por</th></tr></thead><tbody>';
    foreach ($history as $h) {
        echo '<tr>';
        echo '<td>' . h((string)$h['created_at']) . '</td>';
        echo '<td>' . h((string)($h['old_frequency'] ?? '-')) . '</td>';
        echo '<td><strong>' . h((string)$h['new_frequency']) . '</strong></td>';
        echo '<td>' . h(mb_strimwidth((string)($h['reason'] ?? ''), 0, 80, '...')) . '</td>';
        echo '<td>' . h((string)($h['changed_by_name'] ?? '-')) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</section>';
}

echo '</div>';
view_footer();
