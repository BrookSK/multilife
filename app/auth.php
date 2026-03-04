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
}

function auth_user(): ?array
{
    $id = auth_user_id();
    if ($id === null) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, name, email, status FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}
