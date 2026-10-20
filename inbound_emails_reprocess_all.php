<?php

declare(strict_types=1);

/**
 * Reprocessa EM LOTE todos os e-mails que estão com status 'error'
 * (e os presos em 'processing' por falha/timeout de execução anterior).
 *
 * Reseta o status para 'received' e dispara o CRON de extração com retry_errors=1.
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

// Resetar para 'received' os que estão em erro ou travados em processing.
// Só os que têm corpo (sem body não há o que reprocessar).
$upd = db()->prepare(
    "UPDATE inbound_emails
     SET status = 'received', error_message = NULL, processed_at = NULL
     WHERE status IN ('error', 'processing')
       AND (
            (body_text IS NOT NULL AND body_text <> '')
         OR (body_html IS NOT NULL AND body_html <> '')
       )"
);
$upd->execute();
$resetCount = $upd->rowCount();

if ($resetCount === 0) {
    flash_set('success', 'Nenhum e-mail com erro para reprocessar.');
    header('Location: /inbound_emails_list.php?status=error');
    exit;
}

$cronToken = trim((string)admin_setting_get('cron.token', ''));

if ($cronToken === '') {
    flash_set('success', $resetCount . ' e-mail(s) com erro foram marcados como "received" e serão processados no próximo ciclo do cron. (Dica: configure o token do CRON para reprocessar na hora.)');
    header('Location: /inbound_emails_list.php');
    exit;
}

// Disparar o cron em lote (processa vários de uma vez). retry_errors=1 inclui
// qualquer e-mail em 'error' remanescente; limit alto para pegar o lote todo.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
// chain=1: o cron se auto-encadeia em background, processando em lotes até
// esvaziar a fila — sem travar a tela e sem precisar clicar de novo.
$url = $scheme . '://' . $host . '/cron/openai_extract_email_to_demand.php?'
    . http_build_query([
        'token' => $cronToken,
        'retry_errors' => '1',
        'limit' => 15,
        'chain' => '1',
        'chain_depth' => 0,
    ]);

// Disparo em BACKGROUND (fire-and-forget): não esperamos o cron terminar, apenas
// "cutucamos" para ele começar. O cron processa em lotes e, a cada execução, fecha
// a conexão com fastcgi_finish_request() e segue em background. Isso evita travar a
// tela mesmo com dezenas de milhares de e-mails na fila.
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    // Timeout curtíssimo: só dispara e não aguarda o processamento.
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 800);
    curl_setopt($ch, CURLOPT_NOSIGNAL, true);
    curl_exec($ch);
    curl_close($ch);
}

flash_set('success', $resetCount . ' e-mail(s) com erro foram enfileirados e estão sendo reprocessados em segundo plano. Você pode continuar usando o sistema; recarregue a página daqui a pouco para ver o progresso. Como são muitos, o processamento ocorre em lotes e leva alguns minutos.');

header('Location: /inbound_emails_list.php');
exit;
