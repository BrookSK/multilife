// Filtros da tela "Gerenciar Grupo" — aba Adicionar Participantes.
// Servido como arquivo estático para não ser afetado por qualquer saída/notice do PHP.
// As funções são globais e chamadas pelos atributos inline (oninput/onchange/onclick).

function _mgNormalizar(s) {
  s = (s == null ? '' : String(s)).toLowerCase();
  try { s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch (e) {}
  return s;
}

function _mgSoDigitos(s) {
  return (s == null ? '' : String(s)).replace(/[^0-9]/g, '');
}

function filtrarParticipantes() {
  var lista = document.getElementById('listaParticipantes');
  if (!lista) return;

  var fNome = document.getElementById('filtroNome');
  var fTel = document.getElementById('filtroTelefone');
  var fEsp = document.getElementById('filtroEspecialidade');
  var fCidade = document.getElementById('filtroCidade');

  var qNome = _mgNormalizar(fNome ? fNome.value : '');
  var qTel = _mgSoDigitos(fTel ? fTel.value : '');
  var qEsp = fEsp ? fEsp.value : '';
  var qCidade = _mgNormalizar(fCidade ? fCidade.value : '');

  var itens = lista.getElementsByClassName('participante-item');
  var visiveis = 0;

  for (var i = 0; i < itens.length; i++) {
    var item = itens[i];
    var nome = _mgNormalizar(item.getAttribute('data-nome'));
    var tel = _mgSoDigitos(item.getAttribute('data-telefone'));
    var esp = item.getAttribute('data-especialidade') || '';
    var cidade = _mgNormalizar(item.getAttribute('data-cidade'));

    var ok = true;
    if (qNome && nome.indexOf(qNome) === -1) ok = false;
    if (qTel && tel.indexOf(qTel) === -1) ok = false;
    if (qEsp && esp !== qEsp) ok = false;
    if (qCidade && cidade.indexOf(qCidade) === -1) ok = false;

    item.style.display = ok ? 'flex' : 'none';
    if (ok) visiveis++;
  }

  var contador = document.getElementById('contadorResultados');
  if (contador) contador.textContent = visiveis;

  var semResultados = document.getElementById('semResultados');
  if (semResultados) semResultados.style.display = (visiveis === 0) ? 'block' : 'none';
}

function contarSelecionadosParticipantes() {
  var lista = document.getElementById('listaParticipantes');
  if (!lista) return;
  var n = lista.querySelectorAll('.participante-check:checked').length;
  var el = document.getElementById('contadorSelecionados');
  if (el) el.textContent = n;
}

function selecionarVisiveis() {
  var lista = document.getElementById('listaParticipantes');
  if (!lista) return;
  var itens = lista.getElementsByClassName('participante-item');
  for (var i = 0; i < itens.length; i++) {
    if (itens[i].style.display !== 'none') {
      var cb = itens[i].querySelector('.participante-check');
      if (cb) cb.checked = true;
    }
  }
  contarSelecionadosParticipantes();
}

function limparSelecaoParticipantes() {
  var lista = document.getElementById('listaParticipantes');
  if (!lista) return;
  var cbs = lista.querySelectorAll('.participante-check');
  for (var i = 0; i < cbs.length; i++) { cbs[i].checked = false; }
  contarSelecionadosParticipantes();
}
