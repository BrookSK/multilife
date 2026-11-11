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

    // Polo: o escolhido (precisa ser do mesmo cliente) ou o padrão da operadora.
    $poloId = (int)($_POST['polo_id'] ?? 0);
    $poloName = '';
    if ($poloId > 0) {
        $polo = polos_find($poloId);
        if ($polo === null) {
            echo json_encode(['success' => false, 'error' => 'Polo inválido.']);
            exit;
        }
        if ($clientId !== null && $polo['client_id'] !== null && (int)$polo['client_id'] !== $clientId) {
            echo json_encode(['success' => false, 'error' => 'O polo escolhido não pertence ao cliente da operadora.']);
            exit;
        }
        $poloName = (string)$polo['name'];
    } else {
        $poloId = isset($op['polo_id']) && $op['polo_id'] !== null ? (int)$op['polo_id'] : 0;
        if ($poloId > 0 && ($polo = polos_find($poloId))) {
            $poloName = (string)$polo['name'];
        }
    }

    $stmt = db()->prepare("
        UPDATE patient_assignments
        SET health_insurer_id = :hi, client_id = :cid, polo_id = :polo
        WHERE id = :id
    ");
    $stmt->execute([
        'hi' => $healthInsurerId,
        'cid' => $clientId,
        'polo' => $poloId > 0 ? $poloId : null,
        'id' => $assignmentId,
    ]);

    audit_log('update', 'patient_assignments', (string)$assignmentId, null, [
        'health_insurer_id' => $healthInsurerId,
        'client_id' => $clientId,
        'polo_id' => $poloId > 0 ? $poloId : null,
    ]);

    echo json_encode([
        'success' => true,
        'health_insurer_id' => $healthInsurerId,
        'client_id' => $clientId,
        'polo_id' => $poloId > 0 ? $poloId : null,
        'operator_name' => (string)$op['name'],
        'client_name' => (string)($op['client_name'] ?? ''),
        'polo_name' => $poloName,
    ]);
} catch (Throwable $e) {
    error_log('[MONITORAMENTO_SET_OPERATOR] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
