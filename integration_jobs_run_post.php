<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('integration_jobs.manage');

$id = (int)($_POST['id'] ?? 0);

$db = db();

$stmt = $db->prepare('SELECT * FROM integration_jobs WHERE id = :id');
$stmt->execute(['id' => $id]);
$j = $stmt->fetch();

if (!$j) {
    flash_set('error', 'Job não encontrado.');
    header('Location: /integration_jobs_list.php');
    exit;
}

$attempts = (int)$j['attempts'];
$max = (int)$j['max_attempts'];
if ($attempts >= $max) {
    $stmt = $db->prepare("UPDATE integration_jobs SET status = 'dead', last_error = 'max attempts atingido', updated_at = NOW() WHERE id = :id");
    $stmt->execute(['id' => $id]);

    integration_log((string)$j['provider'], (string)$j['action'], 'error', null, $j['payload'], null, 'max attempts atingido', $attempts);

    flash_set('error', 'Job marcado como dead (max attempts).');
    header('Location: /integration_jobs_view.php?id=' . $id);
    exit;
}

$db->beginTransaction();
try {
    $stmt = $db->prepare("UPDATE integration_jobs SET status = 'running', last_run_at = NOW(), attempts = attempts + 1 WHERE id = :id");
    $stmt->execute(['id' => $id]);

    // Execução stub: ainda não chama APIs externas.
    // Regra do documento: registrar payload e falhas; retentar até 3 vezes.
    $provider = (string)$j['provider'];
    $action = (string)$j['action'];

    // Critério simples: se payload contém "force_error": true, falha.
    $payloadArr = null;
    if (!empty($j['payload'])) {
        $payloadArr = json_decode((string)$j['payload'], true);
    }

    $shouldFail = is_array($payloadArr) && !empty($payloadArr['force_error']);

    if ($shouldFail) {
        $err = 'Falha simulada (force_error).';
        $stmt = $db->prepare("UPDATE integration_jobs SET status = 'error', last_error = :e, next_run_at = DATE_ADD(NOW(), INTERVAL POW(2, LEAST(attempts, 3)) MINUTE) WHERE id = :id");
        $stmt->execute(['e' => $err, 'id' => $id]);

        integration_log($provider, $action, 'error', null, $payloadArr, null, $err, (int)$j['attempts'] + 1);

        $db->commit();

        flash_set('error', 'Job executado com erro (simulado).');
        header('Location: /integration_jobs_view.php?id=' . $id);
        exit;
    }

    $stmt = $db->prepare("UPDATE integration_jobs SET status = 'success', last_error = NULL, next_run_at = NULL WHERE id = :id");
    $stmt->execute(['id' => $id]);

    integration_log($provider, $action, 'success', 200, $payloadArr, ['result' => 'ok'], null, (int)$j['attempts'] + 1);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Job executado com sucesso (stub).');
header('Location: /integration_jobs_view.php?id=' . $id);
exit;
