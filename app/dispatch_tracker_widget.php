<?php

declare(strict_types=1);

/**
 * ACOMPANHAMENTO GLOBAL DA CAPTAÇÃO (rodapé de todas as telas).
 *
 * Antes o popup de progresso vivia só em demands_view.php e o run_id ficava
 * apenas na memória do JavaScript: ao recarregar ou trocar de página, o
 * acompanhamento sumia. Agora:
 *  - os runs em andamento ficam no localStorage e também são recuperados do
 *    servidor (demands_dispatch_status.php?active=1) a cada página;
 *  - ao terminar, mostra o resultado e SOME sozinho após alguns segundos.
 *
 * API JS: window.mlDispatchTracker.start(runId, titulo)
 */
function dispatch_tracker_render(): void
{
    $uid = auth_user_id();
    if ($uid === null || !rbac_user_can($uid, 'demands.manage')) {
        return;
    }
    ?>
<div id="mlDispatchBox" style="display:none;position:fixed;right:20px;bottom:20px;z-index:9999;width:380px;max-width:calc(100vw - 40px);flex-direction:column;gap:10px"></div>
<script>
(function(){
  var KEY = 'ml_dispatch_runs';
  var box = document.getElementById('mlDispatchBox');
  if (!box) return;
  var cards = {};      // runId -> elemento
  var finished = {};   // runId -> true (já mostrado como concluído)
  var hidden = {};     // runId -> true (usuário ocultou enquanto rodava; reaparece só com o resultado)
  var timer = null;
  var phasePercent = { queued: 10, starting: 20, creating_group: 40, syncing_members: 55, adding_members: 70, sending: 85, done: 100 };

  function load(){ try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch(e){ return {}; } }
  function save(m){ try { localStorage.setItem(KEY, JSON.stringify(m)); } catch(e){} }
  function remember(id, title){ var m = load(); m[id] = { title: title || '', t: Date.now() }; save(m); }
  function forget(id){ var m = load(); delete m[id]; save(m); }

  function esc(s){ var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }

  function ensureCard(id, title){
    if (cards[id]) return cards[id];
    var el = document.createElement('div');
    el.style.cssText = 'background:#fff;border:1px solid hsl(var(--border));border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.18);overflow:hidden';
    el.innerHTML =
      '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 14px;background:hsl(var(--primary));color:#fff">' +
        '<strong data-t style="font-size:13px">Captação em andamento…</strong>' +
        '<button type="button" data-x aria-label="Ocultar" style="background:transparent;border:0;color:#fff;font-size:18px;line-height:1;cursor:pointer">&times;</button>' +
      '</div>' +
      '<div style="padding:12px 14px">' +
        '<div data-sub style="font-size:12px;font-weight:700;margin-bottom:6px">' + esc(title) + '</div>' +
        '<div style="height:8px;background:hsl(var(--muted));border-radius:999px;overflow:hidden;margin-bottom:8px"><div data-bar style="height:100%;width:10%;background:hsl(var(--primary));transition:width .4s ease"></div></div>' +
        '<div data-m style="font-size:12px;color:hsl(var(--muted-foreground));line-height:1.5">Na fila…</div>' +
      '</div>';
    el.querySelector('[data-x]').addEventListener('click', function(){
      // Ocultar não cancela a captação; se ainda estiver rodando, volta na próxima página.
      el.remove(); delete cards[id];
      if (finished[id]) forget(id); else hidden[id] = true;
      refreshVisibility();
    });
    box.appendChild(el);
    cards[id] = el;
    refreshVisibility();
    return el;
  }
  function refreshVisibility(){ box.style.display = Object.keys(cards).length ? 'flex' : 'none'; }

  function render(run){
    var id = String(run.run_id);
    var stored = load()[id] || {};
    var title = run.demand_title ? ('#' + run.demand_id + ' — ' + run.demand_title) : (stored.title || ('Card #' + run.demand_id));
    if (hidden[id] && !run.done) return;
    var el = ensureCard(id, title);
    el.querySelector('[data-sub]').textContent = title;
    var bar = el.querySelector('[data-bar]'), msg = el.querySelector('[data-m]'), t = el.querySelector('[data-t]');
    if (!run.done) {
      bar.style.width = (phasePercent[run.phase] || 15) + '%';
      msg.textContent = run.phase_message || 'Processando…';
      t.textContent = 'Captação em andamento…';
      return;
    }
    if (finished[id]) return;
    finished[id] = true;
    bar.style.width = '100%';
    bar.style.background = run.success ? 'hsl(142 72% 42%)' : 'hsl(0 84% 60%)';
    t.textContent = run.success ? 'Captação enviada ✓' : 'Captação não concluída';
    msg.textContent = run.result_message || (run.success ? 'Captação enviada com sucesso.' : 'O disparo não foi concluído.');
    forget(id);
    // Sucesso some sozinho; falha fica até o usuário fechar (para ler o motivo).
    if (run.success) {
      setTimeout(function(){ if (cards[id]) { cards[id].remove(); delete cards[id]; refreshVisibility(); } }, 8000);
    }
    document.dispatchEvent(new CustomEvent('ml-dispatch-done', { detail: run }));
  }

  function poll(){
    var ids = Object.keys(load()).filter(function(id){ return !finished[id]; });
    var url = ids.length ? ('/demands_dispatch_status.php?run_ids=' + encodeURIComponent(ids.join(','))) : null;
    var p1 = url ? fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function(r){ return r.json(); }) : Promise.resolve({ ok: true, runs: [] });
    p1.then(function(j){
      if (!j || !j.ok) return;
      var seen = {};
      (j.runs || []).forEach(function(run){ seen[run.run_id] = true; render(run); });
      // ids guardados que o servidor não conhece mais: descarta.
      ids.forEach(function(id){ if (!seen[id]) forget(id); });
      schedule();
    }).catch(function(){ schedule(); });
  }
  function schedule(){
    clearTimeout(timer);
    var pending = Object.keys(load()).filter(function(id){ return !finished[id]; }).length;
    if (pending) timer = setTimeout(poll, 3000);
  }

  // Recupera do servidor captações em andamento deste usuário (ex.: após recarregar a página).
  fetch('/demands_dispatch_status.php?active=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (j && j.ok) (j.runs || []).forEach(function(run){ if (!load()[run.run_id]) remember(run.run_id, run.demand_title ? ('#' + run.demand_id + ' — ' + run.demand_title) : ''); });
      poll();
    })
    .catch(function(){ poll(); });

  window.mlDispatchTracker = {
    start: function(runId, title){
      remember(String(runId), title || '');
      ensureCard(String(runId), title || '');
      poll();
    }
  };
})();
</script>
<?php
}
