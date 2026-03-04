<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

$status = isset($_GET['status']) ? (string)$_GET['status'] : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$allowed = ['', 'pendente', 'recebido', 'inadimplente'];
if (!in_array($status, $allowed, true)) {
    $status = '';
}

$sql = 'SELECT ar.id, ar.amount, ar.due_at, ar.status, ar.received_at,
               a.id AS appointment_id, a.first_at,
               p.full_name AS patient_name,
               u.name AS professional_name
        FROM finance_accounts_receivable ar
        INNER JOIN appointments a ON a.id = ar.appointment_id
        INNER JOIN patients p ON p.id = ar.patient_id
        INNER JOIN users u ON u.id = ar.professional_user_id
        WHERE p.deleted_at IS NULL';
$params = [];

if ($status !== '') {
    $sql .= ' AND ar.status = :status';
    $params['status'] = $status;
}

if ($q !== '') {
    $sql .= ' AND (p.full_name LIKE :q OR u.name LIKE :q OR ar.appointment_id LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' ORDER BY ar.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Financeiro - Contas a Receber');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">Contas a Receber</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">Geradas ao criar o agendamento.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/finance_payable_list.php">Contas a Pagar</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/finance_receivable_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
$labels = [
    '' => 'Todos',
    'pendente' => 'Pendente',
    'recebido' => 'Recebido',
    'inadimplente' => 'Inadimplente',
];
foreach ($labels as $k => $label) {
    $sel = ($status === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (paciente/profissional/agendamento)" style="flex:1;min-width:240px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';


echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table style="width:100%;border-collapse:separate;border-spacing:0 10px">';
echo '<thead><tr style="text-align:left;color:rgba(234,240,255,.72);font-size:12px">';
echo '<th>ID</th><th>Agendamento</th><th>Data</th><th>Paciente</th><th>Profissional</th><th>Valor</th><th>Status</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10)">';
    echo '<td style="padding:12px;border-top-left-radius:14px;border-bottom-left-radius:14px">' . (int)$r['id'] . '</td>';
    echo '<td style="padding:12px">#' . (int)$r['appointment_id'] . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['first_at']) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['patient_name']) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['professional_name']) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['amount']) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['status']) . '</td>';
    echo '<td style="padding:12px;border-top-right-radius:14px;border-bottom-right-radius:14px;text-align:right">';

    echo '<form method="post" action="/finance_receivable_set_status_post.php" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap">';
    echo '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
    echo '<select name="status" style="border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:8px 10px;outline:none;font-size:13px">';
    foreach (['pendente','recebido','inadimplente'] as $st) {
        $sel = ((string)$r['status'] === $st) ? ' selected' : '';
        echo '<option value="' . h($st) . '"' . $sel . '>' . h($st) . '</option>';
    }
    echo '</select>';
    echo '<button class="btn" type="submit">Salvar</button>';
    echo '</form>';

    echo '</td>';
    echo '</tr>';
}
if (count($rows) === 0) {
    echo '<tr><td colspan="8" class="pill" style="display:table-cell;padding:12px">Sem registros.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
