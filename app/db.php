<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/../config/config.php';
    $db = $config['db'];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'],
        (int)$db['port'],
        $db['name'],
        $db['charset']
    );

    try {
        $pdo = new PDO(
            $dsn,
            $db['user'],
            $db['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Erro ao conectar no banco de dados.\n\n";
        echo "Verifique o arquivo config/config.php ou configure variáveis de ambiente:\n";
        echo "- DB_HOST\n";
        echo "- DB_PORT\n";
        echo "- DB_NAME\n";
        echo "- DB_USER\n";
        echo "- DB_PASS\n\n";
        echo "Detalhe técnico (sem credenciais): " . $e->getMessage() . "\n";
        exit;
    }

    return $pdo;
}
