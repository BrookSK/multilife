<?php

declare(strict_types=1);

/**
 * Registra a INDICAÇÃO de outro profissional feita por um profissional cobrado.
 *
 * Permite que a equipe lance no sistema o contato indicado (nome/telefone/
 * especialidade), dando continuidade ao fluxo de captação.
 *
 * POST: demand_id, referred_name, referred_phone, referred_specialty, note,
 *       referrer_phone (opcional)
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método inválido.']);
    exit;
}

$demandId = (int)($_POST['demand_id'] ?? 0);
$referredName = trim((string)($_POST['referred_name'] ?? ''));
$referredPhone = preg_replace('/\D+/', '', (string)($_POST['referred_phone'] ?? ''));
$referredSpecialty = trim((string)($_POST['referred_specialty'] ?? ''));
$note = trim((string)($_POST['note'] ?? ''));
$referrerPhone = preg_replace('/\D+/', '', (string)($_POST['referrer_phone'] ?? ''));

if ($demandId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Demanda inválida.']);
    exit;
}
if ($referredName === '' && $referredPhone === '') {
    echo json_encode(['success' => false, 'error' => 'Informe ao menos o nome ou o telefone do profissional indicado.']);
    exit;
}

$db = db();

// Garantir tabela (idempotente).
try {
    $db->exec("CREATE TABLE IF NOT EXISTS demand_referrals (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        demand_id BIGINT UNSIGNED NOT NULL,
        referrer_user_id INT UNSIGNED NULL,
        referrer_phone VARCHAR(30) NULL,
        referred_name VARCHAR(255) NULL,
        referred_phone VARCHAR(30) NULL,
        referred_specialty VARCHAR(120) NULL,
        note TEXT NULL,
        status ENUM('new','contacted','discarded') NOT NULL DEFAULT 'new',
        created_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_dref_demand (demand_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

try {
    $stmt = $db->prepare(
        'INSERT INTO demand_referrals (demand_id, referrer_phone, referred_name, referred_phone, referred_specialty, note, status, created_by_user_id)
         VALUES (:d, :rp, :rn, :rph, :rs, :note, \'new\', :uid)'
    );
    $stmt->execute([
        'd' => $demandId,
        'rp' => $referrerPhone !== '' ? $referrerPhone : null,
        'rn' => $referredName !== '' ? $referredName : null,
        'rph' => $referredPhone !== '' ? $referredPhone : null,
        'rs' => $referredSpecialty !== '' ? $referredSpecialty : null,
        'note' => $note !== '' ? $note : null,
        'uid' => auth_user_id(),
    ]);
    $refId = (int)$db->lastInsertId();

    audit_log('create', 'demand_referrals', (string)$refId, null, [
        'demand_id' => $demandId,
        'referred_name' => $referredName,
        'referred_phone' => $referredPhone,
    ]);

    echo json_encode(['success' => true, 'referral_id' => $refId, 'message' => 'Indicação registrada.']);
    exit;
} catch (Throwable $e) {
    error_log('[DEMAND_REFERRAL_CREATE] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao registrar indicação: ' . $e->getMessage()]);
    exit;
}
