<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$uid = (int)auth_user_id();

$canManage = rbac_user_can($uid, 'patients.manage');
$canViewLinked = rbac_user_can($uid, 'patients.view_linked');

if (!$canManage && !$canViewLinked) {
    header('Location: /forbidden.php');
    exit;
}

if ($canManage) {
    $stmt = db()->prepare('SELECT * FROM patients WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute(['id' => $id]);
    $p = $stmt->fetch();
} else {
    $stmt = db()->prepare(
        'SELECT p.*
         FROM patients p
         INNER JOIN patient_professionals pp ON pp.patient_id = p.id
         WHERE p.id = :id AND p.deleted_at IS NULL AND pp.professional_user_id = :uid AND pp.is_active = 1'
    );
    $stmt->execute(['id' => $id, 'uid' => $uid]);
    $p = $stmt->fetch();
}

if (!$p) {
    flash_set('error', 'Paciente não encontrado (ou não vinculado).');
    header('Location: /dashboard.php');
    exit;
}

$stmt = db()->prepare('INSERT INTO patient_access_logs (patient_id, user_id, action, ip_address, user_agent) VALUES (:pid, :uid, :action, :ip, :ua)');
$stmt->execute([
    'pid' => (int)$p['id'],
    'uid' => $uid,
    'action' => 'view',
    'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : null,
    'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_strimwidth((string)$_SERVER['HTTP_USER_AGENT'], 0, 255, '') : null,
]);

$stmt = db()->prepare(
    'SELECT e.id, e.occurred_at, e.origin, e.sessions_count, e.notes, u.name AS professional_name
     FROM patient_prontuario_entries e
     LEFT JOIN users u ON u.id = e.professional_user_id
     WHERE e.patient_id = :pid
     ORDER BY e.occurred_at DESC, e.id DESC'
);
$stmt->execute(['pid' => $id]);
$entries = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT u.id, u.name, u.email, pp.specialty, pp.is_active
     FROM patient_professionals pp
     INNER JOIN users u ON u.id = pp.professional_user_id
     WHERE pp.patient_id = :pid'
);
$stmt->execute(['pid' => $id]);
$linked = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT d.id, d.category, d.title, d.status, d.created_at,
            (SELECT MAX(v.version_no) FROM document_versions v WHERE v.document_id = d.id) AS last_version
     FROM documents d
     WHERE d.entity_type = :et AND d.entity_id = :eid
     ORDER BY d.id DESC
     LIMIT 50'
);
$stmt->execute(['et' => 'patient', 'eid' => $id]);
$patientDocs = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT ar.id, ar.amount, ar.due_at, ar.status, ar.received_at,
            a.id AS appointment_id, a.first_at,
            u.name AS professional_name
     FROM finance_accounts_receivable ar
     INNER JOIN appointments a ON a.id = ar.appointment_id
     INNER JOIN users u ON u.id = ar.professional_user_id
     WHERE ar.patient_id = :pid
     ORDER BY ar.id DESC
     LIMIT 50'
);
$stmt->execute(['pid' => $id]);
$receivables = $stmt->fetchAll();

view_header('Paciente #' . (string)$p['id']);

$contact = trim((string)($p['whatsapp'] ?? ''));
if ($contact === '') {
    $contact = trim((string)($p['phone_primary'] ?? ''));
}

$addr = trim((string)($p['address_street'] ?? ''));
if ($addr !== '') {
    $addr .= ', ' . trim((string)($p['address_number'] ?? ''));
}

$addrCity = trim((string)($p['address_city'] ?? ''));
$addrUf = trim((string)($p['address_state'] ?? ''));

$addrTxt = trim($addr);
if ($addrCity !== '' || $addrUf !== '') {
    $addrTxt .= ($addrTxt !== '' ? ' — ' : '') . $addrCity . ($addrUf !== '' ? '/' . $addrUf : '');
}
if ($addrTxt === '') {
    $addrTxt = '-';
}


echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:6px">Paciente</div>';
echo '<div style="font-size:22px;font-weight:900">' . h((string)$p['full_name']) . '</div>';
echo '<div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
echo '<span class="badge badgeInfo"><strong>CPF:</strong>&nbsp;' . h((string)($p['cpf'] ?? '-')) . '</span>';
echo '<span style="color:hsl(var(--muted-foreground));font-size:13px"><strong>Contato:</strong> ' . h($contact !== '' ? $contact : '-') . '</span>';
echo '<span style="color:hsl(var(--muted-foreground));font-size:13px"><strong>Endereço:</strong> ' . h($addrTxt) . '</span>';
echo '</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
if ($canManage) {
    echo '<a class="btn" href="/patients_list.php">Voltar</a>';
    echo '<a class="btn" href="/patients_edit.php?id=' . (int)$p['id'] . '">Editar</a>';
    echo '<a class="btn" href="/patients_links_edit.php?id=' . (int)$p['id'] . '">Vínculos</a>';
} else {
    echo '<a class="btn" href="/professional_my_patients_list.php">Voltar</a>';
}

echo '</div>';
echo '</div>';
echo '</section>';

echo '<style>';
echo '.pvTabs{display:flex;gap:10px;flex-wrap:wrap}';
echo '.pvTab{border:1px solid hsl(var(--border));background:hsla(var(--secondary)/.50);border-radius:10px;padding:8px 10px;font-size:12px;font-weight:900;color:hsl(var(--muted-foreground));cursor:pointer}';
echo '.pvTab.isActive{background:hsl(var(--primary));border-color:hsl(var(--primary));color:hsl(var(--primary-foreground))}';
echo '.pvPanel{display:none;margin-top:12px}';
echo '.pvPanel.isActive{display:block}';
echo '</style>';

echo '<section class="card col12">';
echo '<div class="pvTabs" id="pvTabs">';
$tabs = [
    ['k' => 'ident', 'l' => 'Identificação'],
    ['k' => 'contato', 'l' => 'Contato'],
    ['k' => 'end', 'l' => 'Endereço'],
    ['k' => 'emerg', 'l' => 'Emergência'],
    ['k' => 'conv', 'l' => 'Convênio'],
    ['k' => 'docs', 'l' => 'Documentos'],
    ['k' => 'saude', 'l' => 'Saúde'],
    ['k' => 'hist', 'l' => 'Histórico Médico'],
    ['k' => 'fin', 'l' => 'Financeiro'],
    ['k' => 'lgpd', 'l' => 'LGPD'],
    ['k' => 'resp', 'l' => 'Responsável'],
    ['k' => 'adm', 'l' => 'Administrativo'],
];
foreach ($tabs as $i => $t) {
    $isActive = $i === 0;
    echo '<button type="button" class="pvTab' . ($isActive ? ' isActive' : '') . '" data-tab="' . h($t['k']) . '">' . h($t['l']) . '</button>';
}
echo '</div>';

