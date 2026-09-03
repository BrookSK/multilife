<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$unit = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';
$insurance = isset($_GET['insurance']) ? trim((string)$_GET['insurance']) : '';

$sql = 'SELECT id, full_name, cpf, whatsapp, phone_primary, email, created_at,
               admin_status, unit, insurance_name
        FROM patients
        WHERE deleted_at IS NULL';
$params = [];

if ($q !== '') {
    $sql .= ' AND (full_name LIKE :q1 OR cpf LIKE :q2 OR whatsapp LIKE :q3 OR phone_primary LIKE :q4 OR email LIKE :q5)';
    $qLike = '%' . $q . '%';
    $params['q1'] = $qLike;
    $params['q2'] = $qLike;
    $params['q3'] = $qLike;
    $params['q4'] = $qLike;
    $params['q5'] = $qLike;
}

if ($status !== '') {
    $sql .= ' AND admin_status = :st';
    $params['st'] = $status;
}

if ($unit !== '') {
    $sql .= ' AND unit LIKE :unit';
    $params['unit'] = '%' . $unit . '%';
}

if ($insurance !== '') {
    $sql .= ' AND insurance_name LIKE :ins';
    $params['ins'] = '%' . $insurance . '%';
}

// ITEM 16: Paginação no backend
$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Contagem total (reaproveita o WHERE já montado em $sql após "FROM patients ...")
$countSql = preg_replace('/^SELECT .*? FROM patients/is', 'SELECT COUNT(*) FROM patients', $sql, 1);
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$sql .= ' ORDER BY id ASC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$buildPageUrl = function (int $p) use ($q, $status, $unit, $insurance): string {
    $qs = array_filter([
        'q' => $q, 'status' => $status, 'unit' => $unit, 'insurance' => $insurance, 'page' => $p,
    ], fn($v) => $v !== '' && $v !== null);
    return '/patients_list.php?' . http_build_query($qs);
};

view_header('Pacientes');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Pacientes</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Cadastro e prontuário.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/patients_create.php">Novo paciente</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/patients_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';

echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (nome, CPF, WhatsApp, telefone, e-mail)" style="flex:1;min-width:240px">';
echo '<input name="status" value="' . h($status) . '" placeholder="Status" style="width:160px">';
echo '<input name="unit" value="' . h($unit) . '" placeholder="Unidade" style="width:180px">';
echo '<input name="insurance" value="' . h($insurance) . '" placeholder="Convênio" style="width:180px">';

echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';


echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Nome</th><th>CPF</th><th>Contato</th><th>Status</th><th>Unidade</th><th>Convênio</th><th>Criado</th><th style="text-align:right">Ações</th>';
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
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td style="font-weight:700">' . h((string)$r['full_name']) . '</td>';
    echo '<td>' . h((string)($r['cpf'] ?? '')) . '</td>';
    echo '<td>' . h($contact) . '</td>';
    echo '<td>' . h((string)($r['admin_status'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['unit'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['insurance_name'] ?? '')) . '</td>';
    echo '<td>' . h((string)$r['created_at']) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/patients_view.php?id=' . (int)$r['id'] . '">Abrir</a> ';
    echo '<a class="btn" href="/patients_edit.php?id=' . (int)$r['id'] . '">Editar</a> ';
    echo '<a class="btn" href="/patients_links_edit.php?id=' . (int)$r['id'] . '">Vínculos</a> ';
    echo '<form method="post" action="/patients_delete_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
    echo '<button class="btn" type="submit" onclick="return confirm(\'Excluir (lógico) este paciente?\')">Excluir</button>';
    echo '</form>';
    echo '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';

// Paginação (item 16)
if ($totalPages > 1) {
    echo '<div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:16px;flex-wrap:wrap">';
    if ($page > 1) echo '<a class="btn" href="' . h($buildPageUrl($page - 1)) . '">← Anterior</a>';
    $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
    if ($start > 1) { echo '<a class="btn" href="' . h($buildPageUrl(1)) . '">1</a>'; if ($start > 2) echo '<span style="color:hsl(var(--muted-foreground))">…</span>'; }
    for ($i = $start; $i <= $end; $i++) {
        echo $i === $page ? '<span class="btn btnPrimary" style="pointer-events:none">' . $i . '</span>' : '<a class="btn" href="' . h($buildPageUrl($i)) . '">' . $i . '</a>';
    }
    if ($end < $totalPages) { if ($end < $totalPages - 1) echo '<span style="color:hsl(var(--muted-foreground))">…</span>'; echo '<a class="btn" href="' . h($buildPageUrl($totalPages)) . '">' . $totalPages . '</a>'; }
    if ($page < $totalPages) echo '<a class="btn" href="' . h($buildPageUrl($page + 1)) . '">Próxima →</a>';
    echo '</div>';
    echo '<div style="text-align:center;margin-top:8px;font-size:13px;color:hsl(var(--muted-foreground))">Página ' . $page . ' de ' . $totalPages . ' • ' . number_format($totalRows, 0, ',', '.') . ' pacientes</div>';
}

echo '</section>';

echo '</div>';

view_footer();
