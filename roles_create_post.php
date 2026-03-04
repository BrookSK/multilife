<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('roles.manage');

$name = trim((string)($_POST['name'] ?? ''));
$slug = trim((string)($_POST['slug'] ?? ''));

if ($name === '' || $slug === '') {
    flash_set('error', 'Preencha nome e slug.');
    header('Location: /roles_create.php');
    exit;
}

if (!preg_match('/^[a-z0-9_\-\.]{2,80}$/', $slug)) {
    flash_set('error', 'Slug inválido. Use letras minúsculas, números, _, -, .');
    header('Location: /roles_create.php');
    exit;
}

$stmt = db()->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
$stmt->execute(['slug' => $slug]);
if ($stmt->fetch()) {
    flash_set('error', 'Já existe um perfil com esse slug.');
    header('Location: /roles_create.php');
    exit;
}

$stmt = db()->prepare('INSERT INTO roles (name, slug) VALUES (:name, :slug)');
$stmt->execute(['name' => $name, 'slug' => $slug]);
$id = (string)db()->lastInsertId();
audit_log('create', 'roles', $id, null, ['name' => $name, 'slug' => $slug]);

flash_set('success', 'Perfil criado.');
header('Location: /roles_list.php');
exit;
