<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('permissions.manage');

$name = trim((string)($_POST['name'] ?? ''));
$slug = trim((string)($_POST['slug'] ?? ''));

if ($name === '' || $slug === '') {
    flash_set('error', 'Preencha nome e slug.');
    header('Location: /permissions_create.php');
    exit;
}

if (!preg_match('/^[a-z0-9_\-\.]{2,120}$/', $slug)) {
    flash_set('error', 'Slug inválido. Use letras minúsculas, números, _, -, .');
    header('Location: /permissions_create.php');
    exit;
}

$stmt = db()->prepare('SELECT id FROM permissions WHERE slug = :slug LIMIT 1');
$stmt->execute(['slug' => $slug]);
if ($stmt->fetch()) {
    flash_set('error', 'Já existe uma permissão com esse slug.');
    header('Location: /permissions_create.php');
    exit;
}

$stmt = db()->prepare('INSERT INTO permissions (name, slug) VALUES (:name, :slug)');
$stmt->execute(['name' => $name, 'slug' => $slug]);
$id = (string)db()->lastInsertId();
audit_log('create', 'permissions', $id, null, ['name' => $name, 'slug' => $slug]);

flash_set('success', 'Permissão criada.');
header('Location: /permissions_list.php');
exit;
