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

      <!-- (B) Mensal / Quinzenal — calendário visual (Opção B) -->
      <div id="monthBlock" style="display:none;margin-bottom:16px;border:1px solid hsl(var(--border));border-radius:12px;padding:18px;max-width:420px">
        <label style="font-weight:700;display:block;margin-bottom:4px" id="monthTitle">Escolha a data de início</label>
        <div id="monthHint" style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:14px"></div>

        <!-- Cabeçalho do calendário -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
          <button type="button" id="calPrev" style="background:hsla(var(--primary)/.1);color:hsl(var(--primary));border:none;border-radius:8px;width:34px;height:34px;cursor:pointer;font-size:16px;font-weight:700">‹</button>
          <div id="calMonthLabel" style="font-weight:800;font-size:15px;text-transform:capitalize"></div>
          <button type="button" id="calNext" style="background:hsla(var(--primary)/.1);color:hsl(var(--primary));border:none;border-radius:8px;width:34px;height:34px;cursor:pointer;font-size:16px;font-weight:700">›</button>
        </div>

        <!-- Cabeçalho dos dias da semana -->
        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:6px">
          <div style="text-align:center;font-size:11px;font-weight:700;color:hsl(var(--muted-foreground))">D</div>
          <div style="text-align:center;font-size:11px;font-weight:700;color:hsl(var(--muted-foreground))">S</div>
          <div style="text-align:center;font-size:11px;font-weight:700;color:hsl(var(--muted-foreground))">T</div>
          <div style="text-align:center;font-size:11px;font-weight:700;color:hsl(var(--muted-foreground))">Q</div>
          <div style="text-align:center;font-size:11px;font-weight:700;color:hsl(var(--muted-foreground))">Q</div>
          <div style="text-align:center;font-size:11px;font-weight:700;color:hsl(var(--muted-foreground))">S</div>
          <div style="text-align:center;font-size:11px;font-weight:700;color:hsl(var(--muted-foreground))">S</div>
        </div>

        <!-- Grade dos dias (preenchida via JS) -->
        <div id="calGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px"></div>

        <!-- Data(s) escolhida(s) -->
        <div id="calSelected" style="margin-top:12px;font-size:13px;font-weight:600;color:hsl(var(--primary))"></div>

        <!-- Padrão de repetição (aparece após escolher a data) -->
        <div id="calPattern" style="display:none;margin-top:16px;padding-top:16px;border-top:1px solid hsl(var(--border))">
          <div style="font-size:13px;font-weight:700;margin-bottom:10px">Como deve repetir?</div>
          <div style="display:flex;flex-direction:column;gap:10px">
            <label class="rp-card" data-selected="1">
              <input type="radio" name="repeat_pattern" value="fixed_day" class="rp-mode" checked>
              <span class="rp-dot"></span>
              <span class="rp-text" id="rpFixedLabel">Repetir todo dia X de cada mês</span>
            </label>
            <label class="rp-card" data-selected="0">
              <input type="radio" name="repeat_pattern" value="weekday_position" class="rp-mode">
              <span class="rp-dot"></span>
              <span class="rp-text" id="rpPosLabel">Repetir na Nª [dia] de cada mês</span>
            </label>
          </div>
        </div>

        <!-- Campos ocultos preenchidos via JS -->
        <input type="hidden" name="month_mode" id="monthModeInput" value="fixed_day">
        <div id="monthHiddenInputs"></div>
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

      <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:20px;padding-top:16px;border-top:1px solid hsl(var(--border))">
        <a class="btn" href="/monitoramento.php">Cancelar</a>
        <button class="btn btnPrimary" type="submit">✓ Confirmar alteração</button>
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
.rp-card{
  display:flex;align-items:center;gap:12px;cursor:pointer;
  padding:14px 16px;border:1.5px solid hsl(var(--border));border-radius:10px;
  background:hsl(var(--card,0 0% 100%));transition:border-color .15s, background .15s, box-shadow .15s;
}
.rp-card:hover{ border-color:hsl(var(--primary)/.5); background:hsla(var(--primary)/.04); }
.rp-card input.rp-mode{ position:absolute;opacity:0;width:0;height:0; }
.rp-card .rp-dot{
  flex:0 0 auto;width:20px;height:20px;border-radius:50%;
  border:2px solid hsl(var(--border));position:relative;transition:border-color .15s;
}
.rp-card .rp-text{ font-size:14px;font-weight:600;color:hsl(var(--foreground)); }
.rp-card[data-selected="1"]{
  border-color:hsl(var(--primary));background:hsla(var(--primary)/.08);
  box-shadow:0 0 0 3px hsla(var(--primary)/.12);
}
.rp-card[data-selected="1"] .rp-dot{ border-color:hsl(var(--primary)); }
.rp-card[data-selected="1"] .rp-dot::after{
  content:"";position:absolute;inset:3px;border-radius:50%;background:hsl(var(--primary));
}
.rp-card[data-selected="1"] .rp-text{ color:hsl(var(--primary)); }
</style>

