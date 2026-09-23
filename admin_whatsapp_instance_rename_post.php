<?php
/**
 * Define o apelido amigável (display_name) de uma instância de WhatsApp.
 * Esse apelido é usado nos filtros do chat para o pessoal identificar o número.
 *
 * POST:
 *   - instance_id   : id da instância (whatsapp_instances.id)
 *   - display_name  : apelido (vazio = remover)
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_login();
rbac_require_permission('admin.settings.manage');

$instanceId = (int)($_POST['instance_id'] ?? 0);
$displayName = trim((string)($_POST['display_name'] ?? ''));
if (mb_strlen($displayName) > 120) {
    $displayName = mb_substr($displayName, 0, 120);
}

if ($instanceId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Instância não informada.']);
    exit;
}

try {
    // Garantir coluna (idempotente).
    try { db()->exec("ALTER TABLE whatsapp_instances ADD COLUMN display_name VARCHAR(120) NULL"); } catch (Throwable $e) {}

    $stmt = db()->prepare("UPDATE whatsapp_instances SET display_name = :dn WHERE id = :id");
    $stmt->execute(['dn' => $displayName !== '' ? $displayName : null, 'id' => $instanceId]);

    audit_log('update', 'whatsapp_instances', (string)$instanceId, null, ['display_name' => $displayName]);

    echo json_encode(['success' => true, 'display_name' => $displayName]);
} catch (Throwable $e) {
    error_log('[WA_INSTANCE_RENAME] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
