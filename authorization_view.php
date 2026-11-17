<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$authId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($authId <= 0) {
    flash_set('error', 'Autorização inválida.');
    header('Location: /authorization_list.php');
    exit;
}

authorization_requests_ensure_pending_patient_column();

// Paciente: o vinculado na autorização (aba Autorização); se não houver, o do
// atendimento já criado (autorizações antigas).
$stmt = db()->prepare(
    'SELECT ar.*, 
     d.id as demand_id, d.title as demand_title, d.specialty as demand_specialty, 
     d.location_city, d.location_state, d.origin_email, d.patient_name as demand_patient_name,
     u.id as professional_id, u.name as professional_name, u.email as professional_email, u.phone as professional_phone,
     p.id as linked_patient_id, p.full_name as patient_name, p.email as patient_email, p.whatsapp as patient_phone
     FROM authorization_requests ar
     INNER JOIN demands d ON d.id = ar.demand_id
     INNER JOIN users u ON u.id = ar.professional_user_id
     LEFT JOIN patients p ON p.deleted_at IS NULL AND p.id = COALESCE(NULLIF(ar.patient_id, 0),
          (SELECT patient_id FROM patient_assignments WHERE demand_id = d.id LIMIT 1))
     WHERE ar.id = :id'
);
$stmt->execute(['id' => $authId]);
$auth = $stmt->fetch();

if (!$auth) {
    flash_set('error', 'Autorização não encontrada.');
    header('Location: /authorization_list.php');
    exit;
}

// Buscar histórico
$stmt = db()->prepare(
    'SELECT h.*, u.name as user_name
     FROM authorization_request_history h
     LEFT JOIN users u ON u.id = h.user_id
     WHERE h.authorization_request_id = :id
     ORDER BY h.created_at DESC'
);
$stmt->execute(['id' => $authId]);
$history = $stmt->fetchAll();

$status = (string)$auth['status'];
$statusBadge = match($status) {
    'aguardando_autorizacao' => '<span style="background:hsla(var(--info)/.15);color:hsl(var(--info));padding:6px 12px;border-radius:8px;font-weight:700;font-size:13px">⏳ Aguardando Resposta</span>',
    'autorizacao_aprovada' => '<span style="background:hsla(var(--success)/.15);color:hsl(var(--success));padding:6px 12px;border-radius:8px;font-weight:700;font-size:13px">✅ Aprovada</span>',
    'autorizacao_negada' => '<span style="background:hsla(var(--destructive)/.15);color:hsl(var(--destructive));padding:6px 12px;border-radius:8px;font-weight:700;font-size:13px">❌ Negada</span>',
    'cancelada' => '<span style="background:hsla(var(--muted)/.15);color:hsl(var(--muted-foreground));padding:6px 12px;border-radius:8px;font-weight:700;font-size:13px">⛔ Cancelada</span>',
    default => '<span style="background:hsla(var(--muted)/.15);color:hsl(var(--muted-foreground));padding:6px 12px;border-radius:8px;font-weight:700;font-size:13px">' . h($status) . '</span>'
};

view_header('Autorização #' . $authId);

echo '<div class="pageHeader">';
echo '<div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:6px">Autorização de Proposta</div>';
echo '<h1>Proposta #' . $authId . ' ' . $statusBadge . '</h1>';
echo '</div>';
echo '<div class="pageHeaderActions">';
echo '<a href="/authorization_list.php" class="btn">← Voltar</a>';
echo '</div>';
echo '</div>';

