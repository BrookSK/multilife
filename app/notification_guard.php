<?php

declare(strict_types=1);

/**
 * Notification Guard
 * 
 * Funções de validação para impedir envio de notificações
 * para pacientes com status restritivo (óbito, alta, inativo)
 * ou profissionais inativos.
 */

/**
 * Status de paciente que bloqueiam envio de notificações.
 * Comparação case-insensitive.
 */
define('PATIENT_BLOCKED_STATUSES', [
    'obito',
    'óbito',
    'falecido',
    'falecida',
    'deceased',
    'alta definitiva',
    'inativo',
    'inativa',
]);

/**
 * Verifica se um paciente pode receber notificações.
 * 
 * @param int $patientId ID do paciente
 * @return array ['allowed' => bool, 'reason' => string|null]
 */
function notification_guard_check_patient(int $patientId): array
{
    if ($patientId <= 0) {
        return ['allowed' => false, 'reason' => 'ID do paciente inválido'];
    }

    $stmt = db()->prepare(
        'SELECT id, full_name, admin_status, deleted_at FROM patients WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $patientId]);
    $patient = $stmt->fetch();

    if (!$patient) {
        return ['allowed' => false, 'reason' => 'Paciente não encontrado'];
    }

    // Paciente soft-deleted
    if (!empty($patient['deleted_at'])) {
        return ['allowed' => false, 'reason' => 'Paciente excluído do sistema'];
    }

    // Verificar admin_status contra lista de bloqueio
    $adminStatus = mb_strtolower(trim((string)($patient['admin_status'] ?? '')));
    if ($adminStatus !== '') {
        foreach (PATIENT_BLOCKED_STATUSES as $blocked) {
            if ($adminStatus === mb_strtolower($blocked) || str_contains($adminStatus, mb_strtolower($blocked))) {
                return [
                    'allowed' => false,
                    'reason' => 'Paciente com status "' . ($patient['admin_status']) . '" - notificação bloqueada'
                ];
            }
        }
    }

    return ['allowed' => true, 'reason' => null];
}

/**
 * Verifica se um profissional (user) pode receber notificações.
 * 
 * @param int $userId ID do usuário/profissional
 * @return array ['allowed' => bool, 'reason' => string|null]
 */
function notification_guard_check_professional(int $userId): array
{
    if ($userId <= 0) {
        return ['allowed' => false, 'reason' => 'ID do profissional inválido'];
    }

    $stmt = db()->prepare(
        'SELECT id, name, status FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['allowed' => false, 'reason' => 'Profissional não encontrado'];
    }

    if ((string)($user['status'] ?? '') !== 'active') {
        return [
            'allowed' => false,
            'reason' => 'Profissional inativo (status: ' . ($user['status'] ?? 'null') . ') - notificação bloqueada'
        ];
    }

    return ['allowed' => true, 'reason' => null];
}

/**
 * Verifica se um paciente pode receber notificações pelo telefone.
 * Útil quando só temos o telefone e não o ID.
 * 
 * @param string $phone Telefone do paciente
 * @return array ['allowed' => bool, 'reason' => string|null]
 */
function notification_guard_check_patient_by_phone(string $phone): array
{
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') {
        return ['allowed' => true, 'reason' => null]; // Sem telefone = não bloqueia (vai falhar no envio)
    }

    // Buscar paciente pelo telefone
    $stmt = db()->prepare(
        "SELECT id, full_name, admin_status, deleted_at FROM patients 
         WHERE (REPLACE(REPLACE(REPLACE(whatsapp,' ',''),'-',''),'(','') LIKE :p
            OR REPLACE(REPLACE(REPLACE(phone_primary,' ',''),'-',''),'(','') LIKE :p
            OR REPLACE(REPLACE(REPLACE(phone_secondary,' ',''),'-',''),'(','') LIKE :p)
         AND deleted_at IS NULL
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['p' => '%' . $digits]);
    $patient = $stmt->fetch();

    if (!$patient) {
        return ['allowed' => true, 'reason' => null]; // Paciente não encontrado = não bloqueia
    }

    return notification_guard_check_patient((int)$patient['id']);
}
