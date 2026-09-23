<?php
/**
 * Registra manualmente um atendimento (feito fora do sistema) na tela de
 * Monitoramento. O atendimento entra como uma sessão APROVADA em
 * billing_document_requirements e, por isso, é contabilizado no Fechamento
 * Mensal da competência correspondente à data informada.
 *
 * POST:
 *   - assignment_id : ID do patient_assignments (traz paciente, profissional,
 *                     operadora e valores). Obrigatório.
 *   - session_date  : data do atendimento (YYYY-MM-DD). Obrigatória.
 *   - notes         : observações (opcional).
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_login();
rbac_require_permission('demands.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método não permitido.']);
    exit;
}

$assignmentId = (int)($_POST['assignment_id'] ?? 0);
$sessionDate = trim((string)($_POST['session_date'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($assignmentId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Atendimento (assignment) não informado.']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate) || strtotime($sessionDate) === false) {
    echo json_encode(['success' => false, 'error' => 'Data do atendimento inválida.']);
    exit;
}

$db = db();

// Garantir colunas usadas (idempotente).
try { $db->exec("ALTER TABLE billing_document_requirements ADD COLUMN created_by_user_id INT UNSIGNED NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE billing_document_requirements ADD COLUMN is_manual TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

try {
    // Validar o assignment e obter paciente/profissional.
    $stmt = $db->prepare("
        SELECT pa.id, pa.patient_id, pa.professional_user_id
        FROM patient_assignments pa
        INNER JOIN patients p ON p.id = pa.patient_id
        WHERE pa.id = :id AND p.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute(['id' => $assignmentId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment) {
        echo json_encode(['success' => false, 'error' => 'Atendimento não encontrado.']);
        exit;
    }

    $patientId = (int)$assignment['patient_id'];
    $professionalId = (int)($assignment['professional_user_id'] ?? 0);
    if ($professionalId <= 0) {
        echo json_encode(['success' => false, 'error' => 'O atendimento selecionado não tem profissional vinculado.']);
        exit;
    }

    // Próximo session_number para respeitar a UNIQUE (assignment_id, session_number).
    $maxStmt = $db->prepare("SELECT COALESCE(MAX(session_number), 0) + 1 AS next_num
        FROM billing_document_requirements WHERE assignment_id = :aid");
    $maxStmt->execute(['aid' => $assignmentId]);
    $nextNumber = (int)$maxStmt->fetchColumn();
    if ($nextNumber < 1) {
        $nextNumber = 1;
    }

    $userId = (int)auth_user_id();

    // Inserir a sessão como aprovada e manual, sem monthly_closure_id (fica NULL,
    // para entrar no próximo fechamento da competência).
    $ins = $db->prepare("
        INSERT INTO billing_document_requirements
            (assignment_id, patient_id, professional_user_id, session_number, session_date,
             status, created_by_user_id, is_manual, created_at)
        VALUES
            (:aid, :pid, :prof, :snum, :sdate, 'approved', :uid, 1, NOW())
    ");
    $ins->execute([
        'aid' => $assignmentId,
        'pid' => $patientId,
        'prof' => $professionalId,
        'snum' => $nextNumber,
        'sdate' => $sessionDate,
        'uid' => $userId,
    ]);

    $newId = (int)$db->lastInsertId();

    audit_log('create', 'billing_document_requirements', (string)$newId, null, [
        'assignment_id' => $assignmentId,
        'patient_id' => $patientId,
        'professional_user_id' => $professionalId,
        'session_date' => $sessionDate,
        'session_number' => $nextNumber,
        'is_manual' => 1,
        'notes' => $notes,
    ]);

    echo json_encode(['success' => true, 'id' => $newId, 'session_number' => $nextNumber]);
} catch (Throwable $e) {
    error_log('[MONITORAMENTO_CREATE] Erro: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