// ITEM 6: Ação manual "Marcar como aprovada" quando o envio automático está
// desabilitado, ou quando a operadora aprovou por e-mail e falta o paciente.
$autoSendAuthView = (string)admin_setting_get('feature.auto_send_authorization', '1');
$pendingPatientApproval = (int)($auth['pending_patient_approval'] ?? 0) === 1;
$showApprovalForm = ($autoSendAuthView !== '1' || $pendingPatientApproval) && $status === 'aguardando_autorizacao';
// Paciente: escolhido (com busca) ou pré-cadastrado aqui, não mais no chat.
$canEditPatient = $status === 'aguardando_autorizacao';
$currentPid = !empty($auth['linked_patient_id']) ? (int)($auth['patient_id'] ?? 0) : 0;
$patientRows = [];
if ($canEditPatient) {
    $patientRows = db()->query("SELECT id, full_name, cpf, phone_primary, whatsapp, address_city, address_state
        FROM patients WHERE deleted_at IS NULL ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}
/** Texto da opção: nome · telefone · cidade/UF · CPF (a busca procura em tudo). */
$patientOptionLabel = static function (array $pr): string {
    $phone = trim((string)(($pr['whatsapp'] ?? '') ?: ($pr['phone_primary'] ?? '')));
    $city = trim((string)($pr['address_city'] ?? ''));
    if ($city !== '' && trim((string)($pr['address_state'] ?? '')) !== '') {
        $city .= '/' . strtoupper(trim((string)$pr['address_state']));
    }
    $cpf = trim((string)($pr['cpf'] ?? ''));
    $extra = array_filter([$phone, $city, $cpf !== '' ? 'CPF ' . $cpf : '']);
    return (string)$pr['full_name'] . ($extra ? ' · ' . implode(' · ', $extra) : '');
};
$renderPatientSelect = static function (string $selectId, string $formId) use ($patientRows, $currentPid, $patientOptionLabel): void {
    echo '<select name="patient_id" id="' . h($selectId) . '"' . ($formId !== '' ? ' form="' . h($formId) . '"' : '') . ' style="width:100%;padding:10px 12px;border:1px solid hsl(var(--border));border-radius:8px">';
    echo '<option value="">Selecione o paciente...</option>';
    foreach ($patientRows as $pr) {
        $sel = (int)$pr['id'] === $currentPid ? ' selected' : '';
        echo '<option value="' . (int)$pr['id'] . '"' . $sel . '>' . h($patientOptionLabel($pr)) . '</option>';
    }
    echo '</select>';
};

if ($showApprovalForm) {
    // Ao aprovar manualmente, o valor autorizado pela operadora/cliente é OBRIGATÓRIO,
    // e o paciente é escolhido ao lado dele.
    $mrTotalSessions = (int)($auth['total_sessions'] ?? 0);
    echo '<section class="card" style="margin-bottom:16px">';
    echo '<div style="font-weight:800;font-size:16px;margin-bottom:4px">✅ Resposta da operadora / cliente</div>';
    if ($pendingPatientApproval) {
        echo '<div style="padding:10px 12px;margin:8px 0 12px;border-radius:8px;background:hsla(var(--success)/.12);border:1px solid hsla(var(--success)/.35);font-size:13px">'
            . 'A operadora / cliente <strong>aprovou</strong> por e-mail. Selecione o paciente, informe o valor autorizado e clique em <strong>Marcar como aprovada</strong>.</div>';
    } else {
        echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:12px">Selecione o paciente (ou faça o cadastro rápido), informe o valor autorizado e marque como aprovada para seguir à Pré-Admissão.</div>';
    }
    echo '<form method="post" action="/authorization_mark_responded_post.php" id="authApproveForm">';
    echo '<input type="hidden" name="auth_id" value="' . $authId . '">';
    echo '<input type="hidden" name="total_sessions" value="' . $mrTotalSessions . '">';
    echo '<div class="authApproveGrid">';
    echo '<div><label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px">Paciente *</label>';
    $renderPatientSelect('authPatientSelect', '');
    echo '<button type="button" class="btn" onclick="openAuthQuickPatient()" style="margin-top:6px">➕ Cadastro rápido do paciente</button>';
    echo '</div>';
    echo '<div><label for="authAuthorizedValue" style="display:block;font-weight:600;font-size:13px;margin-bottom:6px">Valor autorizado (total) R$ *</label>';
    echo '<input type="number" name="authorized_value" id="authAuthorizedValue" required min="0.01" step="0.01" placeholder="0,00" title="Valor total autorizado pela operadora / cliente" style="width:100%;box-sizing:border-box;height:42px;padding:8px 12px;border:1px solid hsl(var(--border));border-radius:8px">';
    echo '</div>';
    echo '<div class="authApproveBtn"><button type="submit" class="btn btnPrimary" style="width:100%;height:42px">✅ Marcar como aprovada</button></div>';
    echo '</div>';
    echo '<div id="authApproveError" style="display:none;margin-top:8px;color:hsl(var(--destructive));font-size:13px;font-weight:600"></div>';
    echo '</form>';
    // Recusar: a operadora/cliente NÃO autorizou. Move o card para "Negativas".
    echo '<form method="post" action="/authorization_mark_denied_post.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid hsl(var(--border))" onsubmit="return confirm(\'Confirmar que a autorização foi RECUSADA? O card irá para Negativas.\')">';
    echo '<input type="hidden" name="auth_id" value="' . $authId . '">';
    echo '<input type="text" name="denial_reason" placeholder="Motivo da recusa (opcional)" title="Motivo informado pela operadora / cliente" style="flex:1;min-width:200px;height:40px;padding:8px 12px;border:1px solid hsl(var(--border));border-radius:8px">';
    echo '<button type="submit" class="btn" style="background:hsl(var(--destructive));color:#fff">❌ Marcar como recusada</button>';
    echo '</form>';
    echo '</section>';
}
echo '<style>.authApproveGrid{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr) auto;gap:12px;align-items:start}'
    . '.authApproveBtn{padding-top:25px}'
    . '@media (max-width:720px){.authApproveGrid{grid-template-columns:1fr}.authApproveBtn{padding-top:0}}</style>';

echo '<div class="grid">';

// Card principal com dados da proposta
echo '<section class="card col8">';
echo '<div class="formSection">';
echo '<div class="formSectionTitle">📋 Dados da Demanda</div>';
echo '<div class="grid">';
echo '<div class="col6"><strong>Demanda:</strong> #' . (int)$auth['demand_id'] . ' - ' . h((string)$auth['demand_title']) . '</div>';
echo '<div class="col6"><strong>Especialidade:</strong> ' . h((string)$auth['demand_specialty']) . '</div>';
$location = '';
if (!empty($auth['location_city'])) {
    $location = h((string)$auth['location_city']);
    if (!empty($auth['location_state'])) $location .= '/' . h((string)$auth['location_state']);
}
if ($location) {
    echo '<div class="col6"><strong>Localização:</strong> ' . $location . '</div>';
}
echo '<div class="col6"><strong>E-mail Origem:</strong> ' . h((string)$auth['origin_email']) . '</div>';
echo '</div>';
echo '</div>';

echo '<div class="formSection">';
echo '<div class="formSectionTitle">👨‍⚕️ Profissional Selecionado</div>';
echo '<div class="grid">';
echo '<div class="col6"><strong>Nome:</strong> ' . h((string)$auth['professional_name']) . '</div>';
echo '<div class="col6"><strong>E-mail:</strong> ' . h((string)$auth['professional_email']) . '</div>';
if (!empty($auth['professional_phone'])) {
    echo '<div class="col6"><strong>Telefone:</strong> ' . h((string)$auth['professional_phone']) . '</div>';
}
echo '</div>';
echo '</div>';

// Paciente: vinculado aqui (não mais no chat). Com o formulário de aprovação
// na tela, a escolha fica ao lado do valor autorizado; sem ele (envio
// automático, aguardando o e-mail), dá para vincular por aqui.
echo '<div class="formSection" id="pacienteSection">';
echo '<div class="formSectionTitle">👤 Paciente</div>';
$linkedContact = $currentPid > 0 ? patient_contact_fetch($currentPid) : [];
if (!empty($auth['patient_name'])) {
    $lcPhones = patient_format_phones($linkedContact);
    $lcAddress = patient_format_address($linkedContact);
    $lcFamily = patient_format_family($linkedContact);
    echo '<div class="grid">';
    echo '<div class="col6"><strong>Nome:</strong> ' . h((string)$auth['patient_name']) . '</div>';
    if (!empty($auth['patient_email'])) {
        echo '<div class="col6"><strong>E-mail:</strong> ' . h((string)$auth['patient_email']) . '</div>';
    }
    $phoneShown = $lcPhones !== '' ? $lcPhones : (string)($auth['patient_phone'] ?? '');
    if ($phoneShown !== '') {
        echo '<div class="col6"><strong>Telefone:</strong> ' . h($phoneShown) . '</div>';
    }
    if ($lcFamily !== '') {
        echo '<div class="col6"><strong>Contato da família:</strong> ' . h($lcFamily) . '</div>';
    }
    if ($lcAddress !== '') {
        echo '<div class="col12"><strong>Endereço:</strong> ' . h($lcAddress) . '</div>';
    }
    echo '</div>';
} else {
    $demandPatientName = trim((string)($auth['demand_patient_name'] ?? ''));
    echo '<div style="color:hsl(var(--muted-foreground));font-size:13px;margin-bottom:8px">Nenhum paciente vinculado'
        . ($demandPatientName !== '' ? ' (no card: <strong>' . h($demandPatientName) . '</strong>)' : '') . '.'
        . ($showApprovalForm ? ' Selecione no campo <strong>Paciente</strong>, ao lado do valor autorizado.' : '') . '</div>';
}

if ($canEditPatient && !$showApprovalForm) {
    echo '<form method="post" action="/authorization_set_patient_post.php" id="authPatientForm" style="margin-top:10px">';
    echo '<input type="hidden" name="auth_id" value="' . $authId . '">';
    $renderPatientSelect('authPatientSelect', '');
    echo '<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">';
    echo '<button type="submit" class="btn btnPrimary">💾 ' . ($currentPid > 0 ? 'Trocar paciente' : 'Vincular paciente') . '</button>';
    echo '<button type="button" class="btn" onclick="openAuthQuickPatient()">➕ Cadastro rápido do paciente</button>';
    echo '</div>';
    echo '</form>';
}
echo '</div>';

if ($canEditPatient) {
    // Pré-preenche o cadastro rápido com o que veio no card da captação.
    $dRow = [];
    try {
        $dSt = db()->prepare('SELECT * FROM demands WHERE id = :id LIMIT 1');
        $dSt->execute(['id' => (int)$auth['demand_id']]);
        $dRow = $dSt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
    $dv = static fn(string $k): string => trim((string)($dRow[$k] ?? ''));
    $qpName = trim((string)($auth['demand_patient_name'] ?? ''));
    $qpInput = 'width:100%;box-sizing:border-box;padding:10px;border:1px solid #d1d7db;border-radius:8px';
    $qpLabel = 'display:block;font-weight:600;font-size:13px;margin-bottom:5px';
    ?>
<style>
.aqpGrid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:12px}
.aqpGrid .s2{grid-column:span 2}.aqpGrid .s3{grid-column:span 3}.aqpGrid .s4{grid-column:span 4}.aqpGrid .s6{grid-column:span 6}
.aqpTitle{font-weight:800;font-size:13px;color:#00695c;margin:16px 0 8px;padding-top:12px;border-top:1px solid #e9edef}
@media (max-width:560px){.aqpGrid{grid-template-columns:1fr}.aqpGrid>div{grid-column:auto !important}}
</style>
<div id="authQuickPatientModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center;padding:12px">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:640px;max-height:92vh;overflow:auto">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:1">
      <strong style="font-size:17px">Cadastro rápido do paciente</strong>
      <button type="button" onclick="closeAuthQuickPatient()" style="background:none;border:none;font-size:24px;cursor:pointer" aria-label="Fechar">&times;</button>
    </div>
    <div style="padding:16px 20px 20px">
      <div style="font-size:13px;color:#667781;margin-bottom:12px">Esses dados vão para a Pré-Admissão, o Monitoramento e a mensagem enviada ao profissional. O restante do cadastro pode ser completado depois em Pacientes.</div>
      <div id="aqpError" style="display:none;background:#f8d7da;color:#721c24;padding:10px 12px;border-radius:8px;margin-bottom:12px;font-size:13px"></div>
      <div id="aqpDup" style="display:none;background:#fff3cd;color:#664d03;border:1px solid #ffe69c;padding:12px;border-radius:8px;margin-bottom:12px;font-size:13px"></div>

      <div class="aqpGrid">
        <div class="s4"><label style="<?= $qpLabel ?>" for="aqpName">Nome completo *</label>
          <input type="text" id="aqpName" maxlength="160" value="<?= h($qpName) ?>" style="<?= $qpInput ?>"></div>
        <div class="s2"><label style="<?= $qpLabel ?>" for="aqpPhone">Telefone do paciente</label>
          <input type="tel" id="aqpPhone" maxlength="30" placeholder="17 99999-0000" style="<?= $qpInput ?>"></div>
      </div>

      <div class="aqpTitle">📍 Endereço</div>
      <div class="aqpGrid">
        <div class="s2"><label style="<?= $qpLabel ?>" for="aqpZip">CEP</label>
          <input type="text" id="aqpZip" maxlength="9" inputmode="numeric" placeholder="00000-000" value="<?= h($dv('location_zip')) ?>" style="<?= $qpInput ?>"></div>
        <div class="s4"><label style="<?= $qpLabel ?>" for="aqpStreet">Rua</label>
          <input type="text" id="aqpStreet" maxlength="160" value="<?= h($dv('location_street')) ?>" style="<?= $qpInput ?>"></div>
        <div class="s2"><label style="<?= $qpLabel ?>" for="aqpNumber">Número</label>
          <input type="text" id="aqpNumber" maxlength="20" value="<?= h($dv('location_number')) ?>" style="<?= $qpInput ?>"></div>
        <div class="s4"><label style="<?= $qpLabel ?>" for="aqpComplement">Complemento</label>
          <input type="text" id="aqpComplement" maxlength="80" placeholder="Apto, bloco, casa..." value="<?= h($dv('location_complement')) ?>" style="<?= $qpInput ?>"></div>
        <div class="s2"><label style="<?= $qpLabel ?>" for="aqpNeighborhood">Bairro</label>
          <input type="text" id="aqpNeighborhood" maxlength="80" value="<?= h($dv('location_neighborhood')) ?>" style="<?= $qpInput ?>"></div>
        <div class="s3"><label style="<?= $qpLabel ?>" for="aqpCity">Cidade</label>
          <input type="text" id="aqpCity" maxlength="120" value="<?= h($dv('location_city') ?: (string)($auth['location_city'] ?? '')) ?>" style="<?= $qpInput ?>"></div>
        <div class="s1"><label style="<?= $qpLabel ?>" for="aqpState">UF</label>
          <input type="text" id="aqpState" maxlength="2" value="<?= h($dv('location_state') ?: (string)($auth['location_state'] ?? '')) ?>" style="<?= $qpInput ?>;text-transform:uppercase"></div>
      </div>

      <div class="aqpTitle">👪 Contato da família / responsável</div>
      <div class="aqpGrid">
        <div class="s3"><label style="<?= $qpLabel ?>" for="aqpFamilyName">Nome</label>
          <input type="text" id="aqpFamilyName" maxlength="160" style="<?= $qpInput ?>"></div>
        <div class="s1"><label style="<?= $qpLabel ?>" for="aqpFamilyRel">Parentesco</label>
          <input type="text" id="aqpFamilyRel" maxlength="60" placeholder="Filha" style="<?= $qpInput ?>"></div>
        <div class="s2"><label style="<?= $qpLabel ?>" for="aqpFamilyPhone">Telefone da família</label>
          <input type="tel" id="aqpFamilyPhone" maxlength="30" placeholder="17 99999-0000" style="<?= $qpInput ?>"></div>
      </div>

      <div style="display:flex;gap:10px;margin-top:6px">
        <button type="button" class="btn" style="flex:1" onclick="closeAuthQuickPatient()">Cancelar</button>
        <button type="button" id="aqpBtn" class="btn btnPrimary" style="flex:2" onclick="submitAuthQuickPatient(false)">Salvar e selecionar</button>
      </div>
    </div>
  </div>
</div>
<script src="/select_filter.js?v=2"></script>
<script>
(function () {
  var sel = document.getElementById('authPatientSelect');
  selectComboAttach(sel, 'Buscar paciente por nome, telefone, cidade ou CPF...');
  // Formulário com o paciente: exige a escolha antes de enviar (o select fica escondido).
  var approveForm = document.getElementById('authApproveForm');
  var linkForm = document.getElementById('authPatientForm');
  function requirePatient(e, msg) {
    if (sel && sel.value) return true;
    e.preventDefault();
    var box = document.getElementById('authApproveError');
    if (box) { box.textContent = msg; box.style.display = 'block'; } else { alert(msg); }
    if (sel && sel._sf) sel._sf.input.focus();
    return false;
  }
  if (approveForm) {
    approveForm.addEventListener('submit', function (e) {
      if (!requirePatient(e, 'Selecione o paciente (ou faça o cadastro rápido) antes de marcar como aprovada.')) return;
      if (!confirm('Confirmar que a autorização foi aprovada com o valor informado? O fluxo seguirá para a Pré-Admissão.')) e.preventDefault();
    });
  }
  if (linkForm) {
    linkForm.addEventListener('submit', function (e) { requirePatient(e, 'Selecione um paciente.'); });
  }
  // CEP -> endereço (ViaCEP). Só preenche campos vazios.
  var zip = document.getElementById('aqpZip');
  if (zip) {
    zip.addEventListener('blur', function () {
      var cep = zip.value.replace(/\D/g, '');
      if (cep.length !== 8) return;
      fetch('https://viacep.com.br/ws/' + cep + '/json/').then(function (r) { return r.json(); }).then(function (j) {
        if (!j || j.erro) return;
        var set = function (id, v) { var el = document.getElementById(id); if (el && !el.value && v) el.value = v; };
        set('aqpStreet', j.logradouro); set('aqpNeighborhood', j.bairro); set('aqpCity', j.localidade); set('aqpState', j.uf);
      }).catch(function () {});
    });
  }
})();
function openAuthQuickPatient() {
  document.getElementById('aqpError').style.display = 'none';
  document.getElementById('aqpDup').style.display = 'none';
  var sel = document.getElementById('authPatientSelect');
  if (sel && sel._sf && sel._sf.refresh) sel._sf.refresh(); // fecha a lista de busca
  document.getElementById('authQuickPatientModal').style.display = 'flex';
  document.getElementById('aqpName').focus();
}
function closeAuthQuickPatient() {
  document.getElementById('authQuickPatientModal').style.display = 'none';
}
// Seleciona o paciente no campo. Na tela sem aprovação manual, já grava o vínculo.
function authUsePatient(id, label) {
  var sel = document.getElementById('authPatientSelect');
  selectFilterAddOption(sel, id, label);
  closeAuthQuickPatient();
  var err = document.getElementById('authApproveError');
  if (err) err.style.display = 'none';
  var linkForm = document.getElementById('authPatientForm');
  if (linkForm) { linkForm.submit(); return; }
  var val = document.getElementById('authAuthorizedValue');
  if (val && !val.value) val.focus();
}
function submitAuthQuickPatient(force) {
  var err = document.getElementById('aqpError'), dup = document.getElementById('aqpDup'), btn = document.getElementById('aqpBtn');
  var v = function (id) { return document.getElementById(id).value.trim(); };
  var name = v('aqpName');
  err.style.display = 'none';
  if (!force) { dup.style.display = 'none'; dup.innerHTML = ''; }
  if (!name) { err.textContent = 'Informe o nome do paciente.'; err.style.display = 'block'; return; }
  var fd = new FormData();
  fd.append('name', name);
  fd.append('phone', v('aqpPhone'));
  fd.append('zip', v('aqpZip'));
  fd.append('street', v('aqpStreet'));
  fd.append('number', v('aqpNumber'));
  fd.append('complement', v('aqpComplement'));
  fd.append('neighborhood', v('aqpNeighborhood'));
  fd.append('city', v('aqpCity'));
  fd.append('state', v('aqpState'));
  fd.append('family_name', v('aqpFamilyName'));
  fd.append('family_relationship', v('aqpFamilyRel'));
  fd.append('family_phone', v('aqpFamilyPhone'));
  if (force) fd.append('force', '1');
  btn.disabled = true; btn.textContent = 'Salvando...';
  var label = function (p) { return p.name + (p.phone ? ' · ' + p.phone : '') + (p.city ? ' · ' + p.city : ''); };
  fetch('/patient_quick_create_post.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      btn.disabled = false; btn.textContent = 'Salvar e selecionar';
      if (d && d.ok && d.patient_id) {
        authUsePatient(d.patient_id, label({ name: d.name || name, phone: v('aqpPhone'), city: v('aqpCity') }));
        return;
      }
      if (d && d.needs_confirmation && Array.isArray(d.duplicates)) {
        var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
        var html = '<div style="font-weight:700;margin-bottom:8px">⚠ Já existe paciente parecido</div>';
        d.duplicates.forEach(function (p, i) {
          html += '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px 0;border-top:1px solid #ffe69c">'
            + '<div><div style="font-weight:600">' + esc(label(p)) + '</div><div style="font-size:11px">(' + (p.reason === 'phone' ? 'mesmo telefone' : 'nome parecido') + ')</div></div>'
            + '<button type="button" class="btn" data-dup="' + i + '">Usar este</button></div>';
        });
        html += '<button type="button" class="btn" id="aqpForce" style="margin-top:10px;width:100%">Cadastrar mesmo assim (são pessoas diferentes)</button>';
        dup.innerHTML = html;
        dup.style.display = 'block';
        dup.querySelectorAll('[data-dup]').forEach(function (b) {
          b.addEventListener('click', function () {
            var p = d.duplicates[+b.getAttribute('data-dup')];
            authUsePatient(p.id, label(p));
          });
        });
        document.getElementById('aqpForce').addEventListener('click', function () { submitAuthQuickPatient(true); });
        return;
      }
      err.textContent = (d && d.message) ? d.message : 'Erro ao cadastrar.';
      err.style.display = 'block';
    })
    .catch(function () {
      btn.disabled = false; btn.textContent = 'Salvar e selecionar';
      err.textContent = 'Erro de conexão. Tente novamente.';
      err.style.display = 'block';
    });
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAuthQuickPatient(); });
</script>
    <?php
}

