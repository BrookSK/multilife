<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin_logo_upload.php');
    exit;
}

// Verificar se arquivo foi enviado
if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
    flash_set('error', 'Erro ao fazer upload do arquivo.');
    header('Location: /admin_logo_upload.php');
    exit;
}

$file = $_FILES['logo'];
$fileName = $file['name'];
$fileTmpName = $file['tmp_name'];
$fileSize = $file['size'];
$fileError = $file['error'];

// Validar extensão
$allowedExtensions = ['jpg', 'jpeg', 'png', 'svg'];
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if (!in_array($fileExtension, $allowedExtensions, true)) {
    flash_set('error', 'Formato de arquivo não permitido. Use PNG, JPG ou SVG.');
    header('Location: /admin_logo_upload.php');
    exit;
}

// Validar tamanho (2MB)
if ($fileSize > 2 * 1024 * 1024) {
    flash_set('error', 'Arquivo muito grande. Tamanho máximo: 2MB.');
    header('Location: /admin_logo_upload.php');
    exit;
}

// Criar diretório de uploads se não existir
$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

// Gerar nome único para o arquivo
$newFileName = 'logo_' . time() . '.' . $fileExtension;
$destination = $uploadsDir . '/' . $newFileName;

// Mover arquivo
if (!move_uploaded_file($fileTmpName, $destination)) {
    flash_set('error', 'Erro ao salvar o arquivo.');
    header('Location: /admin_logo_upload.php');
    exit;
}

// Determinar tipo de logo
$type = isset($_POST['type']) && in_array($_POST['type'], ['system', 'login'], true) ? $_POST['type'] : 'system';
$settingKey = $type === 'login' ? 'app.login_logo_url' : 'app.logo_url';

// Salvar URL da logo nas configurações
$logoUrl = '/uploads/' . $newFileName;

try {
    $db = db();
    
    // Verificar se configuração já existe
    $stmt = $db->prepare('SELECT id FROM admin_settings WHERE setting_key = :key');
    $stmt->execute(['key' => $settingKey]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Atualizar
        $stmt = $db->prepare('UPDATE admin_settings SET setting_value = :value WHERE setting_key = :key');
        $stmt->execute(['value' => $logoUrl, 'key' => $settingKey]);
    } else {
        // Inserir
        $stmt = $db->prepare('INSERT INTO admin_settings (setting_key, setting_value) VALUES (:key, :value)');
        $stmt->execute(['key' => $settingKey, 'value' => $logoUrl]);
    }
    
    flash_set('success', 'Logo atualizada com sucesso!');
    header('Location: /admin_settings.php');
    exit;
    
} catch (Exception $e) {
    error_log('Erro ao salvar logo: ' . $e->getMessage());
    flash_set('error', 'Erro ao salvar configuração da logo.');
    header('Location: /admin_logo_upload.php');
    exit;
}