echo '<div class="pvPanel isActive" data-panel="ident">';
echo '<div class="grid">';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Nome:</strong> ' . h((string)$p['full_name']) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>CPF:</strong> ' . h((string)($p['cpf'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>RG:</strong> ' . h((string)($p['rg'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Nascimento:</strong> ' . h((string)($p['birth_date'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Sexo:</strong> ' . h((string)($p['sex'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Estado civil:</strong> ' . h((string)($p['marital_status'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Profissão:</strong> ' . h((string)($p['profession'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Escolaridade:</strong> ' . h((string)($p['education_level'] ?? '-')) . '</div></div>';
echo '</div>';
echo '</div>';

echo '<div class="pvPanel" data-panel="contato">';
echo '<div class="grid">';
echo '<div class="col6"><div class="pill" style="display:block"><strong>WhatsApp:</strong> ' . h((string)($p['whatsapp'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Telefone principal:</strong> ' . h((string)($p['phone_primary'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Telefone secundário:</strong> ' . h((string)($p['phone_secondary'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Preferência:</strong> ' . h((string)($p['preferred_contact'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>E-mail:</strong> ' . h((string)($p['email'] ?? '-')) . '</div></div>';
echo '</div>';
echo '</div>';

echo '<div class="pvPanel" data-panel="end">';
echo '<div class="grid">';
echo '<div class="col6"><div class="pill" style="display:block"><strong>CEP:</strong> ' . h((string)($p['address_zip'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Logradouro:</strong> ' . h((string)($p['address_street'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Número:</strong> ' . h((string)($p['address_number'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Complemento:</strong> ' . h((string)($p['address_complement'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Bairro:</strong> ' . h((string)($p['address_neighborhood'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Cidade:</strong> ' . h((string)($p['address_city'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>UF:</strong> ' . h((string)($p['address_state'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>País:</strong> ' . h((string)($p['address_country'] ?? '-')) . '</div></div>';
echo '</div>';
echo '</div>';

echo '<div class="pvPanel" data-panel="emerg">';
echo '<div class="grid">';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Nome:</strong> ' . h((string)($p['emergency_name'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Parentesco:</strong> ' . h((string)($p['emergency_relationship'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Telefone:</strong> ' . h((string)($p['emergency_phone'] ?? '-')) . '</div></div>';
echo '</div>';
echo '</div>';

echo '<div class="pvPanel" data-panel="conv">';
echo '<div class="grid">';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Convênio:</strong> ' . h((string)($p['insurance_name'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Carteirinha:</strong> ' . h((string)($p['insurance_card_number'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Validade:</strong> ' . h((string)($p['insurance_valid_until'] ?? '-')) . '</div></div>';
echo '<div class="col12"><div class="pill" style="display:block"><strong>Obs:</strong> ' . h((string)($p['insurance_notes'] ?? '-')) . '</div></div>';
echo '</div>';
echo '</div>';

echo '<div class="pvPanel" data-panel="docs">';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-bottom:10px">';
if ($canManage) {
    echo '<a class="btn btnPrimary" href="/documents_upload.php?entity_type=patient&entity_id=' . (int)$p['id'] . '&return_to=' . urlencode('/patients_view.php?id=' . (int)$p['id']) . '">Novo documento</a>';
    echo '<a class="btn" href="/documents_list.php?entity_type=patient&entity_id=' . (int)$p['id'] . '">Abrir gestão documental</a>';
}
echo '</div>';

echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Categoria</th><th>Título</th><th>Versão</th><th>Status</th><th>Criado em</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($patientDocs as $d) {
    $ver = $d['last_version'] !== null ? 'v' . (int)$d['last_version'] : '-';
    echo '<tr>';
    echo '<td>' . (int)$d['id'] . '</td>';
    echo '<td>' . h((string)$d['category']) . '</td>';
    echo '<td>' . h((string)($d['title'] ?? '')) . '</td>';
    echo '<td>' . h($ver) . '</td>';
    echo '<td>' . h((string)$d['status']) . '</td>';
    echo '<td>' . h((string)$d['created_at']) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/documents_view.php?id=' . (int)$d['id'] . '">Abrir</a>';
    echo '</td>';
    echo '</tr>';
}
if (count($patientDocs) === 0) {
    echo '<tr><td colspan="7" class="pill" style="display:table-cell;padding:12px">Sem documentos.</td></tr>';
}
echo '</tbody></table>';
echo '</div>';
echo '</div>';

echo '<div class="pvPanel" data-panel="saude">';
$healthRaw = (string)($p['health_json'] ?? '');
$health = null;
if (trim($healthRaw) !== '') {
    $decoded = json_decode($healthRaw, true);
    if (is_array($decoded)) {
        $health = $decoded;
    }
}

if (is_array($health) && (isset($health['allergies']) || isset($health['medications']) || isset($health['conditions']) || isset($health['restrictions']) || isset($health['blood_type']) || isset($health['notes']))) {
    echo '<div class="grid">';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Alergias:</strong> ' . h((string)($health['allergies'] ?? '-')) . '</div></div>';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Medicamentos:</strong> ' . h((string)($health['medications'] ?? '-')) . '</div></div>';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Condições:</strong> ' . h((string)($health['conditions'] ?? '-')) . '</div></div>';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Restrições:</strong> ' . h((string)($health['restrictions'] ?? '-')) . '</div></div>';
    echo '<div class="col6"><div class="pill" style="display:block"><strong>Tipo sanguíneo:</strong> ' . h((string)($health['blood_type'] ?? '-')) . '</div></div>';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Observações:</strong> ' . h((string)($health['notes'] ?? '-')) . '</div></div>';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Saúde (JSON):</strong><br>' . h($healthRaw) . '</div></div>';
    echo '</div>';
} else {
    echo '<pre style="white-space:pre-wrap;margin:0" class="pill">' . h($healthRaw) . '</pre>';
}
echo '</div>';

echo '<div class="pvPanel" data-panel="hist">';
$mhRaw = (string)($p['medical_history_json'] ?? '');
$mh = null;
if (trim($mhRaw) !== '') {
    $decoded = json_decode($mhRaw, true);
    if (is_array($decoded)) {
        $mh = $decoded;
    }
}

if (is_array($mh) && (isset($mh['main_complaints']) || isset($mh['past_diseases']) || isset($mh['surgeries']) || isset($mh['hospitalizations']) || isset($mh['family_history']) || isset($mh['habits']) || isset($mh['notes']))) {
    echo '<div class="grid">';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Queixas principais:</strong> ' . h((string)($mh['main_complaints'] ?? '-')) . '</div></div>';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Doenças prévias:</strong> ' . h((string)($mh['past_diseases'] ?? '-')) . '</div></div>';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Cirurgias:</strong> ' . h((string)($mh['surgeries'] ?? '-')) . '</div></div>';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Internações:</strong> ' . h((string)($mh['hospitalizations'] ?? '-')) . '</div></div>';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Histórico familiar:</strong> ' . h((string)($mh['family_history'] ?? '-')) . '</div></div>';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Hábitos:</strong> ' . h((string)($mh['habits'] ?? '-')) . '</div></div>';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Observações:</strong> ' . h((string)($mh['notes'] ?? '-')) . '</div></div>';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Histórico médico (JSON):</strong><br>' . h($mhRaw) . '</div></div>';
    echo '</div>';
} else {
    echo '<pre style="white-space:pre-wrap;margin:0" class="pill">' . h($mhRaw) . '</pre>';
}
echo '</div>';

echo '<div class="pvPanel" data-panel="fin">';
echo '<div style="display:grid;gap:10px">';
if ((string)($p['finance_json'] ?? '') !== '') {
    echo '<div class="pill" style="display:block"><strong>Financeiro (JSON):</strong><br><pre style="white-space:pre-wrap;margin:0">' . h((string)$p['finance_json']) . '</pre></div>';
}
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Agendamento</th><th>Data</th><th>Profissional</th><th>Valor</th><th>Vencimento</th><th>Status</th>';
echo '</tr></thead><tbody>';
foreach ($receivables as $r) {
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td>#' . (int)$r['appointment_id'] . '</td>';
    echo '<td>' . h((string)$r['first_at']) . '</td>';
    echo '<td>' . h((string)$r['professional_name']) . '</td>';
    echo '<td>' . h((string)$r['amount']) . '</td>';
    echo '<td>' . h((string)$r['due_at']) . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '</tr>';
}
if (count($receivables) === 0) {
    echo '<tr><td colspan="7" class="pill" style="display:table-cell;padding:12px">Sem contas a receber.</td></tr>';
}
echo '</tbody></table>';
echo '</div>';
echo '</div>';
echo '</div>';

echo '<div class="pvPanel" data-panel="lgpd">';
$canAnonymize = rbac_user_can($uid, 'patients.lgpd.anonymize');
echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-bottom:10px">';
if ($canManage) {
    echo '<a class="btn" href="/patient_access_logs_list.php?patient_id=' . (int)$p['id'] . '">Acessos ao prontuário</a>';
}
if ($canAnonymize) {
    echo '<a class="btn btnPrimary" href="/patient_lgpd_anonymize.php?id=' . (int)$p['id'] . '">Anonimizar (LGPD)</a>';
}
echo '</div>';

$lgpdRaw = (string)($p['lgpd_json'] ?? '');
$lgpd = null;
if (trim($lgpdRaw) !== '') {
    $decoded = json_decode($lgpdRaw, true);
    if (is_array($decoded)) {
        $lgpd = $decoded;
    }
}

if (is_array($lgpd) && (isset($lgpd['consent_status']) || isset($lgpd['consent_at']) || isset($lgpd['consent_version']) || isset($lgpd['consent_channel']) || isset($lgpd['notes']))) {
    echo '<div class="grid">';
    echo '<div class="col6"><div class="pill" style="display:block"><strong>Consentimento:</strong> ' . h((string)($lgpd['consent_status'] ?? '-')) . '</div></div>';
    echo '<div class="col6"><div class="pill" style="display:block"><strong>Data:</strong> ' . h((string)($lgpd['consent_at'] ?? '-')) . '</div></div>';
    echo '<div class="col6"><div class="pill" style="display:block"><strong>Versão:</strong> ' . h((string)($lgpd['consent_version'] ?? '-')) . '</div></div>';
    echo '<div class="col6"><div class="pill" style="display:block"><strong>Canal:</strong> ' . h((string)($lgpd['consent_channel'] ?? '-')) . '</div></div>';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Observações:</strong> ' . h((string)($lgpd['notes'] ?? '-')) . '</div></div>';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>LGPD (JSON):</strong><br>' . h($lgpdRaw) . '</div></div>';
    echo '</div>';
} else {
    echo '<pre style="white-space:pre-wrap;margin:0" class="pill">' . h($lgpdRaw) . '</pre>';
}
echo '</div>';

echo '<div class="pvPanel" data-panel="resp">';
$respRaw = (string)($p['responsible_json'] ?? '');
$resp = null;
if (trim($respRaw) !== '') {
    $decoded = json_decode($respRaw, true);
    if (is_array($decoded)) {
        $resp = $decoded;
    }
}

if (is_array($resp) && (isset($resp['name']) || isset($resp['relationship']) || isset($resp['cpf']) || isset($resp['phone']) || isset($resp['email']) || isset($resp['notes']))) {
    echo '<div class="grid">';
    echo '<div class="col6"><div class="pill" style="display:block"><strong>Nome:</strong> ' . h((string)($resp['name'] ?? '-')) . '</div></div>';
    echo '<div class="col6"><div class="pill" style="display:block"><strong>Parentesco:</strong> ' . h((string)($resp['relationship'] ?? '-')) . '</div></div>';
    echo '<div class="col6"><div class="pill" style="display:block"><strong>CPF:</strong> ' . h((string)($resp['cpf'] ?? '-')) . '</div></div>';
    echo '<div class="col6"><div class="pill" style="display:block"><strong>Telefone:</strong> ' . h((string)($resp['phone'] ?? '-')) . '</div></div>';
    echo '<div class="col6"><div class="pill" style="display:block"><strong>E-mail:</strong> ' . h((string)($resp['email'] ?? '-')) . '</div></div>';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Observações:</strong> ' . h((string)($resp['notes'] ?? '-')) . '</div></div>';
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Responsável (JSON):</strong><br>' . h($respRaw) . '</div></div>';
    echo '</div>';
} else {
    echo '<pre style="white-space:pre-wrap;margin:0" class="pill">' . h($respRaw) . '</pre>';
}
echo '</div>';

echo '<div class="pvPanel" data-panel="adm">';
echo '<div class="grid">';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Status:</strong> ' . h((string)($p['admin_status'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Unidade:</strong> ' . h((string)($p['unit'] ?? '-')) . '</div></div>';
echo '<div class="col12"><div class="pill" style="display:block"><strong>Médico responsável:</strong> ' . h((string)($p['doctor_responsible'] ?? '-')) . '</div></div>';
echo '<div class="col12">';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
if ($canManage) {
    echo '<a class="btn" href="/documents_list.php?entity_type=patient&entity_id=' . (int)$p['id'] . '">Documentos do paciente</a>';
    echo '<a class="btn btnPrimary" href="/documents_upload.php?entity_type=patient&entity_id=' . (int)$p['id'] . '&return_to=' . urlencode('/patients_view.php?id=' . (int)$p['id']) . '">Novo documento</a>';
}
echo '</div>';
echo '</div>';
echo '<div class="col12"><div class="pill" style="display:block"><strong>Documentos (JSON):</strong><br>' . h((string)($p['documents_json'] ?? '')) . '</div></div>';
echo '</div>';
echo '</div>';

echo '<script>';
echo '(function(){var tabs=document.querySelectorAll(".pvTab");var panels=document.querySelectorAll(".pvPanel");';
echo 'var act=function(k){tabs.forEach(function(b){b.classList.toggle("isActive", b.getAttribute("data-tab")===k);}); panels.forEach(function(p){p.classList.toggle("isActive", p.getAttribute("data-panel")===k);});};';
echo 'tabs.forEach(function(b){b.addEventListener("click", function(){act(b.getAttribute("data-tab"));});});})();';
echo '</script>';

echo '</section>';

// Vínculos (somente visualização para profissional)

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:8px">Profissionais vinculados</div>';
echo '<div style="display:grid;gap:10px">';
foreach ($linked as $l) {
    $txt = (string)$l['name'] . ' — ' . (string)$l['email'];
    if (!empty($l['specialty'])) {
        $txt .= ' (' . (string)$l['specialty'] . ')';
    }
    if ((int)$l['is_active'] !== 1) {
        $txt .= ' [inativo]';
    }
    echo '<div class="pill" style="display:block">' . h($txt) . '</div>';
}
if (count($linked) === 0) {
    echo '<div class="pill" style="display:block">-</div>';
}

echo '</div>';
echo '</section>';

// Prontuário

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div style="font-weight:900;margin-bottom:8px">Prontuário</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
if ($canManage) {
    echo '<a class="btn btnPrimary" href="/patient_prontuario_create.php?patient_id=' . (int)$p['id'] . '">Novo registro</a>';
}
echo '</div>';
echo '</div>';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>Data</th><th>Origem</th><th>Profissional</th><th>Sessões</th><th>Observações</th>';
echo '<th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($entries as $e) {
    echo '<tr>';
    echo '<td>' . h((string)$e['occurred_at']) . '</td>';
    echo '<td>' . h((string)$e['origin']) . '</td>';
    echo '<td>' . h((string)($e['professional_name'] ?? '-')) . '</td>';
    echo '<td>' . h((string)($e['sessions_count'] ?? '')) . '</td>';
    echo '<td>' . h(mb_strimwidth((string)($e['notes'] ?? ''), 0, 140, '...')) . '</td>';
    echo '<td style="text-align:right">';
    if ($canManage && isset($e['id'])) {
        echo '<a class="btn" href="/patient_prontuario_edit.php?id=' . (int)$e['id'] . '">Editar</a> ';
        echo '<form method="post" action="/patient_prontuario_delete_post.php" style="display:inline" onsubmit="return confirm(\'Excluir este registro do prontuário?\')">';
        echo '<input type="hidden" name="id" value="' . (int)$e['id'] . '">';
        echo '<button class="btn" type="submit" style="height:34px">Excluir</button>';
        echo '</form>';
    } else {
        echo '-';
    }
    echo '</td>';
    echo '</tr>';
}
if (count($entries) === 0) {
    echo '<tr><td colspan="6" class="pill" style="display:table-cell;padding:12px">Sem registros.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
