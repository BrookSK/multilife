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
// ITEM 6: Ação manual "Marcar como respondida" quando envio automático está desabilitado.
$autoSendAuthView = (string)admin_setting_get('feature.auto_send_authorization', '1');
$pendingPatientApproval = (int)($auth['pending_patient_approval'] ?? 0) === 1;
$hasLinkedPatient = (int)($auth['patient_id'] ?? 0) > 0 && !empty($auth['linked_patient_id']);
// Também aparece quando a operadora aprovou por e-mail mas faltava o paciente
if (($autoSendAuthView !== '1' || $pendingPatientApproval) && (string)$auth['status'] === 'aguardando_autorizacao') {
    // Ao aprovar manualmente, o valor autorizado pela operadora/cliente é OBRIGATÓRIO.
    // Total de sessões para calcular o valor por sessão a partir do total autorizado.
    $mrTotalSessions = (int)($auth['total_sessions'] ?? 0);
    echo '<form method="post" action="/authorization_mark_responded_post.php" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap" onsubmit="return confirm(\'Confirmar que a autorização foi aprovada com o valor informado? O fluxo seguirá para a Pré-Admissão.\')">';
    echo '<input type="hidden" name="auth_id" value="' . $authId . '">';
    echo '<input type="hidden" name="total_sessions" value="' . $mrTotalSessions . '">';
    echo '<input type="number" name="authorized_value" required min="0.01" step="0.01" placeholder="Valor autorizado (total) R$ *" title="Valor total autorizado pela operadora / cliente" style="height:40px;min-width:220px;padding:8px 12px;border:1px solid hsl(var(--border));border-radius:8px">';
    if ($hasLinkedPatient) {
        echo '<button type="submit" class="btn btnPrimary">✅ Marcar como aprovada</button>';
    } else {
        echo '<button type="submit" class="btn btnPrimary" disabled title="Vincule o paciente abaixo antes de aprovar">✅ Marcar como aprovada</button>';
    }
    echo '</form>';
    // Recusar: a operadora/cliente NÃO autorizou. Move o card para "Negativas".
    echo '<form method="post" action="/authorization_mark_denied_post.php" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap" onsubmit="return confirm(\'Confirmar que a autorização foi RECUSADA? O card irá para Negativas.\')">';
    echo '<input type="hidden" name="auth_id" value="' . $authId . '">';
    echo '<input type="text" name="denial_reason" placeholder="Motivo da recusa (opcional)" title="Motivo informado pela operadora / cliente" style="height:40px;min-width:220px;padding:8px 12px;border:1px solid hsl(var(--border));border-radius:8px">';
    echo '<button type="submit" class="btn" style="background:hsl(var(--destructive));color:#fff">❌ Marcar como recusada</button>';
    echo '</form>';
}
echo '</div>';
echo '</div>';

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

// Paciente: vinculado aqui (não mais no chat). Enquanto aguarda a aprovação,
// dá para selecionar um paciente existente (com busca) ou fazer o cadastro rápido.
$canEditPatient = $status === 'aguardando_autorizacao';
echo '<div class="formSection" id="pacienteSection">';
echo '<div class="formSectionTitle">👤 Paciente</div>';
if ($pendingPatientApproval && $status === 'aguardando_autorizacao') {
    echo '<div style="padding:10px 12px;margin-bottom:12px;border-radius:8px;background:hsla(var(--success)/.12);border:1px solid hsla(var(--success)/.35);font-size:13px">'
        . '✅ A operadora / cliente <strong>aprovou</strong> por e-mail. Vincule o paciente abaixo e clique em <strong>Marcar como aprovada</strong> para seguir para a Pré-Admissão.</div>';
}
if (!empty($auth['patient_name'])) {
    echo '<div class="grid">';
    echo '<div class="col6"><strong>Nome:</strong> ' . h((string)$auth['patient_name']) . '</div>';
    if (!empty($auth['patient_email'])) {
        echo '<div class="col6"><strong>E-mail:</strong> ' . h((string)$auth['patient_email']) . '</div>';
    }
    if (!empty($auth['patient_phone'])) {
        echo '<div class="col6"><strong>Telefone:</strong> ' . h((string)$auth['patient_phone']) . '</div>';
    }
    echo '</div>';
} else {
    $demandPatientName = trim((string)($auth['demand_patient_name'] ?? ''));
    echo '<div style="color:hsl(var(--muted-foreground));font-size:13px;margin-bottom:8px">Nenhum paciente vinculado'
        . ($demandPatientName !== '' ? ' (no card: <strong>' . h($demandPatientName) . '</strong>)' : '') . '.</div>';
}

