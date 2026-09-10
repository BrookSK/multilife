<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$assignmentId = (int)($_GET['assignment_id'] ?? 0);

// Garantir coluna month_days (fallback caso a migration não tenha rodado)
try { db()->exec("ALTER TABLE patient_assignments ADD COLUMN month_days VARCHAR(120) NULL"); } catch (Throwable $e) {}

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
echo '</div>'; // fecha .grid dos dois campos

// Seleção de dias — comportamento dinâmico por frequência:
//  - 1x a 6x/semana e diário: dias FIXOS automáticos (só exibe, não deixa escolher)
//  - quinzenal e mensal: seletor de DIA DO MÊS (calendário)
//  - avaliação e pontual: nada (sessão única)
$currentWeekdays = [];
if (!empty($assignment['weekdays'])) {
    $currentWeekdays = json_decode((string)$assignment['weekdays'], true) ?: [];
}
$currentMonthDays = [];
if (!empty($assignment['month_days'])) {
    $currentMonthDays = json_decode((string)$assignment['month_days'], true) ?: [];
}
$diasSemana = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

echo '<div class="col12">';
echo '<div id="weekdaysError" style="display:none;margin-top:4px;margin-bottom:8px;color:hsl(var(--destructive));font-size:13px;font-weight:700"></div>';

// (A) Bloco informativo dos dias FIXOS (semanais/diário) — preenchido via JS, somente leitura
echo '<div id="fixedDaysBlock" style="display:none;margin-top:6px">';
echo '<label style="font-weight:700;display:block;margin-bottom:6px">Dias de atendimento (definidos pela frequência)</label>';
echo '<div id="fixedDaysChips" style="display:flex;gap:8px;flex-wrap:wrap"></div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:6px">Estes dias se repetem toda semana. São definidos automaticamente pela frequência escolhida.</div>';
// hidden inputs com os weekdays fixos (preenchidos via JS)
echo '<div id="fixedDaysInputs"></div>';
echo '</div>';

// (B) Bloco MENSAL/QUINZENAL — duas modalidades de escolha:
//     1) Dia fixo do mês (ex.: todo dia 25)
//     2) Posição + dia da semana (ex.: 1ª quinta-feira, última sexta-feira)
echo '<div id="monthDaysBlock" style="display:none;margin-top:6px;border:1px solid hsl(var(--border));border-radius:10px;padding:14px">';
echo '<label style="font-weight:700;display:block;margin-bottom:8px" id="monthDaysLabel">Quando o atendimento ocorre no mês?</label>';
echo '<div id="monthDaysHint" style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:12px"></div>';

// Escolha do MODO
echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px">';
echo '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:600"><input type="radio" name="month_mode" value="fixed_day" class="mm-mode" checked style="width:auto"> Dia fixo do mês</label>';
echo '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:600"><input type="radio" name="month_mode" value="weekday_position" class="mm-mode" style="width:auto"> Dia da semana (ex.: 1ª quinta-feira)</label>';
echo '</div>';

// MODO 1: dia fixo do mês (calendário 1..31)
echo '<div id="mmFixedDay">';
echo '<div style="font-size:13px;font-weight:600;margin-bottom:6px" id="mmFixedDayLabel">Selecione o(s) dia(s) do mês:</div>';
echo '<div style="display:grid;grid-template-columns:repeat(7,minmax(38px,1fr));gap:6px;max-width:340px">';
for ($d = 1; $d <= 31; $d++) {
    $checked = in_array($d, $currentMonthDays, true) ? ' checked' : '';
    echo '<label style="display:flex;align-items:center;justify-content:center;padding:8px 0;border:1px solid hsl(var(--border));border-radius:8px;cursor:pointer;font-size:13px;font-weight:700">';
    echo '<input type="checkbox" name="month_days[]" value="' . $d . '"' . $checked . ' class="md-check" style="display:none"><span>' . $d . '</span>';
    echo '</label>';
}
echo '</div>';
echo '</div>';

