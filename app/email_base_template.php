<?php

declare(strict_types=1);

/**
 * Template base de e-mail — Layout profissional MultiLife Care
 * Todas as funções de geração de HTML de e-mail usam este layout.
 */

/**
 * Gera o wrapper HTML completo com header/footer da identidade MultiLife
 */
function email_base_layout(string $title, string $bodyContent, string $footerNote = ''): string
{
    $logoUrl = 'https://multilife.onsolutionsbrasil.com.br/uploads/logo_1773584168.png';
    $primaryColor = '#00a884';
    $year = date('Y');
    
    $footerHtml = $footerNote !== '' 
        ? '<p style="margin:8px 0 0;font-size:11px;color:#9ca3af">' . $footerNote . '</p>' 
        : '';

    return '<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . htmlspecialchars($title) . '</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;line-height:1.6;color:#1f2937">
<div style="max-width:680px;margin:0 auto;padding:24px 16px">

<!-- Header -->
<div style="background:' . $primaryColor . ';padding:28px 32px;border-radius:12px 12px 0 0;text-align:center">
<img src="' . $logoUrl . '" alt="MultiLife Care" style="max-height:48px;margin-bottom:12px" />
<h1 style="margin:0;font-size:20px;font-weight:700;color:#ffffff;letter-spacing:-0.3px">' . htmlspecialchars($title) . '</h1>
</div>

<!-- Body -->
<div style="background:#ffffff;padding:32px;border:1px solid #e5e7eb;border-top:none">
' . $bodyContent . '
</div>

<!-- Footer -->
<div style="padding:20px 32px;text-align:center;border-radius:0 0 12px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-top:none">
<p style="margin:0;font-size:12px;color:#6b7280">Este e-mail foi enviado automaticamente pelo sistema MultiLife Care.</p>
<p style="margin:6px 0 0;font-size:12px;color:#6b7280">© ' . $year . ' MultiLife Care — Atendimento Domiciliar</p>
' . $footerHtml . '
</div>

</div>
</body>
</html>';
}

/**
 * Gera um bloco de seção com borda colorida à esquerda
 */
function email_section(string $title, string $content, string $color = '#00a884'): string
{
    return '
<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px;border-left:4px solid ' . $color . '">
<h3 style="margin:0 0 10px;font-size:15px;font-weight:700;color:' . $color . '">' . $title . '</h3>
' . $content . '
</div>';
}

/**
 * Gera uma linha de dados (label: valor)
 */
function email_data_row(string $label, string $value): string
{
    if (trim($value) === '' || $value === '-') return '';
    return '<p style="margin:4px 0;font-size:14px"><strong style="color:#374151">' . htmlspecialchars($label) . ':</strong> <span style="color:#4b5563">' . htmlspecialchars($value) . '</span></p>';
}

/**
 * Gera um bloco de destaque (valor total, etc)
 */
function email_highlight_box(string $label, string $value, string $bgColor = '#d1fae5', string $textColor = '#059669'): string
{
    return '
<div style="background:' . $bgColor . ';padding:20px;margin:24px 0;border-radius:10px;text-align:center">
<p style="margin:0;font-size:13px;color:#065f46;font-weight:600">' . htmlspecialchars($label) . '</p>
<p style="margin:8px 0 0;font-size:28px;font-weight:900;color:' . $textColor . ';letter-spacing:-0.5px">' . htmlspecialchars($value) . '</p>
</div>';
}

/**
 * Gera tabela de cronograma de sessões
 */
function email_session_table(array $sessions): string
{
    if (empty($sessions)) return '';
    
    $rows = '';
    foreach ($sessions as $i => $session) {
        $num = $i + 1;
        $date = $session['formatted'] ?? ($session['date'] . ' às ' . substr($session['start_time'] ?? '08:00', 0, 5));
        $bg = ($num % 2 === 0) ? '#f9fafb' : '#ffffff';
        $rows .= '<tr style="background:' . $bg . '">
            <td style="padding:8px 12px;font-size:13px;font-weight:600;color:#374151">Sessão ' . $num . '</td>
            <td style="padding:8px 12px;font-size:13px;color:#4b5563;text-align:right">' . htmlspecialchars($date) . '</td>
        </tr>';
    }
    
    return '
<div style="margin:24px 0">
<h3 style="margin:0 0 12px;font-size:15px;font-weight:700;color:#374151">📅 Cronograma de Sessões</h3>
<table style="width:100%;border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
<thead>
<tr style="background:#f3f4f6">
<th style="padding:10px 12px;font-size:12px;text-align:left;color:#6b7280;font-weight:600;text-transform:uppercase">Sessão</th>
<th style="padding:10px 12px;font-size:12px;text-align:right;color:#6b7280;font-weight:600;text-transform:uppercase">Data e Horário</th>
</tr>
</thead>
<tbody>' . $rows . '</tbody>
</table>
</div>';
}

/**
 * Gera bloco de observações
 */
function email_notes_block(string $notes): string
{
    if (trim($notes) === '') return '';
    return '
<div style="background:#fffbeb;padding:16px 20px;margin:20px 0;border-radius:8px;border-left:4px solid #f59e0b">
<h3 style="margin:0 0 8px;font-size:14px;font-weight:700;color:#92400e">📝 Observações</h3>
<p style="margin:0;font-size:14px;color:#78350f;line-height:1.7">' . nl2br(htmlspecialchars($notes)) . '</p>
</div>';
}

/**
 * Gera separador visual
 */
function email_divider(): string
{
    return '<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0">';
}
