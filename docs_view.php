<?php

declare(strict_types=1);

/**
 * Exibe (ou baixa) um documento interno da empresa (docs/*.md) da lista fixa
 * em app/company_docs.php. Acesso: Gestão Documental (documents.manage).
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('documents.manage');

$key = (string)($_GET['doc'] ?? '');
$docs = company_system_docs();
$path = company_system_doc_path($key);
if ($path === null) {
    flash_set('error', 'Documento não encontrado.');
    header('Location: /documents_list.php?tab=company');
    exit;
}
$meta = $docs[$key];
$md = (string)file_get_contents($path);

// Download do arquivo original (.md).
if (!empty($_GET['download'])) {
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $meta['file'] . '"');
    header('X-Content-Type-Options: nosniff');
    echo $md;
    exit;
}

view_header($meta['title']);

echo '<style>';
echo '.mdDoc{line-height:1.65;font-size:15px;max-width:1000px}';
echo '.mdDoc h1{font-size:26px;margin:0 0 10px}.mdDoc h2{font-size:20px;margin:28px 0 10px;padding-top:10px;border-top:1px solid hsl(var(--border))}.mdDoc h3{font-size:16px;margin:20px 0 8px}';
echo '.mdDoc code{background:hsl(var(--muted));padding:1px 6px;border-radius:5px;font-size:13px}';
echo '.mdDoc pre{background:#0f172a;color:#e2e8f0;padding:12px 14px;border-radius:10px;overflow:auto}.mdDoc pre code{background:transparent;color:inherit;padding:0}';
echo '.mdDoc .mdTableWrap{overflow:auto;margin:10px 0}.mdDoc table{border-collapse:collapse;width:100%;font-size:14px}';
echo '.mdDoc th,.mdDoc td{border:1px solid hsl(var(--border));padding:7px 10px;text-align:left;vertical-align:top}.mdDoc th{background:hsl(var(--muted))}';
echo '.mdDoc ul,.mdDoc ol{padding-left:22px}.mdDoc li{margin:3px 0}.mdDoc hr{border:0;border-top:1px solid hsl(var(--border));margin:20px 0}';
echo '.mdDoc blockquote{margin:10px 0;padding:8px 14px;border-left:4px solid hsl(var(--primary));background:hsla(var(--primary)/.06)}';
echo '@media print{.sidebar,.topbar,.mdActions{display:none!important}.mainCol{margin-left:0!important}}';
echo '</style>';

echo '<section class="card">';
echo '<div class="mdActions" style="display:flex;gap:10px;flex-wrap:wrap;justify-content:space-between;align-items:center;margin-bottom:14px">';
echo '<a class="btn" href="/documents_list.php?tab=company">← Documentos da empresa</a>';
echo '<div style="display:flex;gap:8px">';
echo '<button type="button" class="btn" onclick="window.print()">Imprimir / salvar PDF</button>';
echo '<a class="btn btnPrimary" href="/docs_view.php?doc=' . urlencode($key) . '&download=1">Baixar .md</a>';
echo '</div></div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:10px">Atualizado em ' . h(date('d/m/Y H:i', (int)filemtime($path))) . '</div>';
echo '<article class="mdDoc">' . markdown_lite($md) . '</article>';
echo '</section>';

view_footer();
