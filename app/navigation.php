<?php

declare(strict_types=1);

/**
 * Retorna a URL da página anterior ou uma URL padrão
 */
function get_back_url(string $default = '/dashboard.php'): string
{
    // Verificar se há referer
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    
    if (empty($referer)) {
        return $default;
    }
    
    // Parse do referer
    $refererParsed = parse_url($referer);
    $currentHost = $_SERVER['HTTP_HOST'] ?? '';
    
    // Verificar se é do mesmo domínio
    if (isset($refererParsed['host']) && $refererParsed['host'] !== $currentHost) {
        return $default;
    }
    
    // Pegar apenas o path e query
    $backUrl = $refererParsed['path'] ?? '/';
    if (isset($refererParsed['query'])) {
        $backUrl .= '?' . $refererParsed['query'];
    }
    
    // Evitar loops (não voltar para a mesma página)
    $currentPath = $_SERVER['REQUEST_URI'] ?? '';
    if ($backUrl === $currentPath) {
        return $default;
    }
    
    // Evitar voltar para páginas de POST
    $postPages = [
        'login_post.php',
        'logout.php',
        '_post.php', // Qualquer página que termine com _post.php
        '_delete.php',
    ];
    
    foreach ($postPages as $pattern) {
        if (strpos($backUrl, $pattern) !== false) {
            return $default;
        }
    }
    
    return $backUrl;
}

/**
 * Renderiza botão "Voltar" inteligente
 */
function render_back_button(string $default = '/dashboard.php', string $class = 'btn'): void
{
    $backUrl = get_back_url($default);
    echo '<a href="' . h($backUrl) . '" class="' . h($class) . '">Voltar</a>';
}
