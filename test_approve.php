<?php
// Arquivo de teste simples para verificar se o endpoint está funcionando

error_log("=== TESTE DIRETO DE APROVACAO ===");

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$assignmentId = 5; // ID fixo para teste

error_log("Buscando atendimento ID: " . $assignmentId);

$stmt = db()->prepare("
    SELECT pa.*, p.full_name as patient_name
    FROM patient_assignments pa
    LEFT JOIN patients p ON p.id = pa.patient_id
    WHERE pa.id = ?
");
$stmt->execute([$assignmentId]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    error_log("ERRO: Atendimento não encontrado");
    die("Atendimento não encontrado");
}

error_log("Atendimento encontrado: " . print_r($assignment, true));
error_log("Status atual: " . $assignment['status']);

// Testar se consegue fazer UPDATE simples
try {
    db()->beginTransaction();
    
    $updateStmt = db()->prepare("
        UPDATE patient_assignments
        SET status = 'approved'
        WHERE id = ?
    ");
    $updateStmt->execute([$assignmentId]);
    
    $affected = $updateStmt->rowCount();
    error_log("Linhas afetadas pelo UPDATE: " . $affected);
    
    db()->commit();
    
    echo "UPDATE executado com sucesso! Linhas afetadas: " . $affected;
    error_log("=== TESTE CONCLUIDO COM SUCESSO ===");
    
} catch (Exception $e) {
    db()->rollBack();
    error_log("ERRO no teste: " . $e->getMessage());
    echo "ERRO: " . $e->getMessage();
}