// MODO 2: posição + dia da semana
echo '<div id="mmWeekdayPos" style="display:none">';
echo '<div style="font-size:13px;font-weight:600;margin-bottom:8px" id="mmWeekdayPosLabel">Selecione a semana e o dia:</div>';
echo '<div style="display:grid;gap:12px;max-width:420px">';
// Linha 1 (sempre visível)
echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
echo '<select name="week_position[]" class="wp-pos" style="min-width:150px">';
$posOptions = ['1' => '1ª semana', '2' => '2ª semana', '3' => '3ª semana', '4' => '4ª semana', 'last' => 'Última semana'];
foreach ($posOptions as $pv => $pl) { echo '<option value="' . h($pv) . '">' . h($pl) . '</option>'; }
echo '</select>';
echo '<select name="week_weekday[]" class="wp-day" style="min-width:150px">';
$wdOptions = [1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado', 7 => 'Domingo'];
foreach ($wdOptions as $wv => $wl) { echo '<option value="' . $wv . '">' . h($wl) . '</option>'; }
echo '</select>';
echo '</div>';
// Linha 2 (só para quinzenal — 2ª ocorrência do mês)
echo '<div id="mmWeekdayPos2" style="display:none;gap:10px;flex-wrap:wrap;align-items:center">';
echo '<select name="week_position[]" class="wp-pos" style="min-width:150px">';
foreach ($posOptions as $pv => $pl) { $selp = ($pv === '3') ? ' selected' : ''; echo '<option value="' . h($pv) . '"' . $selp . '>' . h($pl) . '</option>'; }
echo '</select>';
echo '<select name="week_weekday[]" class="wp-day" style="min-width:150px">';
foreach ($wdOptions as $wv => $wl) { echo '<option value="' . $wv . '">' . h($wl) . '</option>'; }
echo '</select>';
echo '</div>';
echo '</div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:8px">Ex.: "1ª quinta-feira do mês" agenda sempre na primeira quinta-feira de cada mês.</div>';
echo '</div>';

echo '</div>';

// (C) Aviso de sessão única (avaliação/pontual)
echo '<div id="singleSessionBlock" style="display:none;margin-top:6px;padding:12px;background:hsla(var(--warning)/.1);border-radius:8px;font-size:13px;color:hsl(var(--foreground))">';
echo 'Esta modalidade cria uma <strong>sessão única</strong> na data de início. Não requer dias da semana nem dia do mês.';
echo '</div>';

echo '</div>';

// CSS para destacar o dia do mês selecionado
echo '<style>';
echo '.md-check:checked + span{background:hsl(var(--primary));color:#fff;border-radius:6px;padding:4px 8px;display:inline-block}';
echo '</style>';
echo '<div class="col12" style="margin-top:12px"><label>Motivo da alteração<textarea name="reason" rows="3" required placeholder="Ex: Paciente apresentou melhora significativa, reduzindo necessidade de atendimento..." style="width:100%"></textarea></label></div>';

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

// Comportamento dinâmico por frequência. Emitido via echo (mesma forma do resto da página).
echo <<<'JS'
<script>
(function(){
  var SINGLE = ["avaliacao","pontual"];
  var MONTHLY = ["quinzenal","biweekly","mensal","monthly"];
  var DIAS = {1:"Seg",2:"Ter",3:"Qua",4:"Qui",5:"Sex",6:"Sab",7:"Dom"};
  function g(id){ return document.getElementById(id); }
  function renderMonthMode(){
    var mode = document.querySelector(".mm-mode:checked");
    var m = mode ? mode.value : "fixed_day";
    var fx = g("mmFixedDay");
    var wp = g("mmWeekdayPos");
    if(fx) fx.style.display = (m === "fixed_day") ? "block" : "none";
    if(wp) wp.style.display = (m === "weekday_position") ? "block" : "none";
  }
  function render(){
    var fs = g("freqSelect");
    if(!fs) return;
    var freq = fs.value;
    var opt = fs.options[fs.selectedIndex];
    var wd = opt ? (opt.getAttribute("data-weekdays") || "[]") : "[]";
    var days = [];
    try { days = JSON.parse(wd); } catch(e) { days = []; }
    var fixedBlock = g("fixedDaysBlock");
    var monthBlock = g("monthDaysBlock");
    var singleBlock = g("singleSessionBlock");
    var fixedChips = g("fixedDaysChips");
    var fixedInputs = g("fixedDaysInputs");
    var monthHint = g("monthDaysHint");
    var monthLabel = g("monthDaysLabel");
    if(fixedBlock) fixedBlock.style.display = "none";
    if(monthBlock) monthBlock.style.display = "none";
    if(singleBlock) singleBlock.style.display = "none";
    if(fixedInputs) fixedInputs.innerHTML = "";
    if(SINGLE.indexOf(freq) !== -1){
      if(singleBlock) singleBlock.style.display = "block";
    } else if(MONTHLY.indexOf(freq) !== -1){
      if(monthBlock) monthBlock.style.display = "block";
      var isQ = (freq === "quinzenal" || freq === "biweekly");
      var pos2 = g("mmWeekdayPos2");
      if(isQ){
        if(monthLabel) monthLabel.textContent = "Quando ocorre (quinzenal - 2x por mes)?";
        if(monthHint) monthHint.textContent = "Escolha por dia fixo do mes (2 dias) ou por dia da semana (2 ocorrencias).";
        if(pos2) pos2.style.display = "flex";
      } else {
        if(monthLabel) monthLabel.textContent = "Quando ocorre (mensal - 1x por mes)?";
        if(monthHint) monthHint.textContent = "Escolha por dia fixo do mes (ex.: dia 25) ou por dia da semana (ex.: 1a quinta-feira).";
        if(pos2) pos2.style.display = "none";
      }
      renderMonthMode();
    } else if(days.length > 0){
      if(fixedBlock) fixedBlock.style.display = "block";
      if(fixedChips) fixedChips.innerHTML = "";
      days.forEach(function(d){
        var chip = document.createElement("span");
        chip.textContent = DIAS[d] || d;
        chip.style.cssText = "padding:8px 14px;background:hsl(var(--primary));color:#fff;border-radius:8px;font-size:13px;font-weight:700";
        if(fixedChips) fixedChips.appendChild(chip);
        var inp = document.createElement("input");
        inp.type = "hidden"; inp.name = "weekdays[]"; inp.value = d;
        if(fixedInputs) fixedInputs.appendChild(inp);
      });
    }
  }
  function init(){
    var fs = g("freqSelect");
    if(fs) fs.addEventListener("change", render);
    var mm = document.querySelectorAll(".mm-mode");
    for(var i=0;i<mm.length;i++){ mm[i].addEventListener("change", renderMonthMode); }
    render();
    var form = document.querySelector("form[action='/monitoramento_desmame_post.php']");
    if(form){
      form.addEventListener("submit", function(e){
        var s = g("freqSelect");
        var freq = s ? s.value : "";
        var errDiv = g("weekdaysError");
        if(errDiv) errDiv.style.display = "none";
        if(!freq){ e.preventDefault(); if(errDiv){ errDiv.style.display="block"; errDiv.textContent="Selecione a nova frequencia."; } return false; }
        if(SINGLE.indexOf(freq) !== -1) return true;
        if(MONTHLY.indexOf(freq) !== -1){
          var mode = document.querySelector(".mm-mode:checked");
          var m = mode ? mode.value : "fixed_day";
          if(m === "fixed_day"){
            var md = document.querySelectorAll(".md-check:checked").length;
            if(md < 1){ e.preventDefault(); if(errDiv){ errDiv.style.display="block"; errDiv.textContent="Selecione o(s) dia(s) do mes no calendario."; } return false; }
          }
        }
        return true;
      });
    }
  }
  if(document.readyState === "loading"){
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
</script>
JS;

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
