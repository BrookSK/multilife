<?php

declare(strict_types=1);

/**
 * Criar notificação para um usuário
 */
function notification_create(int $userId, string $type, string $title, string $message, ?string $link = null): void
{
    $stmt = db()->prepare('
        INSERT INTO notifications (user_id, type, title, message, link)
        VALUES (:uid, :type, :title, :message, :link)
    ');
    
    $stmt->execute([
        'uid' => $userId,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'link' => $link
    ]);
}

/**
 * Criar notificação de pendência
 */
function notification_pendencia(int $userId, string $message, string $link): void
{
    notification_create($userId, 'pendencia', 'Nova Pendência', $message, $link);
}

/**
 * Criar notificação de captação atrasada
 */
function notification_captacao_atrasada(int $userId, int $demandId, string $title): void
{
    notification_create(
        $userId,
        'captacao_atrasada',
        'Captação Atrasada',
        'A demanda "' . $title . '" está atrasada',
        '/demands_view.php?id=' . $demandId
    );
}

/**
 * Criar notificação de novo e-mail
 */
function notification_email(int $userId, string $subject, int $emailId): void
{
    notification_create(
        $userId,
        'email',
        'Novo E-mail',
        'Assunto: ' . $subject,
        '/email_view.php?id=' . $emailId
    );
}

/**
 * Criar notificação de nova mensagem WhatsApp
 */
function notification_whatsapp(int $userId, string $from, string $preview, int $chatId): void
{
    notification_create(
        $userId,
        'whatsapp',
        'Nova Mensagem WhatsApp',
        'De: ' . $from . ' - ' . $preview,
        '/chat_view.php?id=' . $chatId
    );
}
