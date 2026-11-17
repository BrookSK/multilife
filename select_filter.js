/**
 * Busca digitável para <select> longos (profissionais, pacientes).
 * Coloca um campo de texto acima do select: ao digitar, a lista é filtrada
 * (sem diferenciar acentos/maiúsculas) e a primeira opção encontrada é
 * selecionada. O select continua sendo o campo enviado no formulário.
 *
 * Uso: selectFilterAttach(document.getElementById('meuSelect'), 'Buscar...');
 *      selectFilterReset(select)  -> limpa a busca e volta todas as opções.
 */
(function () {
  function norm(s) {
    return String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  }

  function rebuild(select, term) {
    var st = select._sf;
    var current = select.value;
    var words = norm(term).split(/\s+/).filter(Boolean);
    select.innerHTML = '';
    var firstMatch = null;
    var count = 0;
    st.options.forEach(function (o) {
      var keep = o.value === '' || words.every(function (w) { return o.key.indexOf(w) !== -1; });
      if (!keep) return;
      var el = document.createElement('option');
      el.value = o.value;
      el.textContent = o.text;
      select.appendChild(el);
      if (o.value !== '') {
        count++;
        if (firstMatch === null) firstMatch = o.value;
      }
    });
    st.info.textContent = words.length ? (count + ' encontrado(s)') : '';
    var stillThere = Array.prototype.some.call(select.options, function (el) { return el.value === current && current !== ''; });
    var next = stillThere ? current : (words.length && firstMatch !== null ? firstMatch : '');
    select.value = next;
    if (next !== current) {
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  window.selectFilterAttach = function (select, placeholder) {
    if (!select || select._sf) return;
    var options = Array.prototype.map.call(select.options, function (el) {
      return { value: el.value, text: el.textContent, key: norm(el.textContent) };
    });
    var wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:6px';
    var input = document.createElement('input');
    input.type = 'search';
    input.placeholder = placeholder || 'Digite para buscar...';
    input.autocomplete = 'off';
    input.style.cssText = 'flex:1;width:100%;padding:10px 12px;border:1px solid #d1d7db;border-radius:8px;font-size:14px';
    var info = document.createElement('span');
    info.style.cssText = 'font-size:12px;color:#667781;white-space:nowrap';
    wrap.appendChild(input);
    wrap.appendChild(info);
    select.parentNode.insertBefore(wrap, select);
    select._sf = { options: options, input: input, info: info };

    var t;
    input.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () { rebuild(select, input.value); }, 120);
    });
    // Enter na busca não envia o formulário
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); rebuild(select, input.value); }
    });
  };

  window.selectFilterReset = function (select) {
    if (!select || !select._sf) return;
    if (select._sf.combo) { select._sf.refresh(); return; }
    select._sf.input.value = '';
    rebuild(select, '');
  };

  /**
   * Busca com lista de resultados (combobox) para <select> longos.
   * O select fica escondido e continua sendo o campo enviado. Ao digitar, mostra
   * até 50 resultados; cada palavra precisa aparecer no texto da opção. Números
   * são comparados só pelos dígitos (17991 acha "(17) 99123-4567"; CPF com ou
   * sem pontos). Setas + Enter escolhem; Esc fecha.
   *
   * Uso: selectComboAttach(document.getElementById('meuSelect'), 'Buscar...');
   */
  function digits(s) { return String(s || '').replace(/\D+/g, ''); }

  window.selectComboAttach = function (select, placeholder) {
    if (!select || select._sf) return;
    var options = [];
    Array.prototype.forEach.call(select.options, function (el) {
      if (el.value === '') return;
      options.push({ value: el.value, text: el.textContent, key: norm(el.textContent), dig: digits(el.textContent) });
    });
    var wrap = document.createElement('div');
    wrap.style.cssText = 'position:relative;width:100%';
    var input = document.createElement('input');
    input.type = 'search';
    input.placeholder = placeholder || 'Digite para buscar...';
    input.autocomplete = 'off';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'false');
    input.style.cssText = 'width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #d1d7db;border-radius:8px;font-size:14px;background:#fff';
    var list = document.createElement('div');
    list.setAttribute('role', 'listbox');
    list.style.cssText = 'display:none;position:absolute;left:0;right:0;top:100%;margin-top:4px;max-height:280px;overflow-y:auto;background:#fff;border:1px solid #d1d7db;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:30';
    var info = document.createElement('div');
    info.style.cssText = 'font-size:12px;color:#667781;margin-top:4px;min-height:16px';
    wrap.appendChild(input);
    wrap.appendChild(list);
    select.parentNode.insertBefore(wrap, select);
    wrap.parentNode.insertBefore(info, wrap.nextSibling);
    select.style.display = 'none';
    select.removeAttribute('required'); // escondido não pode ser "required": a validação fica no envio

    var st = { options: options, input: input, info: info, list: list, combo: true, active: -1, shown: [] };
    select._sf = st;

    function labelOf(value) {
      for (var i = 0; i < st.options.length; i++) { if (st.options[i].value === String(value)) return st.options[i].text; }
      return '';
    }
    function showSelected() {
      var lbl = select.value ? labelOf(select.value) : '';
      input.value = lbl;
      info.textContent = lbl ? '✓ Selecionado' : '';
      info.style.color = lbl ? '#00a884' : '#667781';
    }
    function close() { list.style.display = 'none'; input.setAttribute('aria-expanded', 'false'); st.active = -1; }
    function choose(o) {
      var exists = Array.prototype.some.call(select.options, function (el) { return el.value === o.value; });
      if (!exists) { var el = document.createElement('option'); el.value = o.value; el.textContent = o.text; select.appendChild(el); }
      select.value = o.value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      showSelected();
      close();
    }
    function highlight(i) {
      var items = list.children;
      for (var k = 0; k < items.length; k++) { items[k].style.background = k === i ? '#e7f8f4' : ''; }
      st.active = i;
      if (items[i] && items[i].scrollIntoView) items[i].scrollIntoView({ block: 'nearest' });
    }
    function render() {
      var term = input.value;
      var words = norm(term).split(/\s+/).filter(Boolean);
      var matches = st.options.filter(function (o) {
        return words.every(function (w) {
          if (o.key.indexOf(w) !== -1) return true;
          var dw = digits(w);
          return dw.length >= 3 && dw === w.replace(/[\s().\-\/]/g, '') && o.dig.indexOf(dw) !== -1;
        });
      });
      st.shown = matches.slice(0, 50);
      list.innerHTML = '';
      if (st.shown.length === 0) {
        var empty = document.createElement('div');
        empty.textContent = 'Nenhum resultado. Use o cadastro rápido se for um paciente novo.';
        empty.style.cssText = 'padding:10px 12px;font-size:13px;color:#667781';
        list.appendChild(empty);
      }
      st.shown.forEach(function (o, i) {
        var item = document.createElement('div');
        item.setAttribute('role', 'option');
        item.textContent = o.text;
        item.style.cssText = 'padding:9px 12px;font-size:14px;cursor:pointer;border-bottom:1px solid #f0f2f5;line-height:1.35';
        if (o.value === select.value) item.style.fontWeight = '700';
        item.addEventListener('mousedown', function (e) { e.preventDefault(); choose(o); });
        item.addEventListener('mouseenter', function () { highlight(i); });
        list.appendChild(item);
      });
      info.style.color = '#667781';
      info.textContent = words.length ? (matches.length + ' encontrado(s)' + (matches.length > 50 ? ' · mostrando 50, refine a busca' : '')) : (st.options.length + ' cadastrado(s) · digite para filtrar');
      list.style.display = 'block';
      input.setAttribute('aria-expanded', 'true');
      st.active = -1;
    }

    var t;
    input.addEventListener('focus', function () { input.select(); render(); });
    input.addEventListener('input', function () {
      if (select.value) { select.value = ''; select.dispatchEvent(new Event('change', { bubbles: true })); }
      clearTimeout(t);
      t = setTimeout(render, 100);
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); if (list.style.display === 'none') render(); highlight(Math.min(st.active + 1, st.shown.length - 1)); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(Math.max(st.active - 1, 0)); }
      else if (e.key === 'Enter') { e.preventDefault(); var o = st.shown[st.active >= 0 ? st.active : 0]; if (o && list.style.display !== 'none') choose(o); }
      else if (e.key === 'Escape') { close(); showSelected(); }
    });
    input.addEventListener('blur', function () { setTimeout(function () { close(); showSelected(); }, 150); });
    // Clique fora da busca fecha a lista.
    document.addEventListener('mousedown', function (e) {
      if (list.style.display !== 'none' && !wrap.contains(e.target)) { close(); showSelected(); }
    });
    // Seleção feita por fora (ex.: cadastro rápido): fecha a lista e mostra o escolhido.
    st.refresh = function () { close(); showSelected(); };
    showSelected();
  };

  /** Inclui uma opção nova (ex.: paciente recém-cadastrado) e seleciona. */
  window.selectFilterAddOption = function (select, value, text) {
    if (!select) return;
    if (select._sf && select._sf.combo) {
      var exists = select._sf.options.some(function (o) { return o.value === String(value); });
      if (!exists) select._sf.options.push({ value: String(value), text: text, key: norm(text), dig: digits(text) });
      var has = Array.prototype.some.call(select.options, function (el) { return el.value === String(value); });
      if (!has) { var el2 = document.createElement('option'); el2.value = String(value); el2.textContent = text; select.appendChild(el2); }
      select.value = String(value);
      select.dispatchEvent(new Event('change', { bubbles: true }));
      select._sf.refresh();
      return;
    }
    if (select._sf) {
      select._sf.options.push({ value: String(value), text: text, key: norm(text) });
      select._sf.input.value = '';
      rebuild(select, '');
    } else {
      var el = document.createElement('option');
      el.value = String(value);
      el.textContent = text;
      select.appendChild(el);
    }
    select.value = String(value);
    select.dispatchEvent(new Event('change', { bubbles: true }));
  };
})();
