<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_docs.submit');

$uid = (int)auth_user_id();
$status = isset($_GET['status']) ? (string)$_GET['status'] : '';

$allowed = ['', 'draft', 'submitted', 'approved', 'rejected'];
if (!in_array($status, $allowed, true)) {
    $status = '';
}

$sql = 'SELECT d.id, d.patient_id, d.patient_ref, d.sessions_count, d.status, d.due_at, d.submitted_at, d.created_at,
               p.full_name AS patient_name
        FROM professional_documentations d
        LEFT JOIN patients p ON p.id = d.patient_id
        WHERE d.professional_user_id = :uid';
$params = ['uid' => $uid];

if ($status !== '') {
    $sql .= ' AND status = :status';
    $params['status'] = $status;
}

$sql .= ' ORDER BY id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Minhas documentações');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Minhas documentações</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Prazo padrão: 48h após criação. Envie para revisão.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/professional_docs_create.php">Novo formulário</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/professional_docs_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="min-width:220px">';
$opts = [
    '' => 'Todos',
    'draft' => 'Rascunho',
    'submitted' => 'Enviado',
    'approved' => 'Aprovado',
    'rejected' => 'Reprovado',
];
foreach ($opts as $k => $label) {
    $sel = ($status === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Paciente</th><th>Sessões</th><th>Status</th><th>Vencimento</th><th>Enviado</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    $pt = $r['patient_name'] ? ((string)$r['patient_name'] . ' (#' . (int)($r['patient_id'] ?? 0) . ')') : (string)$r['patient_ref'];
    echo '<td style="font-weight:700">' . h($pt) . '</td>';
    echo '<td>' . (int)$r['sessions_count'] . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td>' . h((string)($r['due_at'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['submitted_at'] ?? '')) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/professional_docs_edit.php?id=' . (int)$r['id'] . '">Abrir</a> ';
    echo '<form method="post" action="/professional_docs_submit_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
    echo '<button class="btn" type="submit" style="height:34px">Enviar</button>';
    echo '</form>';
    echo '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
