<?php

declare(strict_types=1);

function rbac_user_roles(int $userId): array
{
    // Cache por requisição: menu, permissões e widgets consultavam isto várias vezes por página.
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    $stmt = db()->prepare(
        'SELECT r.slug FROM roles r INNER JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :uid'
    );
    $stmt->execute(['uid' => $userId]);
    $roles = [];
    foreach ($stmt->fetchAll() as $row) {
        $roles[] = (string)$row['slug'];
    }
    return $cache[$userId] = $roles;
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
    // Cache por requisição (antes: 1 consulta a cada rbac_user_can/menu/widget).
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
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
    return $cache[$userId] = $perms;
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
        // Se é profissional tentando acessar área admin, redirecionar para área do profissional
        $stmtRole = db()->prepare("
            SELECT r.slug FROM user_roles ur 
            INNER JOIN roles r ON r.id = ur.role_id 
            WHERE ur.user_id = ?
        ");
        $stmtRole->execute([$uid]);
        $roles = $stmtRole->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('profissional', $roles, true)) {
            header('Location: /profissional_registros.php');
            exit;
        }
        
        header('Location: /forbidden.php');
        exit;
    }
}

/**
 * Exige QUALQUER uma das permissões informadas (OR).
 * Usado em telas de cadastro que o admin (admin.settings.manage) e o captador
 * (cadastros.manage) podem acessar.
 *
 * @param string[] $permissionSlugs
 */
function rbac_require_any_permission(array $permissionSlugs): void
{
    $uid = auth_user_id();
    if ($uid === null) {
        header('Location: /login.php');
        exit;
    }
    $perms = rbac_user_permissions($uid);
    foreach ($permissionSlugs as $slug) {
        if (in_array($slug, $perms, true)) {
            return;
        }
    }
    // Reaproveita o redirecionamento padrão (profissional/forbidden).
    rbac_require_permission((string)($permissionSlugs[0] ?? '__none__'));
}

/**
 * Perfis que um usuário SEM 'roles.manage' (ex.: captador com users.manage)
 * pode atribuir/gerenciar. Evita escalonamento de privilégio: o captador
 * cadastra profissionais e pacientes, mas não cria/edita administradores.
 */
const RBAC_LIMITED_ASSIGNABLE_ROLES = ['profissional', 'paciente'];

function rbac_actor_has_full_user_admin(): bool
{
    $uid = auth_user_id();
    return $uid !== null && rbac_user_can($uid, 'roles.manage');
}

/**
 * Indica se o usuário logado pode gerenciar (editar/excluir/alterar perfis) o
 * usuário alvo. Sem 'roles.manage', só pode gerenciar usuários cujos perfis
 * estejam todos em RBAC_LIMITED_ASSIGNABLE_ROLES (ou sem perfil).
 */
function rbac_can_manage_target_user(int $targetUserId): bool
{
    if (rbac_actor_has_full_user_admin()) {
        return true;
    }
    $stmt = db()->prepare('SELECT r.slug FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?');
    $stmt->execute([$targetUserId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $slug) {
        if (!in_array((string)$slug, RBAC_LIMITED_ASSIGNABLE_ROLES, true)) {
            return false;
        }
    }
    return true;
}

/**
 * Bloqueia (flash + redirect) se o usuário logado não puder gerenciar o alvo.
 */
function rbac_require_manage_target_user(int $targetUserId, string $redirect = '/users_list.php'): void
{
    if (!rbac_can_manage_target_user($targetUserId)) {
        flash_set('error', 'Você só pode gerenciar usuários com perfil Profissional ou Paciente.');
        header('Location: ' . $redirect);
        exit;
    }
}

/**
 * Indica se o usuário logado pode atribuir o perfil (slug) informado.
 */
function rbac_can_assign_role(string $roleSlug): bool
{
    return rbac_actor_has_full_user_admin() || in_array($roleSlug, RBAC_LIMITED_ASSIGNABLE_ROLES, true);
}
