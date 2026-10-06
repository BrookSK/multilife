<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

require_once __DIR__ . '/app/imported_contacts_helper.php';
imported_contacts_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /contacts_import.php');
    exit;
}

if (!isset($_FILES['contacts_file']) || ($_FILES['contacts_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    flash_set('error', 'Envie um arquivo CSV.');
    header('Location: /contacts_import.php');
    exit;
}

$delimiterOpt = (string)($_POST['delimiter'] ?? ';');
$delimiter = $delimiterOpt === 'tab' ? "\t" : ($delimiterOpt === ',' ? ',' : ';');
$hasHeader = isset($_POST['has_header']) && $_POST['has_header'] === '1';
$defaultCategory = (string)($_POST['default_category'] ?? 'outros');
if (!in_array($defaultCategory, ['cliente', 'profissional', 'outros'], true)) {
    $defaultCategory = 'outros';
}

$tmp = (string)$_FILES['contacts_file']['tmp_name'];
$origName = (string)$_FILES['contacts_file']['name'];

$raw = (string)file_get_contents($tmp);
if ($raw === '') {
    flash_set('error', 'Arquivo vazio.');
    header('Location: /contacts_import.php');
    exit;
}

// Remover BOM e normalizar para UTF-8.
$raw = str_replace("\xEF\xBB\xBF", '', $raw);
$enc = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
if ($enc && $enc !== 'UTF-8') {
    $raw = (string)mb_convert_encoding($raw, 'UTF-8', $enc);
}

$lines = preg_split('/\r\n|\r|\n/', $raw);
$lines = array_values(array_filter($lines, static fn($l) => trim((string)$l) !== ''));
if (count($lines) === 0) {
    flash_set('error', 'Nenhuma linha encontrada no arquivo.');
    header('Location: /contacts_import.php');
    exit;
}

// Detectar índices das colunas pelo cabeçalho (se houver).
$idxName = 0;
$idxPhone = 1;
$idxCategory = -1;
$startRow = 0;

if ($hasHeader) {
    $header = str_getcsv($lines[0], $delimiter);
    $startRow = 1;
    foreach ($header as $i => $colRaw) {
        $col = mb_strtolower(trim((string)$colRaw));
        if (preg_match('/\b(nome|name|contato|cliente|paciente|profissional)\b/u', $col) && $idxName === 0 && $i !== $idxPhone) {
            $idxName = $i;
        }
        if (preg_match('/\b(telefone|celular|phone|whatsapp|fone|numero|número|contato)\b/u', $col)) {
            $idxPhone = $i;
        }
        if (preg_match('/\b(categoria|tipo|category|grupo|classe)\b/u', $col)) {
            $idxCategory = $i;
        }
    }
}

$db = db();
$ins = $db->prepare(
    "INSERT INTO imported_contacts (name, phone, phone_raw, category, source, created_by_user_id)
     VALUES (:name, :phone, :phone_raw, :cat, :src, :uid)
     ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        phone_raw = VALUES(phone_raw),
        source = VALUES(source),
        updated_at = CURRENT_TIMESTAMP"
);

$imported = 0;
$skipped = 0;
$source = mb_substr($origName, 0, 120);

for ($r = $startRow; $r < count($lines); $r++) {
    $cells = str_getcsv($lines[$r], $delimiter);
    if (count($cells) === 0) { $skipped++; continue; }

    $nameRaw = trim((string)($cells[$idxName] ?? ''));
    $phoneRaw = trim((string)($cells[$idxPhone] ?? ''));
    $catMarker = $idxCategory >= 0 ? trim((string)($cells[$idxCategory] ?? '')) : '';

    // Nome é obrigatório; sem nome, pula.
    if ($nameRaw === '') { $skipped++; continue; }

    $category = imported_contacts_detect_category($catMarker, $nameRaw);
    if ($category === 'outros' && $catMarker === '') {
        // Sem marcador e nome não indicou categoria -> usa o default escolhido.
        $category = $defaultCategory;
    }

    $name = imported_contacts_clean_name($nameRaw);
    if ($name === '') { $name = $nameRaw; }

    $phone = imported_contacts_normalize_phone($phoneRaw);
    // Sem telefone não dá para deduplicar pela UNIQUE; ainda assim importamos
    // (phone NULL). Para evitar erro de UNIQUE com múltiplos NULL, usamos NULL
    // (MySQL permite múltiplos NULL em índice único).
    try {
        $ins->execute([
            'name' => mb_substr($name, 0, 200),
            'phone' => $phone !== '' ? $phone : null,
            'phone_raw' => $phoneRaw !== '' ? mb_substr($phoneRaw, 0, 64) : null,
            'cat' => $category,
            'src' => $source,
            'uid' => auth_user_id(),
        ]);
        $imported++;
    } catch (Throwable $e) {
        $skipped++;
        error_log('[IMPORTED_CONTACTS] linha ' . $r . ': ' . $e->getMessage());
    }
}

audit_log('import', 'imported_contacts', '0', null, [
    'source' => $source,
    'imported' => $imported,
    'skipped' => $skipped,
]);

flash_set('success', 'Importação concluída: ' . $imported . ' contato(s) importado(s)/atualizado(s)'
    . ($skipped > 0 ? ', ' . $skipped . ' ignorado(s)' : '') . '.');
header('Location: /imported_contacts_list.php');
exit;
