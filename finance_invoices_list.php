<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
$uid = auth_user_id();
if (!rbac_user_can($uid, 'finance.manage') && !rbac_user_can($uid, 'finance.view')) {
    rbac_require_permission('finance.manage');
}

require_once __DIR__ . '/app/finance_invoices_helper.php';
finance_invoices_ensure_schema();

$direction = isset($_GET['direction']) && in_array($_GET['direction'], ['income', 'expense'], true) ? (string)$_GET['direction'] : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$where = [];
$params = [];
if ($direction !== '') {
    $where[] = 'fi.direction = :dir';
    $params['dir'] = $direction;
}
if ($q !== '') {
    $where[] = '(fi.invoice_number LIKE :q OR fi.supplier_name LIKE :q OR fi.notes LIKE :q OR fi.file_name LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT fi.*, u.name AS professional_name, p.full_name AS patient_name
        FROM finance_invoices fi
        LEFT JOIN users u ON u.id = fi.professional_user_id
        LEFT JOIN patients p ON p.id = fi.patient_id
        $whereSql
        ORDER BY fi.id DESC
        LIMIT 500";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totais do conjunto filtrado.
$totalIncome = 0.0;
$totalExpense = 0.0;
foreach ($rows as $r) {
    if ($r['direction'] === 'income') { $totalIncome += (float)$r['amount']; }
    else { $totalExpense += (float)$r['amount']; }
}

$canManage = rbac_user_can($uid, 'finance.manage');

view_header('Notas Fiscais');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Notas Fiscais</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Controle de notas fiscais correlacionadas ao que foi pago e recebido.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
if ($canManage) {
    echo '<a class="btn btnPrimary" href="/finance_invoice_upload.php">+ Nova Nota Fiscal</a>';
}
echo '<a class="btn" href="/finance_dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/finance_invoices_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="direction" style="min-width:200px">';
$dirOpts = ['' => 'Todas (recebidas e pagas)', 'income' => 'Recebidas (entrada)', 'expense' => 'Pagas (saída)'];
foreach ($dirOpts as $k => $label) {
    $sel = ($direction === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (nº NF, fornecedor, obs...)" style="flex:1;min-width:240px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';
echo '</section>';

// Totais
echo '<section class="card col6" style="padding:20px">';
echo '<div style="font-size:13px;font-weight:700;color:hsl(var(--muted-foreground))">Total em NFs Recebidas (entrada)</div>';
echo '<div style="font-size:28px;font-weight:900;color:hsl(142,76%,36%)">R$ ' . number_format($totalIncome, 2, ',', '.') . '</div>';
echo '</section>';
echo '<section class="card col6" style="padding:20px">';
echo '<div style="font-size:13px;font-weight:700;color:hsl(var(--muted-foreground))">Total em NFs Pagas (saída)</div>';
echo '<div style="font-size:28px;font-weight:900;color:hsl(var(--destructive))">R$ ' . number_format($totalExpense, 2, ',', '.') . '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Direção</th><th>Nº NF</th><th>Emissão</th><th>Valor</th><th>Fornecedor/Vínculo</th><th>Arquivo</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    $isIncome = $r['direction'] === 'income';
    $dirBadge = $isIncome
        ? '<span class="badge" style="background:hsl(142,76%,36%);color:#fff;font-size:12px">Recebida</span>'
        : '<span class="badge" style="background:hsl(0,84%,60%);color:#fff;font-size:12px">Paga</span>';

    $vinc = trim((string)($r['supplier_name'] ?? ''));
    if ($vinc === '') {
        $vinc = (string)($r['professional_name'] ?? $r['patient_name'] ?? '-');
    }

    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td>' . $dirBadge . '</td>';
    echo '<td>' . h((string)($r['invoice_number'] ?? '-')) . '</td>';
    echo '<td>' . h($r['issue_date'] ? date('d/m/Y', strtotime((string)$r['issue_date'])) : '-') . '</td>';
    echo '<td>R$ ' . number_format((float)$r['amount'], 2, ',', '.') . '</td>';
    echo '<td>' . h($vinc) . '</td>';
    echo '<td>';
    echo '<a class="btn" href="/finance_invoice_download.php?id=' . (int)$r['id'] . '" target="_blank">⬇ ' . h(mb_strimwidth((string)($r['file_name'] ?? 'arquivo'), 0, 24, '...')) . '</a>';
    echo '</td>';
    echo '<td style="text-align:right;white-space:nowrap">';
    echo '<a class="btn" href="/finance_invoice_download.php?id=' . (int)$r['id'] . '" target="_blank">Ver</a>';
    echo '</td>';
    echo '</tr>';
}
if (count($rows) === 0) {
    echo '<tr><td colspan="8" style="padding:14px">Nenhuma nota fiscal cadastrada.</td></tr>';
}
echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
