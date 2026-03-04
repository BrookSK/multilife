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
echo '<div style="font-size:22px;font-weight:900">Meus pacientes</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Apenas pacientes vinculados ao seu perfil.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/professional_my_patients_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar" style="flex:1;min-width:240px">';
echo '<button class="btn" type="submit">Buscar</button>';
echo '</form>';

echo '</section>';


echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
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

    echo '<tr>';
    echo '<td style="font-weight:700">' . h((string)$r['full_name']) . '</td>';
    echo '<td>' . h($contact) . '</td>';
    echo '<td style="text-align:right">';
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
