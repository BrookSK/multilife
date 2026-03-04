<?php

declare(strict_types=1);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function view_header(string $title): void
{
    $user = auth_user();
    $flashError = flash_get('error');
    $flashSuccess = flash_get('success');

    echo '<!doctype html>';
    echo '<html lang="pt-BR">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<style>';
    echo ':root{--bg1:#0b1020;--bg2:#111a33;--card:rgba(255,255,255,.08);--cardBorder:rgba(255,255,255,.14);--text:#eaf0ff;--muted:rgba(234,240,255,.72);--primary:#6d5efc;--primary2:#4fd1c5;--danger:#ff5b7a;--shadow:0 20px 60px rgba(0,0,0,.45);--radius:18px;}';
    echo '*{box-sizing:border-box}';
    echo 'body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;min-height:100vh;color:var(--text);background:radial-gradient(900px 500px at 12% 18%, rgba(109,94,252,.35), transparent 55%),radial-gradient(700px 500px at 88% 20%, rgba(79,209,197,.26), transparent 55%),radial-gradient(900px 700px at 60% 110%, rgba(255,91,122,.18), transparent 60%),linear-gradient(180deg,var(--bg1),var(--bg2));}';
    echo 'a{color:rgba(234,240,255,.9);text-decoration:none} a:hover{text-decoration:underline}';
    echo '.top{max-width:1100px;margin:0 auto;padding:18px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}';
    echo '.brand{display:flex;align-items:center;gap:10px;font-weight:800;letter-spacing:.2px}';
    echo '.pill{padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);font-size:12px;color:rgba(234,240,255,.8)}';
    echo '.nav{display:flex;gap:10px;align-items:center;flex-wrap:wrap}';
    echo '.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 12px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);font-weight:700;font-size:13px}';
    echo '.btnPrimary{border:none;background:linear-gradient(135deg, rgba(109,94,252,.95), rgba(79,209,197,.9));box-shadow:0 18px 42px rgba(0,0,0,.35)}';
    echo '.wrap{max-width:1100px;margin:0 auto;padding:0 16px 26px}';
    echo '.card{background:var(--card);border:1px solid var(--cardBorder);box-shadow:var(--shadow);border-radius:var(--radius);padding:18px;backdrop-filter:blur(10px)}';
    echo '.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px}';
    echo '.col6{grid-column:span 6} .col12{grid-column:span 12}';
    echo '.alert{margin:0 0 14px;padding:12px;border-radius:14px;font-size:13px;line-height:1.4;border:1px solid transparent}';
    echo '.alertError{background:rgba(255,91,122,.14);border-color:rgba(255,91,122,.35)}';
    echo '.alertSuccess{background:rgba(79,209,197,.14);border-color:rgba(79,209,197,.35)}';
    echo '@media(max-width:860px){.col6{grid-column:span 12}}';
    echo '</style>';
    echo '</head>';
    echo '<body>';

    echo '<header class="top">';
    echo '<div class="brand">Multilife <span class="pill">Care</span></div>';
    echo '<nav class="nav">';
    echo '<a class="btn" href="/">Início</a>';
    if ($user !== null) {
        echo '<span class="pill">' . h($user['name']) . '</span>';
        echo '<a class="btn" href="/logout.php">Sair</a>';
    } else {
        echo '<a class="btn btnPrimary" href="/login.php">Entrar</a>';
    }
    echo '</nav>';
    echo '</header>';

    echo '<main class="wrap">';

    if ($flashError !== '') {
        echo '<div class="alert alertError" role="alert">' . h($flashError) . '</div>';
    }
    if ($flashSuccess !== '') {
        echo '<div class="alert alertSuccess" role="alert">' . h($flashSuccess) . '</div>';
    }
}

function view_footer(): void
{
    echo '</main>';
    echo '</body>';
    echo '</html>';
}