echo '<div class="formSection">';
echo '<div class="formSectionTitle">📅 Agendamento Proposto</div>';
echo '<div class="grid">';
echo '<div class="col6"><strong>Data de Início:</strong> ' . date('d/m/Y', strtotime((string)$auth['start_date'])) . '</div>';
echo '<div class="col6"><strong>Horário de início:</strong> ' . h(substr((string)$auth['start_time'], 0, 5)) . '</div>';

$frequencyText = match((string)$auth['frequency']) {
    'single' => 'Atendimento Único',
    'daily' => 'Diário',
    'weekly' => 'Semanal',
    'biweekly' => 'Quinzenal',
    'monthly' => 'Mensal',
    'custom' => 'Personalizado',
    default => h((string)$auth['frequency'])
};

echo '<div class="col6"><strong>Frequência:</strong> ' . $frequencyText . '</div>';
echo '<div class="col6"><strong>Duração:</strong> ' . (int)$auth['duration_weeks'] . ' semanas</div>';
echo '<div class="col6"><strong>Total de Sessões:</strong> ' . (int)$auth['total_sessions'] . '</div>';

if (!empty($auth['frequency_details'])) {
    $details = json_decode((string)$auth['frequency_details'], true);
    if (is_array($details) && !empty($details['description'])) {
        echo '<div class="col12"><strong>Detalhes:</strong> ' . h((string)$details['description']) . '</div>';
    }
}
echo '</div>';
echo '</div>';

