<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json');
auth_require_login();

$email = trim((string)($_GET['email'] ?? ''));

if (empty($email) || strpos($email, '@') === false) {
    echo json_encode(['success' => false, 'error' => 'E-mail inválido']);
    exit;
}

// Extrair domínio do e-mail
$domain = strtolower(substr($email, strpos($email, '@') + 1));

try {
    // 1. Buscar por domínio cadastrado
    $stmt = db()->prepare("
        SELECT id, name, email, email_domain, contact_email
        FROM health_insurers 
        WHERE status = 'active' AND email_domain = ?
        LIMIT 1
    ");
    $stmt->execute([$domain]);
    $insurer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. Fallback: tentar pelo nome (domínio pode ser parte do nome)
    if (!$insurer) {
        // Extrair nome base do domínio (ex: unimed.com.br → unimed)
        $domainBase = explode('.', $domain)[0];
        if (strlen($domainBase) >= 3) {
            $stmt2 = db()->prepare("
                SELECT id, name, email, email_domain, contact_email
                FROM health_insurers 
                WHERE status = 'active' AND LOWER(name) LIKE ?
                LIMIT 1
            ");
            $stmt2->execute(['%' . $domainBase . '%']);
            $insurer = $stmt2->fetch(PDO::FETCH_ASSOC);
        }
    }
    
    if ($insurer) {
        echo json_encode([
            'success' => true,
            'found' => true,
            'insurer_id' => (int)$insurer['id'],
            'insurer_name' => $insurer['name'],
            'insurer_email' => $insurer['email'] ?? '',
            'domain' => $domain,
        ]);
    } else {
        // Não encontrou — retornar o domínio como sugestão de nome
        $suggestedName = ucfirst($domainBase ?? $domain);
        echo json_encode([
            'success' => true,
            'found' => false,
            'domain' => $domain,
            'suggested_name' => $suggestedName,
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
