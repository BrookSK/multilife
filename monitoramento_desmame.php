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

// Outros atendimentos do mesmo paciente
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

// Histórico de alterações
$stmtHist = db()->prepare(
    "SELECT fc.*, u.name as changed_by_name
     FROM patient_frequency_changes fc
     LEFT JOIN users u ON u.id = fc.changed_by_user_id
     WHERE fc.assignment_id = :aid
     ORDER BY fc.created_at DESC LIMIT 20"
);
$stmtHist->execute(['aid' => $assignmentId]);
$history = $stmtHist->fetchAll();

// Frequência atual normalizada (para pré-selecionar)
$currentFreq = function_exists('frequency_normalize')
    ? frequency_normalize((string)($assignment['session_frequency'] ?? ''))
    : (string)($assignment['session_frequency'] ?? '');

// Opções de frequência com os dias fixos de cada uma (para semanais/diário)
$freqOptions = [
    '1x_semana' => ['label' => '1x/Semana', 'weekdays' => [3]],
    '2x_semana' => ['label' => '2x/Semana', 'weekdays' => [2, 4]],
    '3x_semana' => ['label' => '3x/Semana', 'weekdays' => [1, 3, 5]],
    '4x_semana' => ['label' => '4x/Semana', 'weekdays' => [1, 2, 3, 4]],
    '5x_semana' => ['label' => '5x/Semana', 'weekdays' => [1, 2, 3, 4, 5]],
    '6x_semana' => ['label' => '6x/Semana', 'weekdays' => [1, 2, 3, 4, 5, 6]],
    '7x_semana' => ['label' => '7x/Semana (Diário)', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]],
    'quinzenal' => ['label' => 'Quinzenal (2x/Mês)', 'weekdays' => []],
    'mensal'    => ['label' => 'Mensal (1x/Mês)', 'weekdays' => []],
    'avaliacao' => ['label' => 'Avaliação (sessão única)', 'weekdays' => []],
    'pontual'   => ['label' => 'Atendimento pontual (sessão única)', 'weekdays' => []],
];

