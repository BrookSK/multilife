<?php

declare(strict_types=1);

function auth_user_id(): ?int
{
    if (!isset($_SESSION['auth_user_id'])) {
        return null;
    }
    $id = (int)$_SESSION['auth_user_id'];
    return $id > 0 ? $id : null;
}

function auth_login(int $userId): void
{
    $_SESSION['auth_user_id'] = $userId;
}

function auth_logout(): void
{
    unset($_SESSION['auth_user_id']);
}

function auth_require_login(): void
{
    if (auth_user_id() === null) {
        header('Location: /login.php');
        exit;
    }
    
    // Verificar se o usuário está suspenso
    $userId = auth_user_id();
    $stmt = db()->prepare('SELECT is_suspended FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    
    if ($user && $user['is_suspended']) {
        auth_logout();
        flash_set('error', 'Sua conta foi suspensa. Entre em contato com o administrador.');
        header('Location: /login.php');
        exit;
    }
}

function auth_user(): ?array
{
    $id = auth_user_id();
    if ($id === null) {
        return null;
    }

    static $cache = [];
    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }
    $stmt = db()->prepare('SELECT id, name, email, status FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $cache[$id] = ($row ?: null);
}

/**
 * Libera o arquivo da sessão PHP para as outras requisições do mesmo usuário.
 *
 * O PHP trava a sessão do início ao fim de cada requisição. Uma chamada lenta em
 * segundo plano (ex.: busca de e-mails por IMAP ao abrir o kanban, polling do
 * chat) fazia TODAS as outras telas do usuário esperarem por ela — abrir um card
 * levava 10–15 s. Chame depois das checagens de login/permissão em endpoints que
 * só LEEM a sessão (não usam flash_set nem gravam em $_SESSION). Os dados já
 * lidos continuam disponíveis em $_SESSION.
 */
function session_release(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}
