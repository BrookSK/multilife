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
    select._sf.input.value = '';
    rebuild(select, '');
  };

  /** Inclui uma opção nova (ex.: paciente recém-cadastrado) e seleciona. */
  window.selectFilterAddOption = function (select, value, text) {
    if (!select) return;
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
