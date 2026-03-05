<?php

declare(strict_types=1);

/**
 * Registra uma ação no histórico da página
 */
function page_history_log(
    string $pageUrl,
    string $pageTitle,
    string $actionType,
    string $actionDescription,
    ?string $entityType = null,
    ?int $entityId = null
): void {
    if (!isset($_SESSION['auth_user_id'])) {
        return; // Não registra se não houver usuário logado
    }
    
    $user = auth_user();
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    try {
        $stmt = db()->prepare(
            "INSERT INTO page_history (
                page_url, page_title, action_type, action_description,
                entity_type, entity_id, user_id, user_name, user_email,
                ip_address, user_agent, created_at
            ) VALUES (
                :page_url, :page_title, :action_type, :action_description,
                :entity_type, :entity_id, :user_id, :user_name, :user_email,
                :ip_address, :user_agent, NOW()
            )"
        );
        
        $stmt->execute([
            'page_url' => $pageUrl,
            'page_title' => $pageTitle,
            'action_type' => $actionType,
            'action_description' => $actionDescription,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => (int)$user['id'],
            'user_name' => (string)$user['name'],
            'user_email' => (string)$user['email'],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent
        ]);
    } catch (Exception $e) {
        // Silenciosamente falha para não quebrar a aplicação
        error_log('Erro ao registrar histórico de página: ' . $e->getMessage());
    }
}

/**
 * Busca histórico de uma página específica
 */
function page_history_get(string $pageUrl, int $page = 1, int $perPage = 30): array
{
    $offset = ($page - 1) * $perPage;
    
    $stmt = db()->prepare(
        "SELECT * FROM page_history 
        WHERE page_url = :page_url 
        ORDER BY created_at DESC 
        LIMIT :limit OFFSET :offset"
    );
    
    $stmt->bindValue(':page_url', $pageUrl, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll();
}

/**
 * Conta total de registros de histórico de uma página
 */
function page_history_count(string $pageUrl): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) as total FROM page_history WHERE page_url = :page_url"
    );
    $stmt->execute(['page_url' => $pageUrl]);
    $result = $stmt->fetch();
    
    return (int)($result['total'] ?? 0);
}

/**
 * Obtém iniciais do nome do usuário para avatar
 */
function get_user_initials(string $name): string
{
    $parts = explode(' ', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}