echo '<div class="formSection">';
echo '<div class="formSectionTitle">💰 Valores</div>';
$proposalValue = (float)$auth['proposal_value'];
$agreedValue = (float)$auth['agreed_value'];
$totalSessions = (int)$auth['total_sessions'];
$totalProposal = $proposalValue * $totalSessions;
$totalCost = $agreedValue * $totalSessions;
$margin = $totalProposal - $totalCost;

echo '<div class="grid">';
echo '<div class="col6"><strong>Valor por Sessão (Proposta):</strong> R$ ' . number_format($proposalValue, 2, ',', '.') . '</div>';
echo '<div class="col6"><strong>Valor por Sessão (Profissional):</strong> R$ ' . number_format($agreedValue, 2, ',', '.') . '</div>';
echo '<div class="col12" style="margin-top:12px;padding:14px;background:hsla(var(--primary)/.08);border:1px solid hsla(var(--primary)/.2);border-radius:10px">';
echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">';
echo '<div><strong>Valor Total da Proposta:</strong><br><span style="font-size:20px;font-weight:900;color:hsl(var(--primary))">R$ ' . number_format($totalProposal, 2, ',', '.') . '</span></div>';
echo '<div><strong>Custo Total:</strong><br><span style="font-size:20px;font-weight:900">R$ ' . number_format($totalCost, 2, ',', '.') . '</span></div>';
$marginColor = $margin >= 0 ? 'hsl(var(--success))' : 'hsl(var(--destructive))';
echo '<div><strong>Margem:</strong><br><span style="font-size:20px;font-weight:900;color:' . $marginColor . '">R$ ' . number_format($margin, 2, ',', '.') . '</span></div>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '</div>';

