<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('roles.manage');

$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$slug = trim((string)($_POST['slug'] ?? ''));

$stmt = db()->prepare('SELECT id, name, slug FROM roles WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Perfil não encontrado.');
    header('Location: /roles_list.php');
    exit;
}

if ($name === '' || $slug === '') {
    flash_set('error', 'Preencha nome e slug.');
    header('Location: /roles_edit.php?id=' . $id);
    exit;
}

if (!preg_match('/^[a-z0-9_\-\.]{2,80}$/', $slug)) {
    flash_set('error', 'Slug inválido. Use letras minúsculas, números, _, -, .');
    header('Location: /roles_edit.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('SELECT id FROM roles WHERE slug = :slug AND id <> :id LIMIT 1');
$stmt->execute(['slug' => $slug, 'id' => $id]);
if ($stmt->fetch()) {
    flash_set('error', 'Já existe outro perfil com esse slug.');
    header('Location: /roles_edit.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('UPDATE roles SET name = :name, slug = :slug WHERE id = :id');
$stmt->execute(['name' => $name, 'slug' => $slug, 'id' => $id]);

audit_log('update', 'roles', (string)$id, $old, ['name' => $name, 'slug' => $slug]);

flash_set('success', 'Perfil atualizado.');
header('Location: /roles_list.php');
exit;
