<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$unit = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';
$insurance = isset($_GET['insurance']) ? trim((string)$_GET['insurance']) : '';
$uf = isset($_GET['uf']) ? trim((string)$_GET['uf']) : '';
$attendance = isset($_GET['attendance']) ? trim((string)$_GET['attendance']) : '';
$dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';

// Garantir colunas de encerramento em patient_assignments (caso a migration não tenha rodado)
foreach ([
    "ALTER TABLE patient_assignments ADD COLUMN ended_at DATETIME NULL",
    "ALTER TABLE patient_assignments ADD COLUMN end_reason_id INT UNSIGNED NULL",
] as $alterPa) {
    try { db()->exec($alterPa); } catch (Throwable $e) { /* já existe */ }
}

// Subquery: pega o assignment MAIS RECENTE de cada paciente (por id desc), com
// status do atendimento e o motivo de encerramento (quando finalizado).
$sql = "SELECT pt.id, pt.full_name, pt.cpf, pt.whatsapp, pt.phone_primary, pt.email, pt.created_at,
               pt.admin_status, pt.unit, pt.insurance_name,
               la.status AS attendance_status,
               ter.name AS end_reason_name,
               ter.slug AS end_reason_slug
        FROM patients pt
        LEFT JOIN (
            SELECT pa1.patient_id, pa1.status, pa1.end_reason_id
            FROM patient_assignments pa1
            INNER JOIN (
                SELECT patient_id, MAX(id) AS max_id
                FROM patient_assignments
                GROUP BY patient_id
            ) latest ON latest.patient_id = pa1.patient_id AND latest.max_id = pa1.id
        ) la ON la.patient_id = pt.id
        LEFT JOIN treatment_end_reasons ter ON ter.id = la.end_reason_id
        WHERE pt.deleted_at IS NULL";
$params = [];

if ($q !== '') {
    $sql .= ' AND (pt.full_name LIKE :q1 OR pt.cpf LIKE :q2 OR pt.whatsapp LIKE :q3 OR pt.phone_primary LIKE :q4 OR pt.email LIKE :q5)';
    $qLike = '%' . $q . '%';
    $params['q1'] = $qLike;
    $params['q2'] = $qLike;
    $params['q3'] = $qLike;
    $params['q4'] = $qLike;
    $params['q5'] = $qLike;
}

if ($status !== '') {
    $sql .= ' AND pt.admin_status = :st';
    $params['st'] = $status;
}

if ($unit !== '') {
    $sql .= ' AND pt.unit LIKE :unit';
    $params['unit'] = '%' . $unit . '%';
}

if ($insurance !== '') {
    $sql .= ' AND pt.insurance_name LIKE :ins';
    $params['ins'] = '%' . $insurance . '%';
}

if ($uf !== '') {
    $sql .= ' AND pt.address_state = :uf';
    $params['uf'] = $uf;
}

// Filtro por estado do atendimento (mais recente)
if ($attendance !== '') {
    if ($attendance === 'sem') {
        $sql .= ' AND la.status IS NULL';
    } elseif ($attendance === 'ativo') {
        // Qualquer atendimento em andamento (não finalizado/cancelado)
        $sql .= " AND la.status IN ('admitted','awaiting_documents','awaiting_financial_approval','approved')";
    } else {
        $sql .= ' AND la.status = :att';
        $params['att'] = $attendance;
    }
}

// Filtro por período de cadastro
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $sql .= ' AND DATE(pt.created_at) >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $sql .= ' AND DATE(pt.created_at) <= :date_to';
    $params['date_to'] = $dateTo;
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

$buildPageUrl = function (int $p) use ($q, $status, $unit, $insurance, $uf, $attendance, $dateFrom, $dateTo): string {
    $qs = array_filter([
        'q' => $q, 'status' => $status, 'unit' => $unit, 'insurance' => $insurance,
        'uf' => $uf, 'attendance' => $attendance, 'date_from' => $dateFrom, 'date_to' => $dateTo,
        'page' => $p,
    ], fn($v) => $v !== '' && $v !== null);
    return '/patients_list.php?' . http_build_query($qs);
};

