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

$sql = 'SELECT id, patient_ref, sessions_count, status, due_at, submitted_at, created_at
        FROM professional_documentations
        WHERE professional_user_id = :uid';
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
echo '<div style="font-size:22px;font-weight:800">Minhas documentações</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">Prazo padrão: 48h após criação. Envie para revisão.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/professional_docs_create.php">Novo formulário</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/professional_docs_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
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
echo '<table style="width:100%;border-collapse:separate;border-spacing:0 10px">';
echo '<thead><tr style="text-align:left;color:rgba(234,240,255,.72);font-size:12px">';
echo '<th>ID</th><th>Paciente</th><th>Sessões</th><th>Status</th><th>Vencimento</th><th>Enviado</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10)">';
    echo '<td style="padding:12px;border-top-left-radius:14px;border-bottom-left-radius:14px">' . (int)$r['id'] . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['patient_ref']) . '</td>';
    echo '<td style="padding:12px">' . (int)$r['sessions_count'] . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['status']) . '</td>';
    echo '<td style="padding:12px">' . h((string)($r['due_at'] ?? '')) . '</td>';
    echo '<td style="padding:12px">' . h((string)($r['submitted_at'] ?? '')) . '</td>';
    echo '<td style="padding:12px;border-top-right-radius:14px;border-bottom-right-radius:14px;text-align:right">';
    echo '<a class="btn" href="/professional_docs_edit.php?id=' . (int)$r['id'] . '">Abrir</a> ';
    echo '<form method="post" action="/professional_docs_submit_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
    echo '<button class="btn" type="submit">Enviar</button>';
    echo '</form>';
    echo '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