<script>
(function(){
  var SINGLE = ["avaliacao","pontual"];
  var MONTHLY = ["quinzenal","mensal"];
  var DIAS = {1:"Seg",2:"Ter",3:"Qua",4:"Qui",5:"Sex",6:"Sab",7:"Dom"};
  var MESES = ["janeiro","fevereiro","março","abril","maio","junho","julho","agosto","setembro","outubro","novembro","dezembro"];
  var DIASEXT = ["domingo","segunda-feira","terça-feira","quarta-feira","quinta-feira","sexta-feira","sábado"];
  var ORDINAL = ["","1ª","2ª","3ª","4ª","5ª"];

  function g(id){ return document.getElementById(id); }

  // Estado do calendário
  var calYear, calMonth;                 // mês exibido
  var selectedDates = [];                // array de "YYYY-MM-DD" (1 no mensal, 2 no quinzenal)
  var maxDates = 1;                      // 1 para mensal, 2 para quinzenal

  function pad(n){ return (n<10?"0":"")+n; }
  function ymd(y,m,d){ return y+"-"+pad(m+1)+"-"+pad(d); }

  // Qual ocorrência do dia da semana no mês (1ª, 2ª...) e se é a última
  function weekdayPosition(dateStr){
    var parts = dateStr.split("-");
    var y = +parts[0], m = +parts[1]-1, d = +parts[2];
    var dt = new Date(y, m, d);
    var wd = dt.getDay(); // 0=dom..6=sab
    var nth = Math.floor((d-1)/7) + 1;
    // última ocorrência?
    var isLast = (d + 7) > new Date(y, m+1, 0).getDate();
    return { nth: nth, isLast: isLast, weekday: wd };
  }

  function renderCalendar(){
    var grid = g("calGrid");
    var lbl = g("calMonthLabel");
    if(!grid || !lbl) return;
    lbl.textContent = MESES[calMonth] + " de " + calYear;
    grid.innerHTML = "";
    var firstDay = new Date(calYear, calMonth, 1).getDay(); // 0=dom
    var daysInMonth = new Date(calYear, calMonth+1, 0).getDate();
    var todayStr = (function(){ var t=new Date(); return ymd(t.getFullYear(), t.getMonth(), t.getDate()); })();
    // espaços vazios antes do dia 1
    for(var i=0;i<firstDay;i++){
      var empty = document.createElement("div");
      grid.appendChild(empty);
    }
    for(var d=1; d<=daysInMonth; d++){
      var cell = document.createElement("button");
      cell.type = "button";
      cell.textContent = d;
      var ds = ymd(calYear, calMonth, d);
      var isSel = selectedDates.indexOf(ds) !== -1;
      var isPast = ds < todayStr;
      cell.style.cssText = "padding:9px 0;border-radius:8px;border:1px solid transparent;cursor:pointer;font-size:14px;font-weight:600;background:"
        + (isSel ? "hsl(var(--primary));color:#fff" : (isPast ? "transparent;color:hsl(var(--muted-foreground));opacity:.45" : "hsla(var(--primary)/.06);color:hsl(var(--foreground))"));
      (function(dstr, past){
        cell.addEventListener("click", function(){
          if(past) return; // não deixa escolher passado
          toggleDate(dstr);
        });
      })(ds, isPast);
      grid.appendChild(cell);
    }
  }

  function toggleDate(ds){
    var idx = selectedDates.indexOf(ds);
    if(idx !== -1){
      selectedDates.splice(idx,1);
    } else {
      if(selectedDates.length >= maxDates){
        selectedDates.shift(); // remove a mais antiga se passou do limite
      }
      selectedDates.push(ds);
      selectedDates.sort();
    }
    renderCalendar();
    renderSelectedInfo();
    buildHiddenInputs();
  }

  function fmtBR(ds){
    var p = ds.split("-");
    return p[2]+"/"+p[1]+"/"+p[0];
  }

  function renderSelectedInfo(){
    var sel = g("calSelected");
    var pat = g("calPattern");
    if(!sel) return;
    if(selectedDates.length === 0){
      sel.textContent = "";
      if(pat) pat.style.display = "none";
      return;
    }
    var txt = selectedDates.map(fmtBR).join("  •  ");
    sel.textContent = "Selecionado: " + txt;

    // Padrão de repetição (considera TODAS as datas escolhidas — 1 no mensal, 2 no quinzenal)
    if(pat){
      // Só mostra as opções de padrão quando já escolheu a quantidade certa de datas
      if(selectedDates.length < maxDates){
        pat.style.display = "none";
      } else {
        pat.style.display = "block";
        updateRpCards();
        var fixedLbl = g("rpFixedLabel");
        var posLbl = g("rpPosLabel");

        // Texto do "dia fixo do mês": lista os dias (ex.: "dia 11 e dia 25")
        var dayNums = selectedDates.map(function(ds){ return +ds.split("-")[2]; });
        var fixedTxt = dayNums.map(function(n){ return "dia " + n; }).join(" e ");
        if(fixedLbl) fixedLbl.textContent = "Repetir todo " + fixedTxt + " de cada mês";

        // Texto da "posição + dia da semana": lista cada ocorrência (ex.: "2ª sexta-feira e 4ª sexta-feira")
        var posParts = selectedDates.map(function(ds){
          var pos = weekdayPosition(ds);
          var posTxt = pos.isLast ? "última" : (ORDINAL[pos.nth] || (pos.nth+"ª"));
          return posTxt + " " + DIASEXT[pos.weekday];
        });
        if(posLbl) posLbl.textContent = "Repetir na " + posParts.join(" e na ") + " de cada mês";
      }
    }
    buildHiddenInputs();
  }

  function updateRpCards(){
    var cards = document.querySelectorAll(".rp-card");
    for(var i=0;i<cards.length;i++){
      var radio = cards[i].querySelector('input.rp-mode');
      cards[i].setAttribute("data-selected", (radio && radio.checked) ? "1" : "0");
    }
  }

  function buildHiddenInputs(){
    var box = g("monthHiddenInputs");
    var modeInput = g("monthModeInput");
    if(!box) return;
    box.innerHTML = "";
    var pattern = document.querySelector('input[name="repeat_pattern"]:checked');
    var mode = pattern ? pattern.value : "fixed_day";
    if(modeInput) modeInput.value = mode;

    if(mode === "fixed_day"){
      // envia os dias do mês (1 ou 2)
      selectedDates.forEach(function(ds){
        var day = +ds.split("-")[2];
        var inp = document.createElement("input");
        inp.type="hidden"; inp.name="month_days[]"; inp.value=day;
        box.appendChild(inp);
      });
    } else {
      // envia posição + dia da semana para cada data escolhida
      selectedDates.forEach(function(ds){
        var pos = weekdayPosition(ds);
        var posVal = pos.isLast ? "last" : String(pos.nth);
        var wdVal = pos.weekday === 0 ? 7 : pos.weekday; // converte dom=0 -> 7
        var ip = document.createElement("input");
        ip.type="hidden"; ip.name="week_position[]"; ip.value=posVal;
        box.appendChild(ip);
        var iw = document.createElement("input");
        iw.type="hidden"; iw.name="week_weekday[]"; iw.value=wdVal;
        box.appendChild(iw);
      });
    }
    // também guardar a primeira data como referência
    if(selectedDates.length > 0){
      var ref = document.createElement("input");
      ref.type="hidden"; ref.name="start_date_ref"; ref.value=selectedDates[0];
      box.appendChild(ref);
    }
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
      maxDates = isQ ? 2 : 1;
      selectedDates = []; // reseta ao trocar
      var title = g("monthTitle");
      var hint = g("monthHint");
      if(isQ){
        if(title) title.textContent = "Quinzenal — escolha 2 datas no mês";
        if(hint) hint.textContent = "Clique em 2 dias no calendário (ex.: dia 5 e dia 20). O padrão se repete todo mês.";
      } else {
        if(title) title.textContent = "Mensal — escolha a data";
        if(hint) hint.textContent = "Clique no dia do calendário. Depois escolha se repete pelo dia do mês ou pela posição (ex.: 1ª quinta-feira).";
      }
      renderCalendar();
      renderSelectedInfo();
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
    var now = new Date();
    calYear = now.getFullYear();
    calMonth = now.getMonth();

    var fs = g("freqSelect");
    if(fs) fs.addEventListener("change", render);

    var prev = g("calPrev");
    var next = g("calNext");
    if(prev) prev.addEventListener("click", function(){ calMonth--; if(calMonth<0){calMonth=11;calYear--;} renderCalendar(); });
    if(next) next.addEventListener("click", function(){ calMonth++; if(calMonth>11){calMonth=0;calYear++;} renderCalendar(); });

    var rps = document.querySelectorAll('input[name="repeat_pattern"]');
    for(var i=0;i<rps.length;i++){
      rps[i].addEventListener("change", function(){ updateRpCards(); buildHiddenInputs(); });
    }

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
          var need = (freq === "quinzenal") ? 2 : 1;
          if(selectedDates.length < need){
            e.preventDefault();
            if(errDiv){ errDiv.style.display="block"; errDiv.textContent="Selecione " + need + " data(s) no calendário."; }
            return false;
          }
          buildHiddenInputs();
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
