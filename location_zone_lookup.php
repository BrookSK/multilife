<?php

declare(strict_types=1);

/**
 * Consulta rápida: zona de um bairro (São Paulo/Rio). Usado pelo formulário do
 * card para sugerir a zona enquanto o captador digita o bairro.
 */

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (auth_user_id() === null) {
    echo json_encode(['ok' => false, 'error' => 'Sessão expirada.']);
    exit;
}

$city = (string)($_GET['city'] ?? '');
$state = (string)($_GET['state'] ?? '');
$neighborhood = (string)($_GET['neighborhood'] ?? '');

echo json_encode([
    'ok' => true,
    'has_zones' => location_city_has_zones($city, $state),
    'zones' => location_zone_options($city, $state),
    'zone' => location_zone_for($city, $state, $neighborhood),
], JSON_UNESCAPED_UNICODE);
