<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.view_linked');

$uid = (int)auth_user_id();
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$sql = 'SELECT p.id, p.full_name, p.whatsapp, p.phone_primary, p.email
        FROM patients p
        INNER JOIN patient_professionals pp ON pp.patient_id = p.id
        WHERE p.deleted_at IS NULL AND pp.professional_user_id = :uid AND pp.is_active = 1';
$params = ['uid' => $uid];

if ($q !== '') {
    $sql .= ' AND (p.full_name LIKE :q OR p.cpf LIKE :q OR p.whatsapp LIKE :q OR p.phone_primary LIKE :q OR p.email LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' ORDER BY p.full_name ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Meus pacientes');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">Meus pacientes</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">Apenas pacientes vinculados ao seu perfil.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/professional_my_patients_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar" style="flex:1;min-width:240px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<button class="btn" type="submit">Buscar</button>';
echo '</form>';

echo '</section>';


echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table style="width:100%;border-collapse:separate;border-spacing:0 10px">';
echo '<thead><tr style="text-align:left;color:rgba(234,240,255,.72);font-size:12px">';
echo '<th>Nome</th><th>Contato</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    $contact = trim((string)($r['whatsapp'] ?? ''));
    if ($contact === '') {
        $contact = trim((string)($r['phone_primary'] ?? ''));
    }
    if ($contact === '') {
        $contact = trim((string)($r['email'] ?? ''));
    }
    if ($contact === '') {
        $contact = '-';
    }

    echo '<tr style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10)">';
    echo '<td style="padding:12px;border-top-left-radius:14px;border-bottom-left-radius:14px">' . h((string)$r['full_name']) . '</td>';
    echo '<td style="padding:12px">' . h($contact) . '</td>';
    echo '<td style="padding:12px;border-top-right-radius:14px;border-bottom-right-radius:14px;text-align:right">';
    echo '<a class="btn" href="/patients_view.php?id=' . (int)$r['id'] . '">Abrir</a>';
    echo '</td>';
    echo '</tr>';
}
if (count($rows) === 0) {
    echo '<tr><td colspan="3" class="pill" style="display:table-cell;padding:12px">Nenhum paciente vinculado.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