$diasNome = [1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado', 7 => 'Domingo'];
$diasCurto = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

view_header('Desmame - Alterar Frequência');
?>

<div class="grid">
  <section class="card col12">
    <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div>
        <div style="font-size:22px;font-weight:900">📉 Desmame - Alterar Frequência</div>
        <div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Alterar a frequência de atendimento do paciente</div>
      </div>
      <a class="btn" href="/monitoramento.php">Voltar</a>
    </div>
  </section>

  <section class="card col12">
    <div class="grid">
      <div class="col6"><div class="pill" style="display:block"><strong>Paciente:</strong> <?= h((string)$assignment['patient_name']) ?></div></div>
      <div class="col6"><div class="pill" style="display:block"><strong>Profissional:</strong> <?= h((string)($assignment['professional_name'] ?? '-')) ?></div></div>
      <div class="col6"><div class="pill" style="display:block"><strong>Especialidade:</strong> <?= h((string)($assignment['specialty'] ?? $assignment['service_type'] ?? '-')) ?></div></div>
      <div class="col6"><div class="pill" style="display:block"><strong>Frequência atual:</strong> <?= h((string)($assignment['session_frequency'] ?? 'Não definida')) ?></div></div>
      <div class="col6"><div class="pill" style="display:block"><strong>Qtd. Sessões:</strong> <?= (int)($assignment['session_quantity'] ?? 0) ?></div></div>
    </div>
  </section>

  <section class="card col12">
    <div style="font-weight:900;margin-bottom:12px">Nova Frequência</div>

    <form method="post" action="/monitoramento_desmame_post.php">
      <input type="hidden" name="assignment_id" value="<?= $assignmentId ?>">

      <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px">
        <div style="flex:1;min-width:240px">
          <label style="font-weight:700;display:block;margin-bottom:4px">Nova frequência</label>
          <select name="new_frequency" id="freqSelect" required style="width:100%;padding:10px;border:1px solid hsl(var(--border));border-radius:8px">
            <option value="">— Selecione —</option>
            <?php foreach ($freqOptions as $code => $opt): ?>
              <option value="<?= h($code) ?>" data-weekdays="<?= h(json_encode($opt['weekdays'])) ?>" <?= ($code === $currentFreq ? 'selected' : '') ?>>
                <?= h($opt['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:1;min-width:200px">
          <label style="font-weight:700;display:block;margin-bottom:4px">Nova qtd. de sessões (opcional)</label>
          <input type="number" name="new_session_quantity" min="1" value="<?= (int)($assignment['session_quantity'] ?? '') ?>" style="width:100%;padding:10px;border:1px solid hsl(var(--border));border-radius:8px">
        </div>
      </div>

      <div id="weekdaysError" style="display:none;margin-bottom:10px;color:hsl(var(--destructive));font-size:13px;font-weight:700"></div>

      <!-- (A) Dias fixos (semanais/diário) -->
      <div id="fixedDaysBlock" style="display:none;margin-bottom:16px">
        <label style="font-weight:700;display:block;margin-bottom:6px">Dias de atendimento (definidos pela frequência)</label>
        <div id="fixedDaysChips" style="display:flex;gap:8px;flex-wrap:wrap"></div>
        <div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:6px">Estes dias se repetem toda semana e são definidos automaticamente pela frequência escolhida.</div>
        <div id="fixedDaysInputs"></div>
      </div>

      <!-- (B) Mensal / Quinzenal -->
      <div id="monthBlock" style="display:none;margin-bottom:16px;border:1px solid hsl(var(--border));border-radius:10px;padding:16px">
        <label style="font-weight:700;display:block;margin-bottom:6px" id="monthTitle">Quando ocorre no mês?</label>
        <div id="monthHint" style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:14px"></div>

        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:16px">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:600">
            <input type="radio" name="month_mode" value="fixed_day" class="mm-mode" checked style="width:auto"> Dia fixo do mês
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:600">
            <input type="radio" name="month_mode" value="weekday_position" class="mm-mode" style="width:auto"> Dia da semana (ex.: 1ª quinta-feira)
          </label>
        </div>

        <!-- Modo 1: dia fixo do mês -->
        <div id="mmFixedDay">
          <div style="font-size:13px;font-weight:600;margin-bottom:8px">Selecione o(s) dia(s) do mês:</div>
          <div style="display:grid;grid-template-columns:repeat(7,minmax(40px,1fr));gap:6px;max-width:360px">
            <?php for ($d = 1; $d <= 31; $d++): ?>
              <label class="mday" style="display:flex;align-items:center;justify-content:center;padding:10px 0;border:1px solid hsl(var(--border));border-radius:8px;cursor:pointer;font-size:14px;font-weight:700">
                <input type="checkbox" name="month_days[]" value="<?= $d ?>" class="md-check" style="display:none"><?= $d ?>
              </label>
            <?php endfor; ?>
          </div>
        </div>

        <!-- Modo 2: posição + dia da semana -->
        <div id="mmWeekdayPos" style="display:none">
          <div style="font-size:13px;font-weight:600;margin-bottom:10px">Selecione a semana e o dia:</div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
            <select name="week_position[]" style="padding:9px;border:1px solid hsl(var(--border));border-radius:8px;min-width:150px">
              <option value="1">1ª semana</option>
              <option value="2">2ª semana</option>
              <option value="3">3ª semana</option>
              <option value="4">4ª semana</option>
              <option value="last">Última semana</option>
            </select>
            <select name="week_weekday[]" style="padding:9px;border:1px solid hsl(var(--border));border-radius:8px;min-width:170px">
              <?php foreach ($diasNome as $wn => $wl): ?>
                <option value="<?= $wn ?>" <?= ($wn === 4 ? 'selected' : '') ?>><?= h($wl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div id="mmPos2" style="display:none;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
            <select name="week_position[]" style="padding:9px;border:1px solid hsl(var(--border));border-radius:8px;min-width:150px">
              <option value="1">1ª semana</option>
              <option value="2">2ª semana</option>
              <option value="3" selected>3ª semana</option>
              <option value="4">4ª semana</option>
              <option value="last">Última semana</option>
            </select>
            <select name="week_weekday[]" style="padding:9px;border:1px solid hsl(var(--border));border-radius:8px;min-width:170px">
              <?php foreach ($diasNome as $wn => $wl): ?>
                <option value="<?= $wn ?>" <?= ($wn === 4 ? 'selected' : '') ?>><?= h($wl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="font-size:12px;color:hsl(var(--muted-foreground))">Ex.: "1ª quinta-feira do mês" agenda sempre na primeira quinta-feira de cada mês.</div>
        </div>
      </div>

      <!-- (C) Sessão única -->
      <div id="singleBlock" style="display:none;margin-bottom:16px;padding:12px;background:hsla(var(--warning)/.1);border-radius:8px;font-size:13px">
        Esta modalidade cria uma <strong>sessão única</strong> na data de início. Não requer dias.
      </div>

      <div style="margin-bottom:16px">
        <label style="font-weight:700;display:block;margin-bottom:4px">Motivo da alteração</label>
        <textarea name="reason" rows="3" required placeholder="Ex: Paciente apresentou melhora, reduzindo necessidade de atendimento..." style="width:100%;padding:10px;border:1px solid hsl(var(--border));border-radius:8px"></textarea>
      </div>

      <?php if (count($otherAssignments) > 0): ?>
      <div style="padding:12px;background:hsla(var(--warning)/.1);border:1px solid hsl(var(--border));border-radius:8px;margin-bottom:16px">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="apply_to_all" value="1" style="width:auto">
          <span>Aplicar a <strong>todos os atendimentos</strong> deste paciente (<?= count($otherAssignments) + 1 ?> atendimentos)</span>
        </label>
      </div>
      <?php endif; ?>

      <div style="display:flex;gap:10px;justify-content:flex-end">
        <a class="btn" href="/monitoramento.php">Cancelar</a>
        <button class="btn btnPrimary" type="submit" style="background:#f59e0b">Confirmar Desmame</button>
      </div>
    </form>
  </section>

  <?php if (count($history) > 0): ?>
  <section class="card col12">
    <div style="font-weight:900;margin-bottom:8px">Histórico de Alterações</div>
    <div style="overflow:auto"><table>
      <thead><tr><th>Data</th><th>De</th><th>Para</th><th>Motivo</th><th>Por</th></tr></thead>
      <tbody>
      <?php foreach ($history as $hh): ?>
        <tr>
          <td><?= h((string)$hh['created_at']) ?></td>
          <td><?= h((string)($hh['old_frequency'] ?? '-')) ?></td>
          <td><strong><?= h((string)$hh['new_frequency']) ?></strong></td>
          <td><?= h(mb_strimwidth((string)($hh['reason'] ?? ''), 0, 80, '...')) ?></td>
          <td><?= h((string)($hh['changed_by_name'] ?? '-')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <?php endif; ?>
</div>

<style>
.md-check:checked + .mday-num { color:#fff; }
.mday input.md-check:checked ~ span { }
label.mday:has(.md-check:checked){ border-color:hsl(var(--primary)) !important; background:hsl(var(--primary)); color:#fff; }
</style>

<script>
(function(){
  var SINGLE = ["avaliacao","pontual"];
  var MONTHLY = ["quinzenal","mensal"];
  var DIAS = {1:"Seg",2:"Ter",3:"Qua",4:"Qui",5:"Sex",6:"Sab",7:"Dom"};

  function g(id){ return document.getElementById(id); }

  function renderMonthMode(){
    var checked = document.querySelector('input[name="month_mode"]:checked');
    var mode = checked ? checked.value : "fixed_day";
    var fx = g("mmFixedDay");
    var wp = g("mmWeekdayPos");
    if(fx) fx.style.display = (mode === "fixed_day") ? "block" : "none";
    if(wp) wp.style.display = (mode === "weekday_position") ? "block" : "none";
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
    var monthBlock = g("monthBlock");
    var singleBlock = g("singleBlock");
    var fixedChips = g("fixedDaysChips");
    var fixedInputs = g("fixedDaysInputs");

    if(fixedBlock) fixedBlock.style.display = "none";
    if(monthBlock) monthBlock.style.display = "none";
    if(singleBlock) singleBlock.style.display = "none";
    if(fixedInputs) fixedInputs.innerHTML = "";

    if(SINGLE.indexOf(freq) !== -1){
      if(singleBlock) singleBlock.style.display = "block";
    } else if(MONTHLY.indexOf(freq) !== -1){
      if(monthBlock) monthBlock.style.display = "block";
      var isQ = (freq === "quinzenal");
      var pos2 = g("mmPos2");
      var title = g("monthTitle");
      var hint = g("monthHint");
      if(isQ){
        if(title) title.textContent = "Quando ocorre (Quinzenal - 2x por mes)?";
        if(hint) hint.textContent = "Escolha por dia fixo do mes (2 dias) ou por dia da semana (2 ocorrencias, ex.: 1a e 3a quinta-feira).";
        if(pos2) pos2.style.display = "flex";
      } else {
        if(title) title.textContent = "Quando ocorre (Mensal - 1x por mes)?";
        if(hint) hint.textContent = "Escolha por dia fixo do mes (ex.: dia 25) ou por dia da semana (ex.: 1a quinta-feira).";
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
    var modes = document.querySelectorAll('input[name="month_mode"]');
    for(var i=0;i<modes.length;i++){ modes[i].addEventListener("change", renderMonthMode); }
    render();

    var form = document.querySelector('form[action="/monitoramento_desmame_post.php"]');
    if(form){
      form.addEventListener("submit", function(e){
        var s = g("freqSelect");
        var freq = s ? s.value : "";
        var errDiv = g("weekdaysError");
        if(errDiv) errDiv.style.display = "none";
        if(!freq){ e.preventDefault(); if(errDiv){ errDiv.style.display="block"; errDiv.textContent="Selecione a nova frequencia."; } return false; }
        if(SINGLE.indexOf(freq) !== -1) return true;
        if(MONTHLY.indexOf(freq) !== -1){
          var checked = document.querySelector('input[name="month_mode"]:checked');
          var mode = checked ? checked.value : "fixed_day";
          if(mode === "fixed_day"){
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

<?php
view_footer();
