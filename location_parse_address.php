<?php

declare(strict_types=1);

/**
 * Separa um endereço colado em uma linha (rua, número, complemento, bairro,
 * cidade, UF, CEP) para preencher o formulário do card. Não grava nada.
 */

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (auth_user_id() === null) {
    echo json_encode(['ok' => false, 'error' => 'Sessão expirada.']);
    exit;
}
session_release();

$text = mb_substr(trim((string)($_POST['text'] ?? '')), 0, 500);
if ($text === '') {
    echo json_encode(['ok' => false, 'error' => 'Informe o endereço.']);
    exit;
}

$fields = location_parse_address($text);
$zone = location_zone_for($fields['location_city'], $fields['location_state'], $fields['location_neighborhood']);
if ($zone !== null) {
    $fields['location_zone'] = $zone;
}

echo json_encode(['ok' => true, 'fields' => $fields], JSON_UNESCAPED_UNICODE);
