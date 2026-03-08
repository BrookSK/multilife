<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$contractId = (int)($_POST['contract_id'] ?? 0);

// Buscar contrato
$stmt = db()->prepare('SELECT * FROM hr_employee_contracts WHERE id = :id');
$stmt->execute(['id' => $contractId]);
$contract = $stmt->fetch();

if (!$contract) {
    flash_set('error', 'Contrato não encontrado.');
    header('Location: /hr_dashboard.php');
    exit;
}

// Buscar configuração do ZapSign
$config = db()->query('SELECT * FROM zapsign_config LIMIT 1')->fetch();

if ($config && !empty($config['api_token']) && !empty($contract['zapsign_doc_token'])) {
    try {
        $apiToken = $config['api_token'];
        $sandboxMode = (bool)$config['sandbox_mode'];
        $baseUrl = $sandboxMode ? 'https://sandbox.api.zapsign.com.br' : 'https://api.zapsign.com.br';
        
        // Cancelar documento no ZapSign
        $ch = curl_init($baseUrl . '/api/v1/docs/' . $contract['zapsign_doc_token'] . '/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiToken
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 204 || $httpCode === 200) {
            // Atualizar status no banco
            $stmt = db()->prepare('UPDATE hr_employee_contracts SET zapsign_status = "cancelled" WHERE id = :id');
            $stmt->execute(['id' => $contractId]);
            
            flash_set('success', 'Contrato cancelado com sucesso!');
        } else {
            throw new Exception('Erro ao cancelar contrato no ZapSign');
        }
    } catch (Exception $e) {
        flash_set('error', 'Erro ao cancelar contrato: ' . $e->getMessage());
    }
} else {
    // Apenas atualizar status local
    $stmt = db()->prepare('UPDATE hr_employee_contracts SET zapsign_status = "cancelled" WHERE id = :id');
    $stmt->execute(['id' => $contractId]);
    flash_set('success', 'Contrato cancelado localmente.');
}

header('Location: /hr_contract_generate.php?employee_id=' . $contract['employee_id']);
exit;
