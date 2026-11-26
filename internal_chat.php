<?php

declare(strict_types=1);

/**
 * CHAT INTERNO — conversa entre a equipe, dentro do sistema (sem WhatsApp).
 * Lista de conversas + conversa aberta; no celular, uma tela de cada vez.
 * Dados pela API internal_chat_api.php (atualiza a cada 3 s com a conversa aberta).
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/internal_chat.php';

auth_require_login();
$uid = (int)auth_user_id();
if (!ichat_user_allowed($uid)) {
    flash_set('error', 'O chat interno é só para a equipe.');
    header('Location: /dashboard.php');
    exit;
}
ichat_ensure_schema();
$openId = (int)($_GET['c'] ?? 0);

view_header('Chat Interno');
session_release();
?>
<style>
.icWrap{display:flex;height:calc(100vh - 110px);min-height:420px;border:1px solid hsl(var(--border));border-radius:16px;overflow:hidden;background:hsl(var(--card));box-shadow:var(--shadow-card)}
.icList{width:340px;flex:0 0 340px;border-right:1px solid hsl(var(--border));display:flex;flex-direction:column;min-width:0;background:hsl(var(--card))}
.icListHead{padding:12px;border-bottom:1px solid hsl(var(--border));display:grid;gap:8px}
.icListHead h1{margin:0;font-size:18px;font-weight:900;display:flex;align-items:center;justify-content:space-between;gap:8px}
.icSearch{font-size:15px !important;padding:9px 12px !important}
.icNewBtns{display:flex;gap:6px}
.icNewBtns .btn{flex:1;font-size:13px;padding:8px 10px}
.icConvs{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch}
.icConv{display:flex;gap:10px;align-items:center;padding:11px 12px;border-bottom:1px solid hsl(var(--border));cursor:pointer;text-decoration:none;color:inherit}
.icConv:hover{background:hsl(var(--accent));text-decoration:none}
.icConv.isActive{background:hsla(var(--primary)/.10)}
.icAv{width:42px;height:42px;border-radius:50%;flex:0 0 42px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:14px}
.icConvMain{flex:1;min-width:0}
.icConvTop{display:flex;justify-content:space-between;gap:8px;align-items:baseline}
.icConvTitle{font-weight:700;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.icConvTime{font-size:11px;color:hsl(var(--muted-foreground));flex:0 0 auto}
.icConvPrev{font-size:13px;color:hsl(var(--muted-foreground));white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;justify-content:space-between;gap:6px}
.icBadge{background:hsl(var(--primary));color:#fff;border-radius:999px;font-size:11px;font-weight:800;min-width:20px;height:20px;padding:0 6px;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto}
.icMain{flex:1;display:flex;flex-direction:column;min-width:0;background:#efeae2}
.icHead{display:flex;align-items:center;gap:10px;padding:10px 12px;background:hsl(var(--card));border-bottom:1px solid hsl(var(--border));min-height:60px}
.icHeadTitle{flex:1;min-width:0}
.icHeadTitle b{display:block;font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.icHeadTitle span{font-size:12px;color:hsl(var(--muted-foreground));white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block}
.icBack{display:none;border:0;background:transparent;font-size:24px;line-height:1;padding:4px 8px;cursor:pointer}
.icIconBtn{border:1px solid hsl(var(--border));background:hsl(var(--card));border-radius:10px;padding:7px 10px;cursor:pointer;font-size:15px}
.icMsgs{flex:1;overflow-y:auto;padding:14px 12px;display:flex;flex-direction:column;gap:4px;-webkit-overflow-scrolling:touch}
.icEmpty{margin:auto;text-align:center;color:hsl(var(--muted-foreground));padding:30px;font-size:15px}
.icDay{align-self:center;background:#e1f3fb;color:#54656f;font-size:12px;padding:3px 12px;border-radius:8px;margin:10px 0 6px}
.icSys{align-self:center;background:#fff3c4;color:#54656f;font-size:12px;padding:4px 12px;border-radius:8px;margin:4px 0;max-width:90%;text-align:center}
.icMsg{max-width:min(78%,560px);padding:6px 10px 4px;border-radius:10px;background:#fff;align-self:flex-start;box-shadow:0 1px .5px rgba(0,0,0,.13);position:relative;word-wrap:break-word;overflow-wrap:anywhere}
.icMsg.mine{align-self:flex-end;background:#d9fdd3}
.icMsg .icWho{font-size:12px;font-weight:700;color:#0b8a7a;margin-bottom:2px}
.icMsg .icText{font-size:15px;line-height:1.4;white-space:pre-wrap}
.icMsg .icMeta{font-size:11px;color:#667781;text-align:right;margin-top:2px;display:flex;gap:6px;justify-content:flex-end;align-items:center}
.icMsg.deleted .icText{font-style:italic;color:#667781}
.icReply{background:rgba(0,0,0,.05);border-left:4px solid #06cf9c;border-radius:6px;padding:4px 8px;margin-bottom:4px;font-size:12.5px;color:#54656f;cursor:pointer}
.icReply b{display:block;color:#06cf9c;font-size:12px}
.icImg{display:block;max-width:100%;max-height:300px;border-radius:8px;margin-bottom:4px;cursor:zoom-in}
.icFile{display:flex;gap:10px;align-items:center;padding:8px 10px;background:rgba(0,0,0,.04);border-radius:8px;margin-bottom:4px;color:inherit;text-decoration:none;min-width:200px}
.icFile:hover{text-decoration:none;background:rgba(0,0,0,.07)}
.icMsgActs{display:none;position:absolute;top:-14px;right:6px;background:#fff;border:1px solid hsl(var(--border));border-radius:999px;padding:1px 4px;box-shadow:var(--shadow-card)}
.icMsg:hover .icMsgActs,.icMsg.showActs .icMsgActs{display:flex}
.icMsgActs button{border:0;background:transparent;cursor:pointer;font-size:14px;padding:3px 6px}
.icCompose{background:hsl(var(--card));border-top:1px solid hsl(var(--border));padding:8px 10px calc(8px + env(safe-area-inset-bottom));display:grid;gap:6px}
.icReplyBar{display:none;align-items:center;gap:8px;background:hsl(var(--secondary));border-left:4px solid hsl(var(--primary));border-radius:8px;padding:6px 10px;font-size:13px}
.icReplyBar span{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.icRow{display:flex;gap:8px;align-items:flex-end}
.icRow textarea{flex:1;min-height:44px !important;max-height:160px;resize:none !important;font-size:16px !important;padding:10px 12px !important;line-height:1.35}
.icSend{flex:0 0 46px;height:46px;border-radius:50%;border:0;background:hsl(var(--primary));color:#fff;font-size:18px;cursor:pointer}
.icAttach{flex:0 0 44px;height:44px;border-radius:50%;border:1px solid hsl(var(--border));background:hsl(var(--card));font-size:19px;cursor:pointer}
.icModal{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:10050;padding:12px}
.icModal.show{display:flex}
.icModalBox{background:hsl(var(--card));border-radius:16px;width:100%;max-width:480px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden}
.icModalHead{padding:14px 16px;border-bottom:1px solid hsl(var(--border));display:flex;justify-content:space-between;align-items:center;font-weight:800;font-size:17px}
.icModalBody{padding:14px 16px;overflow-y:auto;display:grid;gap:10px}
.icModalFoot{padding:12px 16px;border-top:1px solid hsl(var(--border));display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.icPick{display:flex !important;gap:10px !important;align-items:center;padding:8px;border-radius:10px;cursor:pointer;font-weight:600 !important;font-size:15px !important}
.icPick:hover{background:hsl(var(--accent))}
.icMember{display:flex;gap:10px;align-items:center;padding:6px 0;border-bottom:1px solid hsl(var(--border))}
.icMember div{flex:1;min-width:0}
.icToast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#111827;color:#fff;padding:10px 16px;border-radius:10px;font-size:14px;z-index:10100;display:none;max-width:90vw}
@media(max-width:980px){.icWrap{height:calc(100vh - 140px)}}
@media(max-width:760px){
  .icWrap{border-radius:0;border-left:0;border-right:0;height:calc(100dvh - 68px - env(safe-area-inset-top));margin:-8px -12px calc(-20px - env(safe-area-inset-bottom))}
  .icList{width:100%;flex:1 1 auto;border-right:0}
  .icMain{display:none}
  .icWrap.hasConv .icList{display:none}
  .icWrap.hasConv .icMain{display:flex}
  .icBack{display:block}
  .icMsg{max-width:88%}
  .icMsgActs{top:-16px}
}
</style>

<div class="icWrap" id="icWrap">
  <aside class="icList">
    <div class="icListHead">
      <h1>Chat Interno <span id="icTotal" class="icBadge" style="display:none"></span></h1>
      <input type="search" id="icSearch" class="icSearch" placeholder="Buscar conversa ou pessoa…" autocomplete="off">
      <div class="icNewBtns">
        <button type="button" class="btn btnPrimary" onclick="icOpenNewDirect()">＋ Conversa</button>
        <button type="button" class="btn" onclick="icOpenNewGroup()">👥 Novo grupo</button>
      </div>
    </div>
    <div class="icConvs" id="icConvs"><div class="icEmpty">Carregando…</div></div>
  </aside>
  <section class="icMain" id="icMain">
    <div class="icHead" id="icHead" style="display:none">
      <button type="button" class="icBack" onclick="icCloseConv()" aria-label="Voltar">‹</button>
      <div class="icAv" id="icHeadAv"></div>
      <div class="icHeadTitle"><b id="icHeadTitle"></b><span id="icHeadSub"></span></div>
      <button type="button" class="icIconBtn" id="icInfoBtn" onclick="icOpenInfo()" title="Participantes">ⓘ</button>
    </div>
    <div class="icMsgs" id="icMsgs"><div class="icEmpty">Escolha uma conversa ao lado ou comece uma nova.<br><span style="font-size:13px">As mensagens ficam só dentro do sistema (não passam pelo WhatsApp).</span></div></div>
    <form class="icCompose" id="icCompose" style="display:none" onsubmit="icSend(event)">
      <div class="icReplyBar" id="icReplyBar"><span id="icReplyText"></span><button type="button" class="icIconBtn" onclick="icCancelReply()" aria-label="Cancelar resposta">✕</button></div>
      <div class="icRow">
        <button type="button" class="icAttach" onclick="document.getElementById('icFile').click()" title="Anexar arquivo">📎</button>
        <input type="file" id="icFile" style="display:none" onchange="icUpload(this)">
        <textarea id="icText" rows="1" placeholder="Mensagem" enterkeyhint="send"></textarea>
        <button type="submit" class="icSend" aria-label="Enviar">➤</button>
      </div>
    </form>
  </section>
</div>

<div class="icModal" id="icModal" onclick="if(event.target===this)icCloseModal()"><div class="icModalBox">
  <div class="icModalHead"><span id="icModalTitle"></span><button type="button" class="icIconBtn" onclick="icCloseModal()">✕</button></div>
  <div class="icModalBody" id="icModalBody"></div>
  <div class="icModalFoot" id="icModalFoot"></div>
</div></div>
<div class="icToast" id="icToast"></div>

<script>
(function () {
  var API = '/internal_chat_api.php';
  var S = { me: null, users: [], convs: [], open: null, members: [], lastId: 0, firstId: 0, hasMore: false, replyTo: null, timer: null, listTimer: null, editing: null };
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; };
  var colors = ['#0ea5a4', '#6366f1', '#f97316', '#db2777', '#16a34a', '#0284c7', '#9333ea', '#ca8a04'];
  var avatar = function (name, id) { var p = String(name || '?').trim().split(/\s+/); var ini = (p[0] || '?').charAt(0) + (p.length > 1 ? p[p.length - 1].charAt(0) : ''); return { ini: ini.toUpperCase(), color: colors[Math.abs(+id || 0) % colors.length] }; };
  var toast = function (t) { var el = $('icToast'); el.textContent = t; el.style.display = 'block'; clearTimeout(el._t); el._t = setTimeout(function () { el.style.display = 'none'; }, 3500); };
  var fmtTime = function (sql) { if (!sql) return ''; var d = new Date(sql.replace(' ', 'T')); var now = new Date(); if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }); var y = new Date(now); y.setDate(now.getDate() - 1); if (d.toDateString() === y.toDateString()) return 'ontem'; return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }); };
  var dayLabel = function (ts) { var d = new Date(ts * 1000), now = new Date(); if (d.toDateString() === now.toDateString()) return 'Hoje'; var y = new Date(now); y.setDate(now.getDate() - 1); if (d.toDateString() === y.toDateString()) return 'Ontem'; return d.toLocaleDateString('pt-BR'); };
  var linkify = function (html) { return html.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>'); };
  var sizeTxt = function (b) { return b > 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; };

  function api(action, params, post, file) {
    var opts = { credentials: 'same-origin', cache: 'no-store' };
    var url = API + '?action=' + encodeURIComponent(action);
    if (post) {
      var fd = new FormData(); fd.append('action', action);
      Object.keys(params || {}).forEach(function (k) { var v = params[k]; if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); }); else fd.append(k, v); });
      if (file) fd.append('file', file);
      opts.method = 'POST'; opts.body = fd;
    } else {
      Object.keys(params || {}).forEach(function (k) { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); });
    }
    return fetch(url, opts).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Resposta inesperada do servidor (HTTP ' + r.status + ').' }; }); });
  }

  // ---------- Lista ----------
  function renderList() {
    var q = ($('icSearch').value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    var norm = function (s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); };
    var html = '';
    var total = 0;
    S.convs.forEach(function (c) {
      total += c.unread;
      if (q && norm(c.title).indexOf(q) === -1) return;
      var av = avatar(c.title, c.type === 'group' ? c.id + 3 : c.other_user_id);
      html += '<a class="icConv' + (S.open && S.open.id === c.id ? ' isActive' : '') + '" href="?c=' + c.id + '" data-id="' + c.id + '">'
        + '<div class="icAv" style="background:' + av.color + '">' + (c.type === 'group' ? '👥' : esc(av.ini)) + '</div>'
        + '<div class="icConvMain"><div class="icConvTop"><span class="icConvTitle">' + esc(c.title) + '</span><span class="icConvTime">' + esc(fmtTime(c.last_at)) + '</span></div>'
        + '<div class="icConvPrev"><span style="overflow:hidden;text-overflow:ellipsis">' + esc(c.preview || (c.type === 'group' ? 'Grupo criado' : 'Nenhuma mensagem ainda')) + '</span>'
        + (c.unread > 0 ? '<span class="icBadge">' + (c.unread > 99 ? '99+' : c.unread) + '</span>' : '') + '</div></div></a>';
    });
    // Pessoas sem conversa aparecem na busca
    if (q) {
      S.users.forEach(function (u) {
        if (norm(u.name).indexOf(q) === -1) return;
        if (S.convs.some(function (c) { return c.type === 'direct' && c.other_user_id === u.id; })) return;
        var av = avatar(u.name, u.id);
        html += '<a class="icConv" href="#" data-user="' + u.id + '"><div class="icAv" style="background:' + av.color + '">' + esc(av.ini) + '</div><div class="icConvMain"><div class="icConvTitle">' + esc(u.name) + '</div><div class="icConvPrev">Começar conversa</div></div></a>';
      });
    }
    $('icConvs').innerHTML = html || '<div class="icEmpty">' + (q ? 'Nada encontrado.' : 'Nenhuma conversa ainda.<br>Toque em "＋ Conversa" para começar.') + '</div>';
    var tb = $('icTotal'); tb.style.display = total > 0 ? '' : 'none'; tb.textContent = total > 99 ? '99+' : total;
    window.mlSetInternalChatBadge && window.mlSetInternalChatBadge(total);
    document.title = (total > 0 ? '(' + total + ') ' : '') + 'Chat Interno';
  }
  $('icConvs').addEventListener('click', function (e) {
    var a = e.target.closest('.icConv'); if (!a) return;
    e.preventDefault();
    if (a.dataset.user) { startDirect(+a.dataset.user); return; }
    openConv(+a.dataset.id);
  });
  $('icSearch').addEventListener('input', renderList);

  function loadList() {
    return api('conversations').then(function (d) { if (d.ok) { S.convs = d.conversations; renderList(); } });
  }

  // ---------- Conversa ----------
  function convTitle() { var c = S.convs.find(function (x) { return x.id === S.open.id; }); return c ? c.title : (S.open.title || 'Conversa'); }
  function openConv(id, keepUrl) {
    stopPoll();
    S.open = { id: id }; S.lastId = 0; S.firstId = 0; S.replyTo = null; lastDay = ''; icCancelReply();
    $('icWrap').classList.add('hasConv');
    $('icMsgs').innerHTML = '<div class="icEmpty">Carregando…</div>';
    $('icHead').style.display = ''; $('icCompose').style.display = '';
    if (!keepUrl) history.replaceState(null, '', '?c=' + id);
    renderList();
    api('messages', { conversation_id: id }).then(function (d) {
      if (!d.ok) { toast(d.error); icCloseConv(); return; }
      S.open = d.conversation; S.members = d.members; S.hasMore = d.has_more;
      renderHead();
      $('icMsgs').innerHTML = '';
      if (d.has_more) $('icMsgs').insertAdjacentHTML('afterbegin', '<button type="button" class="btn" id="icMore" style="align-self:center;font-size:13px" onclick="icLoadMore()">Mensagens anteriores</button>');
      if (!d.messages.length) $('icMsgs').insertAdjacentHTML('beforeend', '<div class="icEmpty" id="icNoMsg">Nenhuma mensagem ainda. Diga oi! 👋</div>');
      d.messages.forEach(function (m) { appendMsg(m); });
      if (d.messages.length) S.firstId = d.messages[0].id;
      scrollBottom(true); markRead(); startPoll();
      if (window.innerWidth > 760) $('icText').focus();
    });
  }
  function renderHead() {
    var title = S.open.type === 'group' ? (S.open.title || 'Grupo') : convTitle();
    var other = S.open.type === 'direct' ? S.members.find(function (m) { return m.id !== S.me.id; }) : null;
    var av = avatar(title, S.open.type === 'group' ? S.open.id + 3 : (other ? other.id : 0));
    $('icHeadAv').style.background = av.color; $('icHeadAv').innerHTML = S.open.type === 'group' ? '👥' : esc(av.ini);
    $('icHeadTitle').textContent = title;
    $('icHeadSub').textContent = S.open.type === 'group' ? S.members.map(function (m) { return m.id === S.me.id ? 'você' : m.name.split(' ')[0]; }).join(', ') : (other ? other.email : '');
  }
  window.icCloseConv = function () { stopPoll(); S.open = null; $('icWrap').classList.remove('hasConv'); history.replaceState(null, '', location.pathname); renderList(); };

  var lastDay = '';
  function appendMsg(m, prepend) {
    var box = $('icMsgs');
    var noMsg = $('icNoMsg'); if (noMsg) noMsg.remove();
    if (box.querySelector('[data-mid="' + m.id + '"]')) return;
    var html = '';
    var day = dayLabel(m.ts);
    if (!prepend && day !== lastDay) { html += '<div class="icDay">' + esc(day) + '</div>'; lastDay = day; }
    if (m.kind === 'system') {
      html += '<div class="icSys" data-mid="' + m.id + '">' + esc(m.body) + '</div>';
    } else {
      html += msgHtml(m);
    }
    if (prepend) {
      var more = $('icMore');
      if (more) more.insertAdjacentHTML('afterend', html); else box.insertAdjacentHTML('afterbegin', html);
    } else {
      box.insertAdjacentHTML('beforeend', html);
    }
    if (m.id > S.lastId) S.lastId = m.id;
  }
  function msgHtml(m) {
    var isGroup = S.open && S.open.type === 'group';
    var h = '<div class="icMsg' + (m.mine ? ' mine' : '') + (m.deleted ? ' deleted' : '') + '" data-mid="' + m.id + '">';
    if (!m.deleted) {
      h += '<div class="icMsgActs"><button type="button" title="Responder" onclick="icReply(' + m.id + ')">↩</button>'
        + (m.mine && m.kind === 'text' ? '<button type="button" title="Editar" onclick="icEdit(' + m.id + ')">✎</button>' : '')
        + (m.mine ? '<button type="button" title="Apagar" onclick="icDelete(' + m.id + ')">🗑</button>' : '') + '</div>';
    }
    if (isGroup && !m.mine) h += '<div class="icWho">' + esc(m.user_name) + '</div>';
    if (m.reply_to && !m.deleted) h += '<div class="icReply" onclick="icJump(' + m.reply_to.id + ')"><b>' + esc(m.reply_to.user_name || '') + '</b>' + esc(m.reply_to.body) + '</div>';
    if (m.deleted) {
      h += '<div class="icText">🚫 mensagem apagada</div>';
    } else {
      if (m.file && m.kind === 'image') h += '<a href="' + esc(m.file.url) + '&inline=1" target="_blank" rel="noopener"><img class="icImg" src="' + esc(m.file.url) + '&inline=1" alt="' + esc(m.file.name) + '" loading="lazy"></a>';
      else if (m.file) h += '<a class="icFile" href="' + esc(m.file.url) + '"><span style="font-size:24px">📄</span><span style="min-width:0"><b style="display:block;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(m.file.name) + '</b><span style="font-size:11px;color:#667781">' + esc(sizeTxt(m.file.size)) + ' · baixar</span></span></a>';
      if (m.body) h += '<div class="icText">' + linkify(esc(m.body)) + '</div>';
    }
    var d = new Date(m.ts * 1000);
    h += '<div class="icMeta">' + (m.edited && !m.deleted ? '<span>editada</span>' : '') + '<span>' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) + '</span>' + (m.mine ? '<span>' + readMark(m) + '</span>' : '') + '</div>';
    return h + '</div>';
  }
  function readMark(m) {
    var others = S.members.filter(function (x) { return x.id !== S.me.id; });
    if (!others.length) return '✓';
    var all = others.every(function (x) { return x.last_read_id >= m.id; });
    return all ? '<span style="color:#53bdeb" title="Lida">✓✓</span>' : '<span title="Enviada">✓</span>';
  }
  function refreshReadMarks() {
    document.querySelectorAll('.icMsg.mine').forEach(function (el) {
      var span = el.querySelector('.icMeta span:last-child'); if (!span) return;
      span.innerHTML = readMark({ id: +el.dataset.mid });
    });
  }
  function replaceMsg(m) { var el = $('icMsgs').querySelector('[data-mid="' + m.id + '"]'); if (el) el.outerHTML = msgHtml(m); }
  function scrollBottom(force) { var b = $('icMsgs'); if (force || b.scrollHeight - b.scrollTop - b.clientHeight < 120) b.scrollTop = b.scrollHeight; }
  window.icLoadMore = function () {
    api('messages', { conversation_id: S.open.id, before_id: S.firstId }).then(function (d) {
      if (!d.ok) return;
      var box = $('icMsgs'), h0 = box.scrollHeight;
      d.messages.slice().reverse().forEach(function (m) { appendMsg(m, true); });
      if (d.messages.length) S.firstId = d.messages[0].id;
      if (!d.has_more && $('icMore')) $('icMore').remove();
      box.scrollTop = box.scrollHeight - h0;
    });
  };
  window.icJump = function (id) { var el = $('icMsgs').querySelector('[data-mid="' + id + '"]'); if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.style.outline = '2px solid #06cf9c'; setTimeout(function () { el.style.outline = ''; }, 1500); } };

  // ---------- Envio ----------
  var ta = $('icText');
  ta.addEventListener('input', function () { ta.style.height = 'auto'; ta.style.height = Math.min(160, ta.scrollHeight) + 'px'; });
  ta.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && window.matchMedia('(pointer:fine)').matches) { e.preventDefault(); icSend(e); }
    if (e.key === 'Escape') { icCancelReply(); }
  });
  window.icSend = function (e) {
    if (e) e.preventDefault();
    var body = ta.value.trim(); if (!body || !S.open) return;
    if (S.editing) {
      var mid = S.editing; S.editing = null;
      api('edit', { message_id: mid, body: body }, true).then(function (d) { if (d.ok) replaceMsg(d.message); else toast(d.error); });
      ta.value = ''; ta.style.height = ''; icCancelReply(); return;
    }
    var params = { conversation_id: S.open.id, body: body };
    if (S.replyTo) params.reply_to_id = S.replyTo;
    ta.value = ''; ta.style.height = ''; icCancelReply();
    api('send', params, true).then(function (d) {
      if (!d.ok) { toast(d.error); ta.value = body; return; }
      appendMsg(d.message); scrollBottom(true); loadList();
    }).catch(function () { toast('Sem conexão: a mensagem não foi enviada.'); ta.value = body; });
  };
  window.icUpload = function (input) {
    var f = input.files && input.files[0]; input.value = '';
    if (!f || !S.open) return;
    if (f.size > S.maxMb * 1048576) { toast('O arquivo passa de ' + S.maxMb + ' MB.'); return; }
    var params = { conversation_id: S.open.id, body: ta.value.trim() };
    if (S.replyTo) params.reply_to_id = S.replyTo;
    toast('Enviando ' + f.name + '…');
    api('upload', params, true, f).then(function (d) {
      if (!d.ok) { toast(d.error); return; }
      ta.value = ''; icCancelReply(); appendMsg(d.message); scrollBottom(true); loadList(); $('icToast').style.display = 'none';
    });
  };
  window.icReply = function (id) {
    var el = $('icMsgs').querySelector('[data-mid="' + id + '"]'); if (!el) return;
    S.replyTo = id; S.editing = null;
    var who = el.classList.contains('mine') ? 'Você' : ((el.querySelector('.icWho') || {}).textContent || convTitle());
    var t = (el.querySelector('.icText') || el.querySelector('.icFile b') || {}).textContent || 'arquivo';
    $('icReplyText').innerHTML = '<b>Respondendo ' + esc(who) + ':</b> ' + esc(t);
    $('icReplyBar').style.display = 'flex'; ta.focus();
  };
  window.icEdit = function (id) {
    var el = $('icMsgs').querySelector('[data-mid="' + id + '"] .icText'); if (!el) return;
    S.editing = id; S.replyTo = null;
    ta.value = el.textContent; ta.dispatchEvent(new Event('input'));
    $('icReplyText').innerHTML = '<b>Editando mensagem</b>'; $('icReplyBar').style.display = 'flex'; ta.focus();
  };
  window.icDelete = function (id) {
    if (!confirm('Apagar esta mensagem para todos da conversa?')) return;
    api('delete', { message_id: id }, true).then(function (d) { if (d.ok) { replaceMsg(d.message); loadList(); } else toast(d.error); });
  };
  window.icCancelReply = function () { S.replyTo = null; if (S.editing) { S.editing = null; ta.value = ''; } $('icReplyBar').style.display = 'none'; };
  // Celular: tocar na mensagem mostra as ações
  $('icMsgs').addEventListener('click', function (e) {
    var m = e.target.closest('.icMsg'); document.querySelectorAll('.icMsg.showActs').forEach(function (x) { if (x !== m) x.classList.remove('showActs'); });
    if (m && !e.target.closest('a,button')) m.classList.toggle('showActs');
  });

  // ---------- Atualização ----------
  function markRead() { if (S.open && S.lastId) api('mark_read', { conversation_id: S.open.id, last_id: S.lastId }, true).then(function () { var c = S.convs.find(function (x) { return x.id === S.open.id; }); if (c) { c.unread = 0; renderList(); } }); }
  function poll() {
    if (!S.open) return;
    var id = S.open.id;
    api('poll', { conversation_id: id, after_id: S.lastId, with_list: 1 }).then(function (d) {
      if (!d.ok || !S.open || S.open.id !== id) return;
      var had = S.lastId;
      (d.messages || []).forEach(function (m) { appendMsg(m); });
      (d.changed || []).forEach(replaceMsg);
      if (d.members) { S.members = d.members; refreshReadMarks(); }
      if (d.conversations) { S.convs = d.conversations; renderList(); }
      if (S.lastId > had) { scrollBottom(false); if (!document.hidden) markRead(); notify(d.messages); }
    }).finally(function () { S.timer = setTimeout(poll, document.hidden ? 10000 : 3000); });
  }
  function startPoll() { stopPoll(); S.timer = setTimeout(poll, 3000); }
  function stopPoll() { if (S.timer) clearTimeout(S.timer); S.timer = null; }
  // Sem conversa aberta: lista a cada 8 s
  setInterval(function () { if (!S.open) loadList(); }, 8000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden && S.open) markRead(); });
  function notify(msgs) {
    if (!document.hidden || !('Notification' in window) || Notification.permission !== 'granted') return;
    (msgs || []).filter(function (m) { return !m.mine && m.kind !== 'system'; }).slice(-1).forEach(function (m) {
      try { new Notification(m.user_name || 'Chat Interno', { body: m.body || (m.file ? m.file.name : ''), tag: 'ichat-' + m.conversation_id, icon: '/pwa/icon-192.png' }); } catch (e) {}
    });
  }

  // ---------- Modais ----------
  function modal(title, body, foot) { $('icModalTitle').textContent = title; $('icModalBody').innerHTML = body; $('icModalFoot').innerHTML = foot || ''; $('icModal').classList.add('show'); }
  window.icCloseModal = function () { $('icModal').classList.remove('show'); };
  function userPicker(type, exclude) {
    var h = '<input type="search" id="icPickSearch" placeholder="Buscar pessoa…" oninput="icFilterPick()" autocomplete="off">';
    h += '<div id="icPickList" style="display:grid;gap:2px;max-height:50vh;overflow-y:auto">';
    S.users.forEach(function (u) {
      if (exclude && exclude.indexOf(u.id) !== -1) return;
      var av = avatar(u.name, u.id);
      h += '<label class="icPick" data-name="' + esc(u.name.toLowerCase()) + '"><input type="' + type + '" name="icPick" value="' + u.id + '">'
        + '<span class="icAv" style="width:34px;height:34px;flex-basis:34px;font-size:12px;background:' + av.color + '">' + esc(av.ini) + '</span><span style="min-width:0">' + esc(u.name) + '<span class="helpText" style="display:block;font-weight:400">' + esc(u.email) + '</span></span></label>';
    });
    return h + '</div>';
  }
  window.icFilterPick = function () { var q = $('icPickSearch').value.toLowerCase(); document.querySelectorAll('#icPickList .icPick').forEach(function (l) { l.style.setProperty('display', l.dataset.name.indexOf(q) === -1 ? 'none' : 'flex', 'important'); }); };
  var picked = function () { return Array.prototype.map.call(document.querySelectorAll('#icPickList input:checked'), function (i) { return +i.value; }); };
  function startDirect(userId) { api('start_direct', { user_id: userId }, true).then(function (d) { if (!d.ok) { toast(d.error); return; } icCloseModal(); $('icSearch').value = ''; loadList().then(function () { openConv(d.conversation_id); }); }); }
  window.icOpenNewDirect = function () {
    modal('Nova conversa', userPicker('radio'), '<button type="button" class="btn" onclick="icCloseModal()">Cancelar</button><button type="button" class="btn btnPrimary" onclick="icDoDirect()">Conversar</button>');
    document.querySelectorAll('#icPickList input').forEach(function (i) { i.addEventListener('change', function () { icDoDirect(); }); });
  };
  window.icDoDirect = function () { var p = picked(); if (!p.length) { toast('Escolha uma pessoa.'); return; } startDirect(p[0]); };
  window.icOpenNewGroup = function () {
    modal('Novo grupo', '<label>Nome do grupo<input id="icGroupName" maxlength="120" placeholder="ex.: Captação SP"></label><div style="font-weight:700">Participantes</div>' + userPicker('checkbox'),
      '<button type="button" class="btn" onclick="icCloseModal()">Cancelar</button><button type="button" class="btn btnPrimary" onclick="icDoGroup()">Criar grupo</button>');
  };
  window.icDoGroup = function () {
    var name = $('icGroupName').value.trim(), p = picked();
    if (!name) { toast('Dê um nome ao grupo.'); return; }
    if (!p.length) { toast('Escolha pelo menos uma pessoa.'); return; }
    api('create_group', { title: name, member_ids: p }, true).then(function (d) { if (!d.ok) { toast(d.error); return; } icCloseModal(); loadList().then(function () { openConv(d.conversation_id); }); });
  };
  window.icOpenInfo = function () {
    if (!S.open) return;
    var isGroup = S.open.type === 'group', isAdmin = S.open.my_role === 'admin';
    var h = '';
    if (isGroup && isAdmin) h += '<label>Nome do grupo<span style="display:flex;gap:8px"><input id="icRename" value="' + esc(S.open.title || '') + '" maxlength="120"><button type="button" class="btn" onclick="icDoRename()">Salvar</button></span></label>';
    h += '<div style="font-weight:700">' + S.members.length + ' participante(s)</div>';
    S.members.forEach(function (m) {
      var av = avatar(m.name, m.id);
      h += '<div class="icMember"><span class="icAv" style="width:34px;height:34px;flex-basis:34px;font-size:12px;background:' + av.color + '">' + esc(av.ini) + '</span><div><b>' + esc(m.id === S.me.id ? m.name + ' (você)' : m.name) + '</b><span class="helpText" style="display:block">' + esc(m.email) + (m.role === 'admin' && isGroup ? ' · administrador' : '') + '</span></div>'
        + (isGroup && isAdmin && m.id !== S.me.id ? (m.role !== 'admin' ? '<button type="button" class="btn" style="font-size:12px;padding:5px 8px" onclick="icMakeAdmin(' + m.id + ')">Tornar admin</button>' : '') + '<button type="button" class="btn" style="font-size:12px;padding:5px 8px;color:#b91c1c" onclick="icRemove(' + m.id + ')">Remover</button>' : '') + '</div>';
    });
    var foot = '<button type="button" class="btn" onclick="icCloseModal()">Fechar</button>';
    if (isGroup && isAdmin) foot = '<button type="button" class="btn" onclick="icOpenAdd()">＋ Adicionar pessoas</button>' + foot;
    if (isGroup) foot = '<button type="button" class="btn" style="color:#b91c1c" onclick="icLeave()">Sair do grupo</button>' + foot;
    modal(isGroup ? 'Dados do grupo' : 'Conversa', h, foot);
  };
  window.icDoRename = function () { var t = $('icRename').value.trim(); if (!t) return; api('rename', { conversation_id: S.open.id, title: t }, true).then(function (d) { if (!d.ok) { toast(d.error); return; } S.open.title = t; renderHead(); loadList(); toast('Nome salvo.'); }); };
  window.icRemove = function (uid) { if (!confirm('Remover esta pessoa do grupo?')) return; api('remove_member', { conversation_id: S.open.id, user_id: uid }, true).then(function (d) { if (!d.ok) { toast(d.error); return; } S.members = S.members.filter(function (m) { return m.id !== uid; }); renderHead(); icOpenInfo(); }); };
  window.icMakeAdmin = function (uid) { api('make_admin', { conversation_id: S.open.id, user_id: uid }, true).then(function (d) { if (!d.ok) { toast(d.error); return; } S.members.forEach(function (m) { if (m.id === uid) m.role = 'admin'; }); icOpenInfo(); }); };
  window.icOpenAdd = function () {
    modal('Adicionar ao grupo', userPicker('checkbox', S.members.map(function (m) { return m.id; })), '<button type="button" class="btn" onclick="icOpenInfo()">Voltar</button><button type="button" class="btn btnPrimary" onclick="icDoAdd()">Adicionar</button>');
  };
  window.icDoAdd = function () { var p = picked(); if (!p.length) { toast('Escolha pelo menos uma pessoa.'); return; } api('add_members', { conversation_id: S.open.id, member_ids: p }, true).then(function (d) { if (!d.ok) { toast(d.error); return; } icCloseModal(); openConv(S.open.id, true); }); };
  window.icLeave = function () { if (!confirm('Sair deste grupo? Você deixa de receber as mensagens.')) return; api('leave', { conversation_id: S.open.id }, true).then(function (d) { if (!d.ok) { toast(d.error); return; } icCloseModal(); icCloseConv(); loadList(); }); };

  // ---------- Início ----------
  api('bootstrap').then(function (d) {
    if (!d.ok) { $('icConvs').innerHTML = '<div class="icEmpty">' + esc(d.error) + '</div>'; return; }
    S.me = d.me; S.users = d.users; S.convs = d.conversations; S.maxMb = d.max_file_mb;
    renderList();
    var start = <?= (int)$openId ?>;
    if (start > 0) openConv(start, true);
    // Notificações do navegador (só pergunta uma vez, com o app aberto)
    if ('Notification' in window && Notification.permission === 'default') {
      setTimeout(function () { try { Notification.requestPermission(); } catch (e) {} }, 4000);
    }
  });
})();
</script>
<?php
view_footer();
