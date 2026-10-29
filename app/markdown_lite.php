<?php

declare(strict_types=1);

/**
 * Conversor simples de Markdown para HTML, usado para exibir a documentação
 * interna (docs/*.md) dentro do sistema. Suporta: títulos, parágrafos, listas
 * (com e sem número, inclusive aninhadas por recuo), tabelas, blocos de código,
 * citações, linha horizontal, **negrito**, *itálico*, `código` e [links](url).
 * Todo o texto é escapado antes da formatação (sem HTML cru do arquivo).
 */

function markdown_lite_inline(string $text): string
{
    $codes = [];
    $text = preg_replace_callback('/`([^`]+)`/', static function ($m) use (&$codes) {
        $codes[] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
        return "\x00" . (count($codes) - 1) . "\x00";
    }, $text) ?? $text;

    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '<em>$1</em>', $text) ?? $text;
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', static function ($m) {
        $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        if (!preg_match('~^(https?://|/|#)~i', $url)) {
            return $m[1];
        }
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
    }, $text) ?? $text;

    return preg_replace_callback("/\x00(\d+)\x00/", static fn($m) => $codes[(int)$m[1]] ?? '', $text) ?? $text;
}

function markdown_lite(string $md): string
{
    $lines = preg_split('/\r\n|\r|\n/', $md) ?: [];
    $html = '';
    $n = count($lines);
    $para = [];
    $flushPara = static function () use (&$para, &$html): void {
        if ($para) {
            $html .= '<p>' . markdown_lite_inline(implode(' ', $para)) . '</p>';
            $para = [];
        }
    };

    for ($i = 0; $i < $n; $i++) {
        $line = $lines[$i];
        $trim = trim($line);

        if ($trim === '') {
            $flushPara();
            continue;
        }
        // Bloco de código
        if (str_starts_with($trim, '```')) {
            $flushPara();
            $code = [];
            for ($i++; $i < $n && !str_starts_with(trim($lines[$i]), '```'); $i++) {
                $code[] = $lines[$i];
            }
            $html .= '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . '</code></pre>';
            continue;
        }
        // Título
        if (preg_match('/^(#{1,6})\s+(.+)$/', $trim, $m)) {
            $flushPara();
            $lvl = strlen($m[1]);
            $id = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $m[2]) ?: $m[2])), '-');
            $html .= '<h' . $lvl . ' id="' . htmlspecialchars($id, ENT_QUOTES) . '">' . markdown_lite_inline($m[2]) . '</h' . $lvl . '>';
            continue;
        }
        // Linha horizontal
        if (preg_match('/^(-{3,}|\*{3,})$/', $trim)) {
            $flushPara();
            $html .= '<hr>';
            continue;
        }
        // Tabela
        if (str_starts_with($trim, '|') && $i + 1 < $n && preg_match('/^\|?\s*:?-{2,}/', trim($lines[$i + 1]))) {
            $flushPara();
            $cells = static fn(string $l): array => array_map('trim', explode('|', trim(trim($l), '|')));
            $html .= '<div class="mdTableWrap"><table><thead><tr>';
            foreach ($cells($trim) as $c) {
                $html .= '<th>' . markdown_lite_inline($c) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            for ($i += 2; $i < $n && str_starts_with(trim($lines[$i]), '|'); $i++) {
                $html .= '<tr>';
                foreach ($cells($lines[$i]) as $c) {
                    $html .= '<td>' . markdown_lite_inline($c) . '</td>';
                }
                $html .= '</tr>';
            }
            $i--;
            $html .= '</tbody></table></div>';
            continue;
        }
        // Citação
        if (str_starts_with($trim, '>')) {
            $flushPara();
            $quote = [];
            for (; $i < $n && str_starts_with(trim($lines[$i]), '>'); $i++) {
                $quote[] = ltrim(substr(trim($lines[$i]), 1));
            }
            $i--;
            $html .= '<blockquote>' . markdown_lite_inline(implode(' ', $quote)) . '</blockquote>';
            continue;
        }
        // Listas (aninhadas por recuo)
        if (preg_match('/^(\s*)([-*]|\d+\.)\s+/', $line)) {
            $flushPara();
            $stack = []; // [indent, tag]
            for (; $i < $n; $i++) {
                $l = $lines[$i];
                if (!preg_match('/^(\s*)([-*]|\d+\.)\s+(.*)$/', $l, $lm)) {
                    // Continuação do item (linha recuada)
                    if (trim($l) !== '' && preg_match('/^\s{2,}\S/', $l) && $stack) {
                        $html = preg_replace('/<\/li>$/', ' ' . markdown_lite_inline(trim($l)) . '</li>', $html) ?? $html;
                        continue;
                    }
                    break;
                }
                $indent = strlen(str_replace("\t", '    ', $lm[1]));
                $tag = ctype_digit(rtrim($lm[2], '.')) ? 'ol' : 'ul';
                if (!$stack || $indent > end($stack)[0]) {
                    if ($stack) {
                        $html = preg_replace('/<\/li>$/', '', $html) ?? $html;
                    }
                    $html .= '<' . $tag . '>';
                    $stack[] = [$indent, $tag];
                } else {
                    while (count($stack) > 1 && $indent < end($stack)[0]) {
                        [, $t] = array_pop($stack);
                        $html .= '</' . $t . '></li>';
                    }
                }
                $html .= '<li>' . markdown_lite_inline($lm[3]) . '</li>';
            }
            while ($stack) {
                [, $t] = array_pop($stack);
                $html .= '</' . $t . '>' . ($stack ? '</li>' : '');
            }
            $i--;
            continue;
        }
        $para[] = $trim;
    }
    $flushPara();
    return $html;
}
