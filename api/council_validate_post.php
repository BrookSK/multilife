<?php

declare(strict_types=1);

/**
 * API endpoint: Validação de registro profissional via provedores de API.
 *
 * POST /api/council_validate_post.php
 * Body (JSON ou form): { application_id, council_abbr, registry_number, council_state }
 *
 * Utiliza sistema de fallback entre provedores:
 *  1. Consultar.IO (se configurado)
 *  2. Infosimples (se configurado)
 *  3. Portal Direto (scraping — fallback final)
 *
 * Retorna JSON com o resultado padronizado da validação.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/council_validator.php';

header('Content-Type: application/json; charset=utf-8');

// Autenticação obrigatória
if (!auth_user_id()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado.']);
    exit;
}

rbac_require_permission('professional_applications.manage');

// Aceita JSON ou form-data
$raw = file_get_contents('php://input');
$input = [];
if ($raw !== '' && $raw !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}
if (empty($input)) {
    $input = $_POST;
}

$applicationId  = isset($input['application_id']) ? (int)$input['application_id'] : 0;
$councilAbbr    = strtoupper(trim((string)($input['council_abbr'] ?? '')));
$registryNumber = trim((string)($input['registry_number'] ?? ''));
$councilState   = strtoupper(trim((string)($input['council_state'] ?? '')));

// Validações básicas
if ($councilAbbr === '' || $registryNumber === '' || $councilState === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Parâmetros obrigatórios: council_abbr, registry_number, council_state.',
    ]);
    exit;
}

$supportedCouncils = ['CRP', 'CRN', 'COREN', 'CREFITO', 'CRM', 'CRO', 'CREA', 'OAB'];
if (!in_array($councilAbbr, $supportedCouncils, true)) {
    http_response_code(400);
    echo json_encode([
        'success'   => false,
        'error'     => "Conselho '$councilAbbr' não é suportado. Suportados: " . implode(', ', $supportedCouncils),
        'supported' => $supportedCouncils,
    ]);
    exit;
}

// Executa validação — botão sempre força revalidação (ignora cache de erro)
$result = council_validate($councilAbbr, $registryNumber, $councilState, true);

// Persiste resultado na candidatura (se application_id fornecido)
if ($applicationId > 0) {
    try {
        $validationStatus = 'PENDING';
        if ($result['success'] && $result['valid']) {
            $validationStatus = 'VALID';
        } elseif ($result['success'] && !$result['valid']) {
            $validationStatus = 'INVALID';
        } elseif (!$result['success']) {
            $validationStatus = 'ERROR';
        }

        $stmt = db()->prepare(
            'UPDATE professional_applications
             SET council_validation_result = :json,
                 council_validated_at      = NOW(),
                 council_validation_status = :vstatus
             WHERE id = :id'
        );
        $stmt->execute([
            'json'    => json_encode($result, JSON_UNESCAPED_UNICODE),
            'vstatus' => $validationStatus,
            'id'      => $applicationId,
        ]);

        // Atualiza log com o ID da candidatura
        $stmt2 = db()->prepare(
            'UPDATE council_validation_logs
             SET triggered_by_user_id = :uid, triggered_by_application_id = :aid
             WHERE council_abbr = :abbr AND registry_number = :num AND council_state = :uf
             ORDER BY id DESC LIMIT 1'
        );
        $stmt2->execute([
            'uid'  => auth_user_id(),
            'aid'  => $applicationId,
            'abbr' => $councilAbbr,
            'num'  => $registryNumber,
            'uf'   => $councilState,
        ]);

        audit_log(
            'council_validate',
            'professional_applications',
            (string)$applicationId,
            null,
            ['council' => $councilAbbr, 'number' => $registryNumber, 'uf' => $councilState, 'result' => $validationStatus]
        );
    } catch (Throwable $e) {
        error_log('council_validate_post: erro ao persistir resultado: ' . $e->getMessage());
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
