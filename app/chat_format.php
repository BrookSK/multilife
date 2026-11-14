<?php

declare(strict_types=1);

/**
 * Exibição de mensagens do chat: formatação do WhatsApp e download de mídia.
 * A versão JS equivalente está em chat_web_functions.js (chatFormatWa).
 */

/**
 * Converte a formatação do WhatsApp em HTML seguro:
 * *negrito*, _itálico_, ~tachado~, `código`, ```bloco```, "> citação" e quebras de linha.
 * O texto é escapado ANTES; só as tags geradas aqui entram no HTML.
 *
 * @param string[] $mentionNumbers números mencionados (@5511...) para destacar
 */
function chat_wa_format(string $text, array $mentionNumbers = []): string
{
    $s = htmlspecialchars(str_replace("\r\n", "\n", $text), ENT_QUOTES, 'UTF-8');

    // Código primeiro (o conteúdo não recebe outras formatações)
    $codes = [];
    $keep = static function (string $html) use (&$codes): string {
        $codes[] = $html;
        return "\x01" . (count($codes) - 1) . "\x02";
    };
    $s = (string)preg_replace_callback('/```([\s\S]+?)```/u', static fn($m) => $keep('<code class="wa-code-block">' . $m[1] . '</code>'), $s);
    $s = (string)preg_replace_callback('/`([^`\n]+)`/u', static fn($m) => $keep('<code class="wa-code">' . $m[1] . '</code>'), $s);

    $marks = ['\*' => 'strong', '_' => 'em', '~' => 's'];
    foreach ($marks as $mk => $tag) {
        $s = (string)preg_replace(
            '/(^|[^\p{L}\p{N}' . $mk . '])' . $mk . '(?=\S)([^' . $mk . '\n]*?\S)' . $mk . '(?![\p{L}\p{N}' . $mk . '])/u',
            '$1<' . $tag . '>$2</' . $tag . '>',
            $s
        );
    }

    foreach ($mentionNumbers as $num) {
        $num = preg_replace('/\D+/', '', (string)$num);
        if ($num !== '') {
            $s = str_replace('@' . $num, '<span class="wa-mention">@' . $num . '</span>', $s);
        }
    }

    // Citação: linhas que começam com "> "
    $s = (string)preg_replace('/^&gt;\s?(.*)$/mu', '<span class="wa-quote">$1</span>', $s);

    $s = (string)preg_replace_callback("/\x01(\\d+)\x02/", static fn($m) => $codes[(int)$m[1]] ?? '', $s);
    return nl2br($s, false);
}

/** Link "Baixar" (pelo servidor, força o download com o nome do arquivo). */
function chat_media_download_link(int $messageId, string $label = 'Baixar'): string
{
    if ($messageId <= 0) {
        return '';
    }
    return '<a class="wa-download" href="/chat_media_download.php?id=' . $messageId . '" title="Baixar arquivo no computador">'
        . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
}
