<?php
/**
 * ITEM 6: Marca manualmente uma autorização como respondida/aprovada.
 * Usado quando o envio automático de e-mail está desabilitado — o usuário confirma
 * que a operadora/cliente respondeu, e o fluxo continua para a Pré-Admissão.
 *
 * Replica o efeito de uma aprovação: cria patient_assignment, lançamentos financeiros,
 * atualiza status da autorização e da demanda.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$authId = (int)($_POST['auth_id'] ?? 0);
if ($authId <= 0) {
    flash_set('error', 'Autorização inválida.');
    header('Location: /authorization_list.php');
    exit;
}

$db = db();
$userId = (int)(auth_user_id() ?? 1);

try {
    // Buscar dados da autorização + demanda
    $stmt = $db->prepare("
        SELECT ar.*, d.specialty AS demand_specialty, d.title AS demand_title
        FROM authorization_requests ar
        INNER JOIN demands d ON d.id = ar.demand_id
        WHERE ar.id = :id AND ar.status = 'aguardando_autorizacao'
        LIMIT 1
    ");
    $stmt->execute(['id' => $authId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        flash_set('error', 'Autorização não encontrada ou já processada.');
        header('Location: /authorization_view.php?id=' . $authId);
        exit;
    }

    $demandId = (int)$request['demand_id'];
    $patientId = (int)$request['patient_id'];
    $professionalUserId = (int)$request['professional_user_id'];
    $proposalValue = (float)$request['proposal_value'];
    $agreedValue = (float)$request['agreed_value'];
    $totalSessions = (int)$request['total_sessions'];
    $frequency = (string)$request['frequency'];
    $startDate = (string)($request['start_date'] ?: date('Y-m-d'));

    // Validar paciente
    $vp = $db->prepare("SELECT id FROM patients WHERE id = :id AND deleted_at IS NULL");
    $vp->execute(['id' => $patientId]);
    if (!$vp->fetch()) {
        flash_set('error', 'Paciente não encontrado no sistema.');
        header('Location: /authorization_view.php?id=' . $authId);
        exit;
    }

    $db->beginTransaction();

    $agreedPerSession = $totalSessions > 0 ? ($agreedValue / $totalSessions) : $agreedValue;
    $proposalPerSession = $totalSessions > 0 ? ($proposalValue / $totalSessions) : $proposalValue;

    // Criar patient_assignment (confirmado)
    $insAssignment = $db->prepare(
        "INSERT INTO patient_assignments
        (demand_id, patient_id, professional_user_id, assigned_by_user_id,
         specialty, service_type, session_quantity, session_frequency,
         payment_value, agreed_value, authorized_value, notes, status, created_at)
        VALUES (:did, :pid, :puid, :abuid, :spec, :stype, :sq, :sfreq, :pv, :av, :authv, :notes, 'confirmed', NOW())"
    );
    $insAssignment->execute([
        'did' => $demandId,
        'pid' => $patientId,
        'puid' => $professionalUserId,
        'abuid' => $userId,
        'spec' => (string)($request['demand_specialty'] ?? ''),
        'stype' => (string)($request['demand_title'] ?? 'Atendimento'),
        'sq' => $totalSessions,
        'sfreq' => $frequency,
        'pv' => $agreedPerSession,
        'av' => $agreedPerSession,
        'authv' => $proposalPerSession,
        'notes' => 'Autorização confirmada manualmente (envio automático desabilitado).',
    ]);
    $assignmentId = (int)$db->lastInsertId();

    // Lançamentos financeiros
    $totalReceita = $proposalValue * $totalSessions;
    $totalDespesa = $agreedValue * $totalSessions;
    try {
        $db->prepare(
            "INSERT INTO financial_entries (entry_type, category, assignment_id, patient_id, amount, description, entry_date, status, created_by_user_id, created_at)
             VALUES ('income', 'servicos', :aid, :pid, :amt, :desc, :dt, 'pending', :cbuid, NOW())"
        )->execute([
            'aid' => $assignmentId, 'pid' => $patientId, 'amt' => $totalReceita,
            'desc' => "Receita - Atendimento #$assignmentId - $totalSessions sessões", 'dt' => $startDate, 'cbuid' => $userId,
        ]);
        $db->prepare(
            "INSERT INTO financial_entries (entry_type, category, assignment_id, patient_id, professional_user_id, amount, description, entry_date, status, created_by_user_id, created_at)
             VALUES ('expense', 'profissionais', :aid, :pid, :puid, :amt, :desc, :dt, 'pending', :cbuid, NOW())"
        )->execute([
            'aid' => $assignmentId, 'pid' => $patientId, 'puid' => $professionalUserId, 'amt' => $totalDespesa,
            'desc' => "Despesa - Atendimento #$assignmentId - $totalSessions sessões", 'dt' => $startDate, 'cbuid' => $userId,
        ]);
    } catch (Throwable $e) {
        error_log('[AUTH_MARK_RESPONDED] Erro ao lançar financeiro: ' . $e->getMessage());
    }

    // Atualizar autorização
    $db->prepare(
        "UPDATE authorization_requests
         SET status = 'autorizacao_aprovada', response_received_at = NOW(), patient_assignment_id = :aid
         WHERE id = :id"
    )->execute(['aid' => $assignmentId, 'id' => $authId]);

    // Atualizar demanda
    $db->prepare("UPDATE demands SET status = 'autorizacao_aprovada' WHERE id = :id")->execute(['id' => $demandId]);

    // Histórico
    try {
        $db->prepare(
            "INSERT INTO authorization_request_history (authorization_request_id, action, notes, user_id)
             VALUES (:auth_id, 'approved', :notes, :uid)"
        )->execute([
            'auth_id' => $authId,
            'notes' => 'Marcada como respondida manualmente pelo usuário (envio automático desabilitado).',
            'uid' => $userId,
        ]);
    } catch (Throwable $e) {}

    $db->commit();

    audit_log('update', 'authorization_requests', (string)$authId, null, ['action' => 'marcada_respondida_manual']);

    flash_set('success', 'Autorização confirmada. O atendimento seguiu para a Pré-Admissão.');
    header('Location: /pre_admissao.php');
    exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[AUTH_MARK_RESPONDED] ' . $e->getMessage());
    flash_set('error', 'Erro ao marcar como respondida: ' . $e->getMessage());
    header('Location: /authorization_view.php?id=' . $authId);
    exit;
}