// Listas para popular os selects de filtro (valores distintos já cadastrados).
try {
    $filterInsurers = db()->query("SELECT DISTINCT insurance_name FROM patients WHERE deleted_at IS NULL AND insurance_name IS NOT NULL AND insurance_name != '' ORDER BY insurance_name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { $filterInsurers = []; }
try {
    $filterUnits = db()->query("SELECT DISTINCT unit FROM patients WHERE deleted_at IS NULL AND unit IS NOT NULL AND unit != '' ORDER BY unit ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { $filterUnits = []; }
try {
    $filterUfs = db()->query("SELECT DISTINCT address_state FROM patients WHERE deleted_at IS NULL AND address_state IS NOT NULL AND address_state != '' ORDER BY address_state ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { $filterUfs = []; }
try {
    $filterAdminStatuses = db()->query("SELECT DISTINCT admin_status FROM patients WHERE deleted_at IS NULL AND admin_status IS NOT NULL AND admin_status != '' ORDER BY admin_status ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { $filterAdminStatuses = []; }

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

echo '<form method="get" action="/patients_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">';

echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (nome, CPF, WhatsApp, telefone, e-mail)" style="flex:1;min-width:240px">';

// Status administrativo (select)
echo '<select name="status" style="min-width:140px">';
echo '<option value="">Status (cadastro)</option>';
foreach ($filterAdminStatuses as $st) {
    $sel = ($status === $st) ? ' selected' : '';
    echo '<option value="' . h((string)$st) . '"' . $sel . '>' . h((string)$st) . '</option>';
}
echo '</select>';

// Estado do atendimento (select)
$attendanceOptions = [
    'ativo' => 'Em atendimento (ativo)',
    'admitted' => 'Admitido',
    'awaiting_documents' => 'Aguardando documentos',
    'awaiting_financial_approval' => 'Aguardando financeiro',
    'completed' => 'Finalizado',
    'cancelled' => 'Cancelado',
    'sem' => 'Sem atendimento',
];
echo '<select name="attendance" style="min-width:170px">';
echo '<option value="">Atendimento (todos)</option>';
foreach ($attendanceOptions as $val => $label) {
    $sel = ($attendance === $val) ? ' selected' : '';
    echo '<option value="' . h($val) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';

// Convênio / Operadora (select)
echo '<select name="insurance" style="min-width:160px">';
echo '<option value="">Convênio (todos)</option>';
foreach ($filterInsurers as $ins) {
    $sel = ($insurance === $ins) ? ' selected' : '';
    echo '<option value="' . h((string)$ins) . '"' . $sel . '>' . h((string)$ins) . '</option>';
}
echo '</select>';

// Unidade (select)
echo '<select name="unit" style="min-width:150px">';
echo '<option value="">Unidade (todas)</option>';
foreach ($filterUnits as $un) {
    $sel = ($unit === $un) ? ' selected' : '';
    echo '<option value="' . h((string)$un) . '"' . $sel . '>' . h((string)$un) . '</option>';
}
echo '</select>';

// UF (select)
if (!empty($filterUfs)) {
    echo '<select name="uf" style="min-width:90px">';
    echo '<option value="">UF</option>';
    foreach ($filterUfs as $ufOpt) {
        $sel = ($uf === $ufOpt) ? ' selected' : '';
        echo '<option value="' . h((string)$ufOpt) . '"' . $sel . '>' . h((string)$ufOpt) . '</option>';
    }
    echo '</select>';
}

// Período de cadastro
echo '<label style="font-size:12px;color:hsl(var(--muted-foreground))">De</label>';
echo '<input type="date" name="date_from" value="' . h($dateFrom) . '" style="width:150px">';
echo '<label style="font-size:12px;color:hsl(var(--muted-foreground))">Até</label>';
echo '<input type="date" name="date_to" value="' . h($dateTo) . '" style="width:150px">';

echo '<button class="btn btnPrimary" type="submit">Filtrar</button>';
if ($q !== '' || $status !== '' || $unit !== '' || $insurance !== '' || $uf !== '' || $attendance !== '' || $dateFrom !== '' || $dateTo !== '') {
    echo '<a class="btn" href="/patients_list.php">Limpar</a>';
}
echo '</form>';

echo '</section>';


echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Nome</th><th>CPF</th><th>Contato</th><th>Status</th><th>Atendimento</th><th>Unidade</th><th>Convênio</th><th>Criado</th><th style="text-align:right">Ações</th>';
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

    // Badge do estado do atendimento (mais recente do paciente).
    $attStatus = (string)($r['attendance_status'] ?? '');
    $endReason = (string)($r['end_reason_name'] ?? '');
    $endSlug = (string)($r['end_reason_slug'] ?? '');
    $attHtml = '<span style="color:hsl(var(--muted-foreground))">Sem atendimento</span>';
    if ($attStatus !== '') {
        if ($attStatus === 'completed') {
            // Finalizado: mostrar o motivo do encerramento (hospitalização, óbito, etc.).
            $reasonLabel = $endReason !== '' ? $endReason : 'Finalizado';
            // Cor conforme o tipo de encerramento.
            $bg = '#e5e7eb'; $fg = '#374151';
            if ($endSlug === 'obito') { $bg = '#fee2e2'; $fg = '#991b1b'; }
            elseif ($endSlug === 'hospitalizacao') { $bg = '#fef3c7'; $fg = '#92400e'; }
            elseif ($endSlug === 'finalizado_normal') { $bg = '#d1fae5'; $fg = '#065f46'; }
            $attHtml = '<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;background:' . $bg . ';color:' . $fg . '">🏁 ' . h($reasonLabel) . '</span>';
        } else {
            // Em andamento: traduzir os status ativos.
            $labelMap = [
                'admitted' => ['Em atendimento', '#d1fae5', '#065f46'],
                'awaiting_documents' => ['Aguardando documentos', '#fef3c7', '#92400e'],
                'awaiting_financial_approval' => ['Aguardando financeiro', '#ffedd5', '#9a3412'],
                'approved' => ['Aprovado', '#dbeafe', '#1e40af'],
                'cancelled' => ['Cancelado', '#e5e7eb', '#374151'],
            ];
            $info = $labelMap[$attStatus] ?? [$attStatus, '#dbeafe', '#1e40af'];
            $attHtml = '<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;background:' . $info[1] . ';color:' . $info[2] . '">' . h($info[0]) . '</span>';
        }
    }

    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td style="font-weight:700">' . h((string)$r['full_name']) . '</td>';
    echo '<td>' . h((string)($r['cpf'] ?? '')) . '</td>';
    echo '<td>' . h($contact) . '</td>';
    echo '<td>' . h((string)($r['admin_status'] ?? '')) . '</td>';
    echo '<td>' . $attHtml . '</td>';
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
