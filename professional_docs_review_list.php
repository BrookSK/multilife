<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_docs.review');

$status = isset($_GET['status']) ? (string)$_GET['status'] : 'submitted';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$allowed = ['submitted', 'approved', 'rejected'];
if (!in_array($status, $allowed, true)) {
    $status = 'submitted';
}

$sql = 'SELECT d.id, d.patient_ref, d.sessions_count, d.status, d.due_at, d.submitted_at, d.reviewed_at,
               u.name AS professional_name, u.email AS professional_email
        FROM professional_documentations d
        INNER JOIN users u ON u.id = d.professional_user_id
        WHERE d.status = :status';
$params = ['status' => $status];

if ($q !== '') {
    $sql .= ' AND (d.patient_ref LIKE :q OR u.name LIKE :q OR u.email LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' ORDER BY d.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Revisão de documentação');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Revisão de documentação</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Admin/Financeiro valida os envios do profissional.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/professional_docs_review_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="min-width:220px">';
$opts = [
    'submitted' => 'Pendentes',
    'approved' => 'Aprovadas',
    'rejected' => 'Reprovadas',
];
foreach ($opts as $k => $label) {
    $sel = ($status === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (paciente, profissional)" style="flex:1;min-width:240px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Profissional</th><th>Paciente</th><th>Sessões</th><th>Status</th><th>Enviado</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td style="font-weight:700">' . h((string)$r['professional_name']) . '<div style="font-size:12px;color:hsl(var(--muted-foreground))">' . h((string)$r['professional_email']) . '</div></td>';
    echo '<td>' . h((string)$r['patient_ref']) . '</td>';
    echo '<td>' . (int)$r['sessions_count'] . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td>' . h((string)($r['submitted_at'] ?? '')) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/professional_docs_review_view.php?id=' . (int)$r['id'] . '">Abrir</a>';
    echo '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
