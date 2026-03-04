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
echo '<div style="font-size:22px;font-weight:800">Candidaturas de Profissionais</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.5">Painel Admin para aprovar/reprovar/solicitar complemento.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '<a class="btn" href="/apply_professional.php">Link público</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/professional_applications_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
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
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (nome, e-mail, telefone, conselho)" style="flex:1;min-width:240px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table style="width:100%;border-collapse:separate;border-spacing:0 10px">';
echo '<thead><tr style="text-align:left;color:rgba(234,240,255,.72);font-size:12px">';
echo '<th>ID</th><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Conselho</th><th>Status</th><th>Criado</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    $council = trim((string)($r['council_abbr'] ?? ''));
    $councilNum = trim((string)($r['council_number'] ?? ''));
    $councilUf = trim((string)($r['council_state'] ?? ''));
    $councilTxt = ($council !== '' || $councilNum !== '') ? ($council . ' ' . $councilNum . ($councilUf !== '' ? '/' . $councilUf : '')) : '-';

    echo '<tr style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10)">';
    echo '<td style="padding:12px;border-top-left-radius:14px;border-bottom-left-radius:14px">' . (int)$r['id'] . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['full_name']) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['email']) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['phone']) . '</td>';
    echo '<td style="padding:12px">' . h($councilTxt) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['status']) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['created_at']) . '</td>';
    echo '<td style="padding:12px;border-top-right-radius:14px;border-bottom-right-radius:14px;text-align:right">';
    echo '<a class="btn" href="/professional_applications_view.php?id=' . (int)$r['id'] . '">Abrir</a>';
    echo '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
