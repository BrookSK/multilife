<?php

declare(strict_types=1);

/**
 * Cadastro rápido (PRÉ-CADASTRO) de paciente a partir do Chat ao Vivo.
 * Pede apenas o essencial: Nome, Telefone (opcional), Cidade/UF (opcional).
 * Cria o paciente marcado como pré-cadastro (admin_status='Pré-cadastro',
 * is_pre_registration=1) para a equipe completar o cadastro longo depois.
 * Responde em JSON (chamado via fetch).
 */

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function pqc_json(bool $ok, string $message, array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

set_exception_handler(function (Throwable $e): void {
    error_log('[QUICK_PATIENT_CREATE] Exceção não tratada: ' . $e->getMessage());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'message' => 'Erro ao pré-cadastrar: ' . $e->getMessage()]);
    exit;
});
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[QUICK_PATIENT_CREATE] Erro fatal: ' . $err['message']);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'message' => 'Erro interno ao pré-cadastrar. Verifique os logs.']);
    }
});

try {
    auth_require_login();
} catch (Throwable $e) {
    pqc_json(false, 'Sessão expirada. Faça login novamente.');
}

$uid = (int)auth_user_id();
if (!rbac_user_can($uid, 'patients.manage') && !rbac_user_can($uid, 'demands.manage')) {
    pqc_json(false, 'Você não tem permissão para cadastrar pacientes.');
}

$name = trim((string)($_POST['name'] ?? ''));
$phoneRaw = trim((string)($_POST['phone'] ?? ''));
$city = trim((string)($_POST['city'] ?? ''));
$state = trim((string)($_POST['state'] ?? ''));
$pendingItemId = (int)($_POST['pending_item_id'] ?? 0);
$force = (string)($_POST['force'] ?? '') === '1';

if ($name === '') {
    pqc_json(false, 'Informe o nome do paciente.');
}

$phone = preg_replace('/\D+/', '', $phoneRaw);

$db = db();

// Garantir coluna de marcação de pré-cadastro (idempotente).
try { $db->exec("ALTER TABLE patients ADD COLUMN is_pre_registration TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

// ====================================================================
// VERIFICAÇÃO DE DUPLICADOS (a menos que force=1): nome parecido / telefone igual.
// ====================================================================
if (!$force) {
    $matches = [];
    $seen = [];
    try {
        // 1) Telefone exatamente igual (phone_primary ou whatsapp).
        if ($phone !== '') {
            $stmt = $db->prepare("
                SELECT id, full_name, phone_primary, whatsapp, address_city
                FROM patients
                WHERE deleted_at IS NULL AND (
                    REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone_primary,''),' ',''),'-',''),'(',''),')','') = :phone
                    OR REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(whatsapp,''),' ',''),'-',''),'(',''),')','') = :phone2
                )
                LIMIT 5
            ");
            $stmt->execute(['phone' => $phone, 'phone2' => $phone]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $seen[(int)$row['id']] = true;
                $matches[] = [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['full_name'],
                    'phone' => (string)($row['phone_primary'] ?: $row['whatsapp'] ?: ''),
                    'city' => (string)($row['address_city'] ?? ''),
                    'reason' => 'phone',
                ];
            }
        }

        // 2) Nome parecido (um contém o outro, case-insensitive).
        $nameNorm = mb_strtolower($name);
        $stmtN = $db->prepare("
            SELECT id, full_name, phone_primary, whatsapp, address_city
            FROM patients
            WHERE deleted_at IS NULL AND (
                LOWER(full_name) LIKE :contains OR :nameNorm LIKE CONCAT('%', LOWER(full_name), '%')
            )
            LIMIT 10
        ");
        $stmtN->execute(['contains' => '%' . $nameNorm . '%', 'nameNorm' => $nameNorm]);
        foreach ($stmtN->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($seen[(int)$row['id']])) {
                continue;
            }
            $seen[(int)$row['id']] = true;
            $matches[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['full_name'],
                'phone' => (string)($row['phone_primary'] ?: $row['whatsapp'] ?: ''),
                'city' => (string)($row['address_city'] ?? ''),
                'reason' => 'name',
            ];
        }
    } catch (Throwable $e) {
        error_log('[QUICK_PATIENT_CREATE] Verificação de duplicados falhou (ignorada): ' . $e->getMessage());
        $matches = [];
    }

    if (count($matches) > 0) {
        pqc_json(false, 'Encontramos paciente(s) parecido(s). Reutilize um existente ou confirme para cadastrar mesmo assim.', [
            'duplicates' => $matches,
            'needs_confirmation' => true,
        ]);
    }
}

$db->beginTransaction();
try {
    $ins = $db->prepare(
        "INSERT INTO patients (full_name, phone_primary, whatsapp, address_city, address_state, admin_status, is_pre_registration, created_at)
         VALUES (:name, :phone1, :phone2, :city, :state, 'Pré-cadastro', 1, NOW())"
    );
    $ins->execute([
        'name' => $name,
        'phone1' => $phone !== '' ? $phone : null,
        'phone2' => $phone !== '' ? $phone : null,
        'city' => $city !== '' ? $city : null,
        'state' => $state !== '' ? strtoupper(substr($state, 0, 2)) : null,
    ]);
    $newId = (int)$db->lastInsertId();

    // Resolver a pendência de pré-cadastro, se veio de uma.
    if ($pendingItemId > 0) {
        try {
            $db->prepare("UPDATE pending_items SET status = 'done', resolved_at = NOW() WHERE id = :id")
                ->execute(['id' => $pendingItemId]);
        } catch (Throwable $e) { /* pending_items pode não existir */ }
    }
    // Resolver também qualquer pendência aberta com o mesmo nome (dedup por título).
    try {
        $db->prepare("UPDATE pending_items SET status = 'done', resolved_at = NOW()
            WHERE type = 'patient_pre_registration' AND status = 'open' AND title LIKE :t")
            ->execute(['t' => '%' . $name . '%']);
    } catch (Throwable $e) { /* ignora */ }

    $db->commit();

    audit_log('create', 'patients_pre_registration', (string)$newId, null, [
        'name' => $name, 'phone' => $phone, 'city' => $city, 'state' => $state,
    ]);

    pqc_json(true, 'Paciente pré-cadastrado com sucesso.', [
        'patient_id' => $newId,
        'name' => $name,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[QUICK_PATIENT_CREATE] ' . $e->getMessage());
    pqc_json(false, 'Erro ao pré-cadastrar paciente. Tente novamente.');
}
