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
    'SELECT e.occurred_at, e.origin, e.sessions_count, e.notes, u.name AS professional_name
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
echo '<div style="font-weight:900;margin-bottom:8px">Prontuário (somente leitura)</div>';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>Data</th><th>Origem</th><th>Profissional</th><th>Sessões</th><th>Observações</th>';
echo '</tr></thead><tbody>';
foreach ($entries as $e) {
    echo '<tr>';
    echo '<td>' . h((string)$e['occurred_at']) . '</td>';
    echo '<td>' . h((string)$e['origin']) . '</td>';
    echo '<td>' . h((string)($e['professional_name'] ?? '-')) . '</td>';
    echo '<td>' . h((string)($e['sessions_count'] ?? '')) . '</td>';
    echo '<td>' . h(mb_strimwidth((string)($e['notes'] ?? ''), 0, 140, '...')) . '</td>';
    echo '</tr>';
}
if (count($entries) === 0) {
    echo '<tr><td colspan="5" class="pill" style="display:table-cell;padding:12px">Sem registros.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
