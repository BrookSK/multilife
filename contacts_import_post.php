<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
if (!rbac_user_can((int)auth_user_id(), 'chat.manage') && !rbac_user_can((int)auth_user_id(), 'demands.manage')) {
    rbac_require_permission('chat.manage');
}

require_once __DIR__ . '/app/imported_contacts_helper.php';
imported_contacts_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /contacts_import.php');
    exit;
}

$defaultCategory = (string)($_POST['default_category'] ?? 'outros');
if (!in_array($defaultCategory, ['profissional', 'operadora', 'paciente', 'outros'], true)) {
    $defaultCategory = 'outros';
}

$bundled = isset($_POST['bundled']) && $_POST['bundled'] === '1';

if ($bundled) {
    $path = __DIR__ . '/docs/contatos.csv';
    if (!is_file($path)) {
        flash_set('error', 'A planilha docs/contatos.csv não foi encontrada no servidor.');
        header('Location: /contacts_import.php');
        exit;
    }
    @set_time_limit(300);
    $result = imported_contacts_import_file($path, auth_user_id(), $defaultCategory);
    $source = 'contatos.csv';
} else {
    if (!isset($_FILES['contacts_file']) || ($_FILES['contacts_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash_set('error', 'Envie um arquivo CSV.');
        header('Location: /contacts_import.php');
        exit;
    }
    $tmp = (string)$_FILES['contacts_file']['tmp_name'];
    $source = mb_substr((string)$_FILES['contacts_file']['name'], 0, 120);
    @set_time_limit(300);
    $result = imported_contacts_import_file($tmp, auth_user_id(), $defaultCategory);
    if ($source !== '' && $source !== 'contatos.csv') {
        try {
            db()->prepare("UPDATE imported_contacts SET source = :src WHERE source = :old AND created_by_user_id = :uid")
                ->execute(['src' => $source, 'old' => basename($tmp), 'uid' => auth_user_id()]);
        } catch (Throwable $e) {
        }
    }
}

$by = $result['by'] ?? [];
audit_log('import', 'imported_contacts', '0', null, [
    'source' => $source,
    'imported' => $result['imported'] ?? 0,
    'skipped' => $result['skipped'] ?? 0,
    'by' => $by,
]);

$parts = [];
foreach (imported_contacts_categories() as $key => $label) {
    $n = (int)($by[$key] ?? 0);
    if ($n > 0) {
        $parts[] = $label . ': ' . $n;
    }
}
$detail = $parts ? ' (' . implode(', ', $parts) . ')' : '';
flash_set('success', 'Importação concluída: ' . (int)($result['imported'] ?? 0) . ' contato(s)'
    . $detail
    . (((int)($result['skipped'] ?? 0)) > 0 ? '. Ignorados: ' . (int)$result['skipped'] : '') . '.');
header('Location: /imported_contacts_list.php');
exit;