echo '<div class="formSection">';
echo '<div class="formSectionTitle">📧 Informações de Envio</div>';
echo '<div class="grid">';
echo '<div class="col6"><strong>E-mail da Operadora / Cliente:</strong> ' . h((string)$auth['operator_email']) . '</div>';
if (!empty($auth['operator_name'])) {
    echo '<div class="col6"><strong>Nome da Operadora / Cliente:</strong> ' . h((string)$auth['operator_name']) . '</div>';
}
if (!empty($auth['sent_at'])) {
    echo '<div class="col6"><strong>Enviado em:</strong> ' . date('d/m/Y H:i:s', strtotime((string)$auth['sent_at'])) . '</div>';
}
if (!empty($auth['response_received_at'])) {
    echo '<div class="col6"><strong>Resposta em:</strong> ' . date('d/m/Y H:i:s', strtotime((string)$auth['response_received_at'])) . '</div>';
}
if (!empty($auth['ai_analysis'])) {
    echo '<div class="col12"><strong>Análise da IA:</strong><br><div style="padding:10px;background:hsla(var(--muted)/.3);border-radius:8px;margin-top:6px">' . nl2br(h((string)$auth['ai_analysis'])) . '</div></div>';
}
if (!empty($auth['denial_reason'])) {
    echo '<div class="col12"><strong>Motivo da Negação:</strong><br><div style="padding:10px;background:hsla(var(--destructive)/.1);border:1px solid hsla(var(--destructive)/.2);border-radius:8px;margin-top:6px;color:hsl(var(--destructive))">' . nl2br(h((string)$auth['denial_reason'])) . '</div></div>';
}
echo '</div>';
echo '</div>';

