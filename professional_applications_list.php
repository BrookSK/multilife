<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_applications.manage');

$status = isset($_GET['status']) ? (string)$_GET['status'] : 'pending';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$allowed = ['pending', 'need_more_info', 'approved', 'rejected'];
if (!in_array($status, $allowed, true)) {
    $status = 'pending';
}

$sql = 'SELECT id, status, full_name, email, phone, council_abbr, council_number, council_state, created_at, reviewed_at
        FROM professional_applications
        WHERE status = :status';
$params = ['status' => $status];

if ($q !== '') {
    $sql .= ' AND (full_name LIKE :q OR email LIKE :q OR phone LIKE :q OR council_number LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' ORDER BY id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Candidaturas');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Candidaturas de Profissionais</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.5">Painel Admin para aprovar/reprovar/solicitar complemento.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '<a class="btn" href="/apply_professional.php">Link público</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/professional_applications_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="min-width:260px">';
$labels = [
    'pending' => 'Pendentes',
    'need_more_info' => 'Solicitar complemento',
    'approved' => 'Aprovadas',
    'rejected' => 'Reprovadas',
];
foreach ($labels as $k => $label) {
    $sel = ($status === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (nome, e-mail, telefone, conselho)" style="flex:1;min-width:240px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Conselho</th><th>Status</th><th>Criado</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    $council = trim((string)($r['council_abbr'] ?? ''));
    $councilNum = trim((string)($r['council_number'] ?? ''));
    $councilUf = trim((string)($r['council_state'] ?? ''));
    $councilTxt = ($council !== '' || $councilNum !== '') ? ($council . ' ' . $councilNum . ($councilUf !== '' ? '/' . $councilUf : '')) : '-';

    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td style="font-weight:700">' . h((string)$r['full_name']) . '</td>';
    echo '<td>' . h((string)$r['email']) . '</td>';
    echo '<td>' . h((string)$r['phone']) . '</td>';
    echo '<td>' . h($councilTxt) . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td>' . h((string)$r['created_at']) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/professional_applications_view.php?id=' . (int)$r['id'] . '">Abrir</a>';
    echo '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
