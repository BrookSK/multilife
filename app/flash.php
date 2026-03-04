<?php

declare(strict_types=1);

function flash_set(string $key, string $message): void
{
    $_SESSION['_flash'][$key] = $message;
}

function flash_get(string $key): string
{
    if (!isset($_SESSION['_flash'][$key])) {
        return '';
    }
    $msg = (string)$_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $msg;
}
