<?php

declare(strict_types=1);

/**
 * Escolhe qual banco esta instalação usa (Configurações → Ambiente / Banco).
 *
 * Grava storage/db_environment.json, lido por config/config.php antes da detecção
 * automática. "Automático" apaga o arquivo (volta ao comportamento de sempre).
 * Antes de trocar, testa a conexão com o banco escolhido; depois desloga, porque
 * os usuários (e os ids) de um banco não são os do outro.
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: /admin_settings.php');
    exit;
}

$choice = (string)($_POST['db_environment'] ?? '');
if (!in_array($choice, ['auto', 'beta', 'production'], true)) {
    flash_set('error', 'Escolha inválida.');
    header('Location: /admin_settings.php');
    exit;
}

$current = require __DIR__ . '/config/config.php';
if (($current['env_source'] ?? '') === 'variavel') {
    flash_set('error', 'O banco está fixado pela variável de ambiente APP_ENV do servidor: a escolha pela tela não vale enquanto ela existir.');
    header('Location: /admin_settings.php');
    exit;
}

// Banco que vai valer depois da troca (mesma regra do config.php)
$target = $choice === 'auto' ? (string)($current['env_auto'] ?? 'production') : $choice;
$labels = ['beta' => 'BETA', 'production' => 'PRODUÇÃO', 'local' => 'LOCAL'];

// Credenciais do banco escolhido: o config.php com APP_ENV forçado só nesta leitura
$prevEnv = getenv('APP_ENV');
putenv('APP_ENV=' . $target);
$targetCfg = require __DIR__ . '/config/config.php';
putenv($prevEnv === false ? 'APP_ENV' : 'APP_ENV=' . $prevEnv);
$tdb = $targetCfg['db'];

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $tdb['host'], (int)$tdb['port'], $tdb['name'], $tdb['charset']),
        $tdb['user'],
        $tdb['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
    $hasUsers = (bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    $pdo = null;
} catch (Throwable $e) {
    flash_set('error', 'Não troquei: o banco ' . $labels[$target] . ' (' . $tdb['name'] . ') não abriu: ' . $e->getMessage());
    header('Location: /admin_settings.php');
    exit;
}
if (!$hasUsers) {
    flash_set('error', 'Não troquei: o banco ' . $labels[$target] . ' (' . $tdb['name'] . ') abriu, mas não tem as tabelas do sistema.');
    header('Location: /admin_settings.php');
    exit;
}

$dir = __DIR__ . '/storage';
$file = $dir . '/db_environment.json';
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
if ($choice === 'auto') {
    $ok = !is_file($file) || @unlink($file);
} else {
    $ok = @file_put_contents($file, json_encode([
        'env' => $choice,
        'updated_at' => date('c'),
        'updated_by_user_id' => auth_user_id(),
        'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}
if (!$ok) {
    flash_set('error', 'Não consegui gravar a escolha em storage/db_environment.json: a pasta storage precisa ter permissão de escrita.');
    header('Location: /admin_settings.php');
    exit;
}

// Registro no banco que estava em uso (antes da troca)
try {
    audit_log('update', 'db_environment', null, ['env' => $current['env'] ?? null, 'source' => $current['env_source'] ?? null], ['choice' => $choice, 'env' => $target]);
} catch (Throwable $e) {
}

// Usuário logado pertence ao banco antigo: entra de novo no banco novo
auth_logout();
flash_set('success', 'Banco trocado para ' . $labels[$target] . ' (' . $tdb['name'] . ')'
    . ($choice === 'auto' ? ', pela detecção automática' : '')
    . '. Entre de novo com um usuário desse banco. O serviço WhatsApp passa a usar o mesmo banco em até 1 minuto.');
header('Location: /login.php');
exit;