if ($canEditPatient) {
    $patientRows = db()->query("SELECT id, full_name, phone_primary, whatsapp, address_city FROM patients WHERE deleted_at IS NULL ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $currentPid = (int)($auth['patient_id'] ?? 0);
    echo '<form method="post" action="/authorization_set_patient_post.php" id="authPatientForm" style="margin-top:10px">';
    echo '<input type="hidden" name="auth_id" value="' . $authId . '">';
    echo '<select name="patient_id" id="authPatientSelect" required style="width:100%;padding:10px 12px;border:1px solid hsl(var(--border));border-radius:8px">';
    echo '<option value="">Selecione o paciente...</option>';
    foreach ($patientRows as $pr) {
        $extra = array_filter([trim((string)($pr['phone_primary'] ?: $pr['whatsapp'] ?: '')), trim((string)($pr['address_city'] ?? ''))]);
        $sel = (int)$pr['id'] === $currentPid ? ' selected' : '';
        echo '<option value="' . (int)$pr['id'] . '"' . $sel . '>' . h((string)$pr['full_name'] . ($extra ? ' · ' . implode(' · ', $extra) : '')) . '</option>';
    }
    echo '</select>';
    echo '<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">';
    echo '<button type="submit" class="btn btnPrimary">💾 ' . ($currentPid > 0 ? 'Trocar paciente' : 'Vincular paciente') . '</button>';
    echo '<button type="button" class="btn" onclick="openAuthQuickPatient()">➕ Cadastro rápido do paciente</button>';
    echo '</div>';
    echo '</form>';
}
echo '</div>';

if ($canEditPatient) {
    $qpName = trim((string)($auth['demand_patient_name'] ?? ''));
    ?>
<div id="authQuickPatientModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:460px;max-height:90vh;overflow:auto">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;justify-content:space-between;align-items:center">
      <strong style="font-size:17px">Cadastro rápido do paciente</strong>
      <button type="button" onclick="closeAuthQuickPatient()" style="background:none;border:none;font-size:24px;cursor:pointer">&times;</button>
    </div>
    <div style="padding:20px">
      <div style="font-size:13px;color:#667781;margin-bottom:14px">Pré-cadastro com o essencial. O cadastro completo é feito depois em Pacientes.</div>
      <div id="aqpError" style="display:none;background:#f8d7da;color:#721c24;padding:10px 12px;border-radius:8px;margin-bottom:12px;font-size:13px"></div>
      <div id="aqpDup" style="display:none;background:#fff3cd;color:#664d03;border:1px solid #ffe69c;padding:12px;border-radius:8px;margin-bottom:12px;font-size:13px"></div>
      <label style="display:block;font-weight:600;margin-bottom:6px">Nome *</label>
      <input type="text" id="aqpName" value="<?= h($qpName) ?>" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px;margin-bottom:12px">
      <label style="display:block;font-weight:600;margin-bottom:6px">Telefone</label>
      <input type="text" id="aqpPhone" placeholder="Ex: 5511999999999" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px;margin-bottom:12px">
      <div style="display:grid;grid-template-columns:1fr 80px;gap:10px;margin-bottom:16px">
        <div><label style="display:block;font-weight:600;margin-bottom:6px">Cidade</label>
          <input type="text" id="aqpCity" value="<?= h((string)($auth['location_city'] ?? '')) ?>" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px"></div>
        <div><label style="display:block;font-weight:600;margin-bottom:6px">UF</label>
          <input type="text" id="aqpState" maxlength="2" value="<?= h((string)($auth['location_state'] ?? '')) ?>" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px"></div>
      </div>
      <button type="button" id="aqpBtn" class="btn btnPrimary" style="width:100%" onclick="submitAuthQuickPatient(false)">Salvar e vincular</button>
    </div>
  </div>
</div>
<script src="/select_filter.js?v=1"></script>
<script>
selectFilterAttach(document.getElementById('authPatientSelect'), 'Buscar paciente por nome, telefone ou cidade...');
function openAuthQuickPatient() {
  document.getElementById('aqpError').style.display = 'none';
  document.getElementById('aqpDup').style.display = 'none';
  document.getElementById('authQuickPatientModal').style.display = 'flex';
}
function closeAuthQuickPatient() {
  document.getElementById('authQuickPatientModal').style.display = 'none';
}
// Seleciona o paciente e já envia o vínculo
function authUsePatient(id, label) {
  var sel = document.getElementById('authPatientSelect');
  selectFilterReset(sel); // volta todas as opções antes de procurar
  var exists = Array.prototype.some.call(sel.options, function (o) { return o.value === String(id); });
  if (!exists) { selectFilterAddOption(sel, id, label); }
  sel.value = String(id);
  closeAuthQuickPatient();
  document.getElementById('authPatientForm').submit();
}
function submitAuthQuickPatient(force) {
  var err = document.getElementById('aqpError'), dup = document.getElementById('aqpDup'), btn = document.getElementById('aqpBtn');
  var name = document.getElementById('aqpName').value.trim();
  err.style.display = 'none';
  if (!force) { dup.style.display = 'none'; dup.innerHTML = ''; }
  if (!name) { err.textContent = 'Informe o nome do paciente.'; err.style.display = 'block'; return; }
  var fd = new FormData();
  fd.append('name', name);
  fd.append('phone', document.getElementById('aqpPhone').value);
  fd.append('city', document.getElementById('aqpCity').value);
  fd.append('state', document.getElementById('aqpState').value);
  if (force) fd.append('force', '1');
  btn.disabled = true; btn.textContent = 'Salvando...';
  fetch('/patient_quick_create_post.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      btn.disabled = false; btn.textContent = 'Salvar e vincular';
      if (d && d.ok && d.patient_id) { authUsePatient(d.patient_id, d.name || name); return; }
      if (d && d.needs_confirmation && Array.isArray(d.duplicates)) {
        var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
        var html = '<div style="font-weight:700;margin-bottom:8px">⚠ Já existe paciente parecido</div>';
        d.duplicates.forEach(function (p, i) {
          var label = p.name + (p.phone ? ' · ' + p.phone : '') + (p.city ? ' · ' + p.city : '');
          html += '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px 0;border-top:1px solid #ffe69c">'
            + '<div><div style="font-weight:600">' + esc(label) + '</div><div style="font-size:11px">(' + (p.reason === 'phone' ? 'mesmo telefone' : 'nome parecido') + ')</div></div>'
            + '<button type="button" class="btn" data-dup="' + i + '">Usar este</button></div>';
        });
        html += '<button type="button" class="btn" id="aqpForce" style="margin-top:10px;width:100%">Cadastrar mesmo assim (são pessoas diferentes)</button>';
        dup.innerHTML = html;
        dup.style.display = 'block';
        dup.querySelectorAll('[data-dup]').forEach(function (b) {
          b.addEventListener('click', function () {
            var p = d.duplicates[+b.getAttribute('data-dup')];
            authUsePatient(p.id, p.name + (p.phone ? ' · ' + p.phone : '') + (p.city ? ' · ' + p.city : ''));
          });
        });
        document.getElementById('aqpForce').addEventListener('click', function () { submitAuthQuickPatient(true); });
        return;
      }
      err.textContent = (d && d.message) ? d.message : 'Erro ao cadastrar.';
      err.style.display = 'block';
    })
    .catch(function () {
      btn.disabled = false; btn.textContent = 'Salvar e vincular';
      err.textContent = 'Erro de conexão. Tente novamente.';
      err.style.display = 'block';
    });
}
</script>
    <?php
}

echo '<div class="formSection">';
echo '<div class="formSectionTitle">📅 Agendamento Proposto</div>';
echo '<div class="grid">';
echo '<div class="col6"><strong>Data de Início:</strong> ' . date('d/m/Y', strtotime((string)$auth['start_date'])) . '</div>';
echo '<div class="col6"><strong>Horário:</strong> ' . h((string)$auth['start_time']) . ' às ' . h((string)$auth['end_time']) . '</div>';

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
