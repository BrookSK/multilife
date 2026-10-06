<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

require_once __DIR__ . '/app/finance_invoices_helper.php';
finance_invoices_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /finance_invoices_list.php');
    exit;
}

$direction = (string)($_POST['direction'] ?? '');
if (!in_array($direction, ['income', 'expense'], true)) {
    flash_set('error', 'Selecione a direção (recebida ou paga).');
    header('Location: /finance_invoice_upload.php');
    exit;
}

$amount = (float)str_replace(',', '.', (string)($_POST['amount'] ?? '0'));
if ($amount < 0) { $amount = 0.0; }

$invoiceNumber = trim((string)($_POST['invoice_number'] ?? ''));
$issueDate = trim((string)($_POST['issue_date'] ?? ''));
if ($issueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) {
    $issueDate = '';
}
$supplierName = trim((string)($_POST['supplier_name'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$financialEntryId = (int)($_POST['financial_entry_id'] ?? 0);
$professionalUserId = (int)($_POST['professional_user_id'] ?? 0);
$patientId = (int)($_POST['patient_id'] ?? 0);

// Validar arquivo.
if (!isset($_FILES['invoice_file']) || ($_FILES['invoice_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    flash_set('error', 'Envie o arquivo da nota fiscal.');
    header('Location: /finance_invoice_upload.php');
    exit;
}

$file = $_FILES['invoice_file'];
$fileName = (string)$file['name'];
$fileTmp = (string)$file['tmp_name'];
$fileSize = (int)$file['size'];
$fileType = (string)$file['type'];

$allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'xml'];
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    flash_set('error', 'Formato não aceito. Use PDF, JPG, PNG, WEBP ou XML.');
    header('Location: /finance_invoice_upload.php');
    exit;
}
$maxSize = 15 * 1024 * 1024; // 15MB
if ($fileSize > $maxSize) {
    flash_set('error', 'Arquivo muito grande. Máximo: 15MB.');
    header('Location: /finance_invoice_upload.php');
    exit;
}

$uploadDir = __DIR__ . '/uploads/finance_invoices';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

$newFileName = 'nf_' . $direction . '_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
$destPath = $uploadDir . '/' . $newFileName;
$storedPath = '/uploads/finance_invoices/' . $newFileName;

if (!move_uploaded_file($fileTmp, $destPath)) {
    flash_set('error', 'Falha ao salvar o arquivo.');
    header('Location: /finance_invoice_upload.php');
    exit;
}

try {
    $stmt = db()->prepare(
        "INSERT INTO finance_invoices
           (direction, financial_entry_id, patient_id, professional_user_id,
            invoice_number, supplier_name, issue_date, amount, notes,
            file_path, file_name, file_type, file_size, created_by_user_id)
         VALUES
           (:dir, :fe, :pid, :prof, :num, :sup, :issue, :amt, :notes,
            :fpath, :fname, :ftype, :fsize, :uid)"
    );
    $stmt->execute([
        'dir' => $direction,
        'fe' => $financialEntryId > 0 ? $financialEntryId : null,
        'pid' => $patientId > 0 ? $patientId : null,
        'prof' => $professionalUserId > 0 ? $professionalUserId : null,
        'num' => $invoiceNumber !== '' ? $invoiceNumber : null,
        'sup' => $supplierName !== '' ? $supplierName : null,
        'issue' => $issueDate !== '' ? $issueDate : null,
        'amt' => $amount,
        'notes' => $notes !== '' ? $notes : null,
        'fpath' => $storedPath,
        'fname' => $fileName,
        'ftype' => $fileType !== '' ? $fileType : null,
        'fsize' => $fileSize,
        'uid' => auth_user_id(),
    ]);
    $invoiceId = (int)db()->lastInsertId();

    audit_log('create', 'finance_invoices', (string)$invoiceId, null, [
        'direction' => $direction,
        'amount' => $amount,
        'invoice_number' => $invoiceNumber,
    ]);

    flash_set('success', 'Nota fiscal registrada com sucesso.');
    header('Location: /finance_invoices_list.php?direction=' . $direction);
    exit;
} catch (Throwable $e) {
    // Remover arquivo se o INSERT falhar.
    if (is_file($destPath)) { @unlink($destPath); }
    error_log('[FINANCE_INVOICES] Erro ao salvar NF: ' . $e->getMessage());
    flash_set('error', 'Erro ao salvar a nota fiscal: ' . $e->getMessage());
    header('Location: /finance_invoice_upload.php');
    exit;
}
