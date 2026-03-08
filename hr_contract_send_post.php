<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$employeeId = (int)($_POST['employee_id'] ?? 0);
$templateId = (int)($_POST['template_id'] ?? 0);
$signerName = trim((string)($_POST['signer_name'] ?? ''));
$signerEmail = trim((string)($_POST['signer_email'] ?? ''));
$signerCpf = trim((string)($_POST['signer_cpf'] ?? ''));

// Validações
if ($employeeId === 0 || $templateId === 0 || $signerName === '' || $signerEmail === '' || $signerCpf === '') {
    flash_set('error', 'Preencha todos os campos obrigatórios.');
    header('Location: /hr_contract_generate.php?employee_id=' . $employeeId);
    exit;
}

// Buscar funcionário
$stmt = db()->prepare('SELECT * FROM hr_employees WHERE id = :id');
$stmt->execute(['id' => $employeeId]);
$employee = $stmt->fetch();

if (!$employee) {
    flash_set('error', 'Funcionário não encontrado.');
    header('Location: /hr_dashboard.php');
    exit;
}

// Buscar template
$stmt = db()->prepare('SELECT * FROM zapsign_contract_templates WHERE id = :id');
$stmt->execute(['id' => $templateId]);
$template = $stmt->fetch();

if (!$template) {
    flash_set('error', 'Template não encontrado.');
    header('Location: /hr_contract_generate.php?employee_id=' . $employeeId);
    exit;
}

// Buscar configuração do ZapSign
$config = db()->query('SELECT * FROM zapsign_config LIMIT 1')->fetch();

if (!$config || empty($config['api_token'])) {
    flash_set('error', 'ZapSign não configurado. Configure em Configurações.');
    header('Location: /hr_contract_generate.php?employee_id=' . $employeeId);
    exit;
}

// Preparar dados para API do ZapSign
$apiToken = $config['api_token'];
$sandboxMode = (bool)$config['sandbox_mode'];
$baseUrl = $sandboxMode ? 'https://sandbox.api.zapsign.com.br' : 'https://api.zapsign.com.br';

try {
    // Criar documento no ZapSign
    $documentData = [
        'name' => $template['name'] . ' - ' . $employee['full_name'],
        'signers' => [
            [
                'name' => $signerName,
                'email' => $signerEmail,
                'cpf' => preg_replace('/[^0-9]/', '', $signerCpf),
                'send_automatic_email' => true,
                'send_automatic_whatsapp' => false
            ]
        ]
    ];
    
    // Se template tem token do ZapSign, usar template
    if (!empty($template['zapsign_template_token'])) {
        $documentData['template_token'] = $template['zapsign_template_token'];
    }
    // Se template tem PDF, fazer upload do PDF
    elseif (!empty($template['pdf_file_path'])) {
        $pdfPath = __DIR__ . $template['pdf_file_path'];
        if (!file_exists($pdfPath)) {
            throw new Exception('Arquivo PDF do template não encontrado.');
        }
        
        // Upload do PDF para ZapSign
        $ch = curl_init($baseUrl . '/api/v1/docs/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiToken,
            'Content-Type: multipart/form-data'
        ]);
        
        $postData = [
            'pdf_file' => new CURLFile($pdfPath, 'application/pdf', basename($pdfPath)),
            'name' => $documentData['name'],
            'signers' => json_encode($documentData['signers'])
        ];
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 201 && $httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMsg = $errorData['message'] ?? 'Erro ao enviar documento para ZapSign';
            throw new Exception($errorMsg);
        }
        
        $responseData = json_decode($response, true);
    } else {
        throw new Exception('Template não possui PDF nem token do ZapSign configurado.');
    }
    
    // Se usou template do ZapSign, criar documento via template
    if (!empty($template['zapsign_template_token'])) {
        $ch = curl_init($baseUrl . '/api/v1/templates/' . $template['zapsign_template_token'] . '/docs/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($documentData));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 201 && $httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMsg = $errorData['message'] ?? 'Erro ao criar documento no ZapSign';
            throw new Exception($errorMsg);
        }
        
        $responseData = json_decode($response, true);
    }
    
    // Salvar contrato no banco
    $docToken = $responseData['token'] ?? null;
    $expiresAt = isset($responseData['expires_at']) ? date('Y-m-d H:i:s', strtotime($responseData['expires_at'])) : null;
    
    $sql = 'INSERT INTO hr_employee_contracts (
        employee_id, template_id, contract_type, zapsign_doc_token, zapsign_status,
        sent_at, expires_at, signer_name, signer_email, signer_cpf, created_by_user_id
    ) VALUES (
        :employee_id, :template_id, :contract_type, :zapsign_doc_token, :zapsign_status,
        NOW(), :expires_at, :signer_name, :signer_email, :signer_cpf, :created_by_user_id
    )';
    
    $stmt = db()->prepare($sql);
    $stmt->execute([
        'employee_id' => $employeeId,
        'template_id' => $templateId,
        'contract_type' => $template['template_type'],
        'zapsign_doc_token' => $docToken,
        'zapsign_status' => 'pending',
        'expires_at' => $expiresAt,
        'signer_name' => $signerName,
        'signer_email' => $signerEmail,
        'signer_cpf' => $signerCpf,
        'created_by_user_id' => auth_user_id(),
    ]);
    
    // Registrar no histórico do funcionário
    $historyStmt = db()->prepare('INSERT INTO hr_employee_history (employee_id, change_type, change_date, description, created_by_user_id) VALUES (:employee_id, :change_type, NOW(), :description, :created_by_user_id)');
    $historyStmt->execute([
        'employee_id' => $employeeId,
        'change_type' => 'outro',
        'description' => 'Contrato enviado para assinatura digital via ZapSign: ' . $template['name'],
        'created_by_user_id' => auth_user_id(),
    ]);
    
    flash_set('success', 'Contrato enviado com sucesso! O funcionário receberá um e-mail com o link para assinar.');
    header('Location: /hr_contract_generate.php?employee_id=' . $employeeId);
    exit;
    
} catch (Exception $e) {
    // Salvar erro no banco
    $sql = 'INSERT INTO hr_employee_contracts (
        employee_id, template_id, contract_type, zapsign_status, error_message, created_by_user_id
    ) VALUES (
        :employee_id, :template_id, :contract_type, :zapsign_status, :error_message, :created_by_user_id
    )';
    
    $stmt = db()->prepare($sql);
    $stmt->execute([
        'employee_id' => $employeeId,
        'template_id' => $templateId,
        'contract_type' => $template['template_type'],
        'zapsign_status' => 'error',
        'error_message' => $e->getMessage(),
        'created_by_user_id' => auth_user_id(),
    ]);
    
    flash_set('error', 'Erro ao enviar contrato: ' . $e->getMessage());
    header('Location: /hr_contract_generate.php?employee_id=' . $employeeId);
    exit;
}
