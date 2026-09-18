<?php

declare(strict_types=1);

/**
 * Gera ou renova o link PÚBLICO de atualização cadastral (/atualizar-cadastro?token=...)
 * de um profissional em pré-cadastro, a partir da tela de Cadastros Pendentes.
 *
 * Reaproveita o registration_token existente (se válido) ou gera um novo.
 * A URL final é exibida via flash para a equipe copiar/enviar ao profissional.
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('users.manage');

$backUrl = '/professional_pre_registrations_list.php';

$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    flash_set('error', 'Profissional inválido.');
    header('Location: ' . $backUrl);
    exit;
}

// Garantir colunas de apoio (idempotente).
try { db()->exec("ALTER TABLE users ADD COLUMN registration_token VARCHAR(64) NULL"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN registration_token_created_at DATETIME NULL"); } catch (Throwable $e) {}

$stmt = db()->prepare('SELECT id, name, registration_token, is_pre_registration FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    flash_set('error', 'Profissional não encontrado.');
    header('Location: ' . $backUrl);
    exit;
}

// Reaproveita token válido; senão gera um novo (mesmo critério de pre_admissao_approve.php).
$regToken = (string)($user['registration_token'] ?? '');
$renewed = false;
if ($regToken === '' || strlen($regToken) < 32) {
    $regToken = bin2hex(random_bytes(32));
    db()->prepare('UPDATE users SET registration_token = :t, registration_token_created_at = NOW() WHERE id = :id')
        ->execute(['t' => $regToken, 'id' => $userId]);
    $renewed = true;
}

// Monta a URL pública.
$publicBaseUrl = trim((string)admin_setting_get('app.public_base_url', ''));
if ($publicBaseUrl === '') {
    $publicBaseUrl = trim((string)admin_setting_get('app.base_url', ''));
}
if ($publicBaseUrl === '') {
    $publicBaseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'multilife.onsolutionsbrasil.com.br');
}
$registrationUrl = rtrim($publicBaseUrl, '/') . '/atualizar-cadastro?token=' . urlencode($regToken);

audit_log('update', 'professional_registration_link', (string)$userId, null, [
    'name' => $user['name'] ?? null,
    'renewed' => $renewed,
]);

flash_set('success', ($renewed ? 'Link gerado' : 'Link ativo') . ' para ' . (string)($user['name'] ?? 'profissional') . ': ' . $registrationUrl);
header('Location: ' . $backUrl);
exit;
