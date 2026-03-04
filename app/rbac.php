<?php

declare(strict_types=1);

function rbac_user_roles(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT r.slug FROM roles r INNER JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :uid'
    );
    $stmt->execute(['uid' => $userId]);
    $roles = [];
    foreach ($stmt->fetchAll() as $row) {
        $roles[] = (string)$row['slug'];
    }
    return $roles;
}

function rbac_user_has_role(int $userId, string $roleSlug): bool
{
    foreach (rbac_user_roles($userId) as $slug) {
        if ($slug === $roleSlug) {
            return true;
        }
    }
    return false;
}

function rbac_user_permissions(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT DISTINCT p.slug
         FROM permissions p
         INNER JOIN role_permissions rp ON rp.permission_id = p.id
         INNER JOIN user_roles ur ON ur.role_id = rp.role_id
         WHERE ur.user_id = :uid'
    );
    $stmt->execute(['uid' => $userId]);

    $perms = [];
    foreach ($stmt->fetchAll() as $row) {
        $perms[] = (string)$row['slug'];
    }
    return $perms;
}

function rbac_user_can(int $userId, string $permissionSlug): bool
{
    foreach (rbac_user_permissions($userId) as $slug) {
        if ($slug === $permissionSlug) {
            return true;
        }
    }
    return false;
}

function rbac_require_permission(string $permissionSlug): void
{
    $uid = auth_user_id();
    if ($uid === null) {
        header('Location: /login.php');
        exit;
    }

    if (!rbac_user_can($uid, $permissionSlug)) {
        header('Location: /forbidden.php');
        exit;
    }
}
