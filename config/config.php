<?php

declare(strict_types=1);

$env = static function (string $key, ?string $default = null): ?string {
    $v = getenv($key);
    if ($v === false) {
        return $default;
    }
    $v = trim((string)$v);
    return $v === '' ? $default : $v;
};

$envInt = static function (string $key, int $default): int {
    $v = getenv($key);
    if ($v === false) {
        return $default;
    }
    $v = trim((string)$v);
    if ($v === '' || !ctype_digit($v)) {
        return $default;
    }
    return (int)$v;
};

/*
 * AMBIENTE (define qual banco usar). Regra: branch "main" = PRODUÇÃO; qualquer
 * outra branch = BETA; LOCAL é detectado pelo host e tem prioridade.
 * Ordem:
 *   0) Variável de ambiente APP_ENV = local | beta | production (força o ambiente,
 *      útil em scripts de linha de comando).
 *   1) Host local: localhost, 127.0.0.1, ::1 ou terminado em .test / .local.
 *   2) Domínio: host com "beta" ou "plesk.page" = beta.
 *   3) Branch atual lida em <raiz>/.git/HEAD (só leitura do arquivo, sem Git).
 * Na dúvida, PRODUÇÃO. As variáveis DB_* continuam valendo por cima de qualquer
 * ambiente (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS).
 * Sem funções nomeadas: este arquivo é carregado mais de uma vez por requisição.
 */
$appEnvironment = (static function (): string {
    $forced = strtolower(trim((string)(getenv('APP_ENV') ?: '')));
    if (in_array($forced, ['local', 'beta', 'production'], true)) {
        return $forced;
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    // Sem a porta: "[::1]:8080" -> "::1", "localhost:8000" -> "localhost"
    $hostName = str_starts_with($host, '[')
        ? substr($host, 1, max(0, (int)strpos($host, ']') - 1))
        : (preg_replace('/:\d+$/', '', $host) ?? '');
    if (in_array($hostName, ['localhost', '127.0.0.1', '::1'], true)
        || str_ends_with($hostName, '.test')
        || str_ends_with($hostName, '.local')) {
        return 'local';
    }
    if ($hostName !== '' && (str_contains($hostName, 'beta') || str_contains($hostName, 'plesk.page'))) {
        return 'beta';
    }

    $headFile = dirname(__DIR__) . '/.git/HEAD';
    if (is_file($headFile) && is_readable($headFile)) {
        $head = trim((string)@file_get_contents($headFile));
        if (str_starts_with($head, 'ref: refs/heads/')) {
            $branch = substr($head, strlen('ref: refs/heads/'));
            return $branch === 'main' ? 'production' : 'beta';
        }
    }
    return 'production';
})();

// Banco de cada ambiente. PRODUÇÃO é o mesmo de antes (não mudou nada).
$dbByEnvironment = [
    'production' => [
        'host' => 'localhost',
        'name' => 'multilife',
        'user' => 'multilife',
        'pass' => '&Xqn4Qiiv&a3Jh8m',
    ],
    'beta' => [
        'host' => 'localhost',
        'name' => 'multilife_beta',
        'user' => 'multilife_beta',
        'pass' => '&Xqn4Qiiv&a3Jh8m',
    ],
    'local' => [
        'host' => '127.0.0.1',
        'name' => 'multilife',
        'user' => 'root',
        'pass' => '',
    ],
];
$dbDefaults = $dbByEnvironment[$appEnvironment];

return [
    'env' => $appEnvironment,
    'db' => [
        'host' => $env('DB_HOST', $dbDefaults['host']),
        'port' => $envInt('DB_PORT', 3306),
        'name' => $env('DB_NAME', $dbDefaults['name']),
        'user' => $env('DB_USER', $dbDefaults['user']),
        // Local costuma ter senha vazia: getenv vazio volta ao padrão do ambiente.
        'pass' => $env('DB_PASS', $dbDefaults['pass']) ?? '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'base_url' => $env('APP_BASE_URL', ''),
        'session_name' => $env('APP_SESSION_NAME', 'multilife_session') ?? 'multilife_session',
        // Login dura 30 dias sem uso (mínimo garantido em app/bootstrap.php)
        'session_lifetime_seconds' => $envInt('APP_SESSION_LIFETIME_SECONDS', 30 * 24 * 60 * 60),
        // Chave da criptografia de dados sensíveis (ex.: senha do sistema externo do
        // profissional). Fica versionada junto com o código para NUNCA se perder.
        // NÃO altere este valor: as senhas já gravadas deixariam de abrir.
        'secret_key' => $env('MULTILIFE_SECRET_KEY', '6iw/4e1+IzJbaHl0mvvBDL6J7FjWfT0+S+fq8nb0s/0='),
    ],
];
