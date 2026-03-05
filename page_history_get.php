<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

header('Content-Type: application/json');

$pageUrl = trim($_GET['page_url'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;

if (empty($pageUrl)) {
    echo json_encode(['error' => 'page_url é obrigatório']);
    exit;
}

$items = page_history_get($pageUrl, $page, $perPage);
$total = page_history_count($pageUrl);
$totalPages = (int)ceil($total / $perPage);

// Adicionar iniciais para cada item
foreach ($items as &$item) {
    $item['initials'] = get_user_initials($item['user_name']);
}

echo json_encode([
    'items' => $items,
    'current_page' => $page,
    'total_pages' => $totalPages,
    'total_items' => $total,
    'per_page' => $perPage
]);