echo '</section>';

// Sidebar com ações e histórico
echo '<section class="col4">';

// Ações disponíveis
if ($status === 'autorizacao_negada') {
    echo '<div class="card" style="margin-bottom:16px">';
    echo '<div style="font-weight:700;margin-bottom:12px">⚡ Ações Disponíveis</div>';
    echo '<div style="display:flex;flex-direction:column;gap:8px">';
    echo '<a href="/authorization_resend.php?id=' . $authId . '" class="btn btnPrimary" style="text-align:center">🔄 Reenviar com Novo Valor</a>';
    echo '<a href="/authorization_cancel.php?id=' . $authId . '" class="btn" style="text-align:center;background:hsl(var(--destructive));color:white">⛔ Finalizar Solicitação</a>';
    echo '</div>';
    echo '</div>';
}

// Histórico
echo '<div class="card">';
echo '<div style="font-weight:700;margin-bottom:12px">📜 Histórico</div>';

if (count($history) === 0) {
    echo '<div style="color:hsl(var(--muted-foreground));font-size:13px;text-align:center;padding:20px">Nenhum registro</div>';
} else {
    echo '<div style="display:flex;flex-direction:column;gap:12px">';
    foreach ($history as $h) {
        $actionText = match((string)$h['action']) {
            'created' => '📝 Criada',
            'sent' => '📧 Enviada',
            'response_received' => '📨 Resposta Recebida',
            'approved' => '✅ Aprovada',
            'denied' => '❌ Negada',
            'resent' => '🔄 Reenviada',
            'cancelled' => '⛔ Cancelada',
            default => h((string)$h['action'])
        };
        
        echo '<div style="padding:10px;background:hsla(var(--muted)/.3);border-radius:8px;font-size:13px">';
        echo '<div style="font-weight:700;margin-bottom:4px">' . $actionText . '</div>';
        echo '<div style="color:hsl(var(--muted-foreground));font-size:12px">' . date('d/m/Y H:i', strtotime((string)$h['created_at'])) . '</div>';
        if (!empty($h['user_name'])) {
            echo '<div style="color:hsl(var(--muted-foreground));font-size:12px">Por: ' . h((string)$h['user_name']) . '</div>';
        }
        if (!empty($h['notes'])) {
            echo '<div style="margin-top:6px;font-size:12px">' . h((string)$h['notes']) . '</div>';
        }
        if (!empty($h['proposal_value'])) {
            echo '<div style="margin-top:4px;font-weight:700;color:hsl(var(--primary))">R$ ' . number_format((float)$h['proposal_value'], 2, ',', '.') . '/sessão</div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

echo '</div>';

echo '</section>';

echo '</div>';

view_footer();
