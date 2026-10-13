<?php
/**
 * Define/atualiza a OPERADORA (e o CLIENTE derivado) de um atendimento
 * (patient_assignments) a partir da tela de Monitoramento.
 *
 * Usado para preencher a operadora que faltou (cards que vieram sem
 * classificação no e-mail). O cliente é resolvido automaticamente a partir
 * da operadora escolhida.
 *
 * POST:
 *   - assignment_id      : ID do patient_assignments (obrigatório)
 *   - health_insurer_id  : ID da operadora (obrigatório)
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_login();
rbac_require_permission('demands.manage');

$assignmentId = (int)($_POST['assignment_id'] ?? 0);
$healthInsurerId = (int)($_POST['health_insurer_id'] ?? 0);

if ($assignmentId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Atendimento não informado.']);
    exit;
}
if ($healthInsurerId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Operadora não informada.']);
    exit;
}

try {
    clients_ensure_schema();

    // Validar operadora e obter o cliente vinculado.
    $op = operators_find($healthInsurerId);
    if ($op === null) {
        echo json_encode(['success' => false, 'error' => 'Operadora inválida.']);
        exit;
    }
    $clientId = $op['client_id'] !== null ? (int)$op['client_id'] : null;

    $stmt = db()->prepare("
        UPDATE patient_assignments
        SET health_insurer_id = :hi, client_id = :cid
        WHERE id = :id
    ");
    $stmt->execute([
        'hi' => $healthInsurerId,
        'cid' => $clientId,
        'id' => $assignmentId,
    ]);

    audit_log('update', 'patient_assignments', (string)$assignmentId, null, [
        'health_insurer_id' => $healthInsurerId,
        'client_id' => $clientId,
    ]);

    echo json_encode([
        'success' => true,
        'health_insurer_id' => $healthInsurerId,
        'client_id' => $clientId,
        'operator_name' => (string)$op['name'],
        'client_name' => (string)($op['client_name'] ?? ''),
    ]);
} catch (Throwable $e) {
    error_log('[MONITORAMENTO_SET_OPERATOR] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
