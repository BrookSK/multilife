<?php

declare(strict_types=1);

/**
 * Como instalar o app MultiLife Care (PWA) no celular e no computador.
 * Aberta também sem login (o botão "Instalar o app" da tela de login traz para cá).
 */

require_once __DIR__ . '/app/bootstrap.php';

$logged = auth_user_id() !== null;

$content = <<<'HTML'
<style>
.paWrap{max-width:760px;margin:0 auto;display:grid;gap:14px}
.paHero{display:flex;gap:16px;align-items:center;background:linear-gradient(135deg,#2bc4c4,#0e8080);color:#fff;border-radius:20px;padding:20px}
.paHero img{width:72px;height:72px;border-radius:18px;box-shadow:0 6px 18px rgba(0,0,0,.25);flex:0 0 auto}
.paHero h1{margin:0 0 4px;font-size:22px;color:#fff}
.paHero p{margin:0;opacity:.95;line-height:1.45}
.paCard{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:16px 18px}
.paCard h2{margin:0 0 10px;font-size:18px;display:flex;gap:8px;align-items:center}
.paCard ol{margin:0;padding-left:20px;line-height:1.7}
.paBtn{display:none;width:100%;padding:15px;border:0;border-radius:14px;background:#1aa8a8;color:#fff;font-size:17px;font-weight:800;cursor:pointer}
.paOk{display:none;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:14px;padding:14px;font-weight:600}
.paMuted{color:#64748b;font-size:14px;line-height:1.5}
</style>
<div class="paWrap">
  <div class="paHero"><img src="/pwa/icon-192.png" alt="">
    <div><h1>App MultiLife Care</h1><p>Instale no celular ou no computador: abre direto da tela inicial, em tela cheia, sem precisar procurar o site.</p></div></div>
  <div class="paOk" id="paOk">✅ O app já está instalado e aberto como aplicativo neste aparelho.</div>
  <button type="button" class="paBtn" id="paBtn" onclick="mlPwaInstall()">📲 Instalar agora</button>
  <div class="paCard" id="paAndroid"><h2>🤖 Android (Chrome)</h2><ol>
    <li>Abra o sistema no <b>Chrome</b>.</li>
    <li>Toque no botão <b>Instalar agora</b> acima ou no menu <b>⋮</b> → <b>Instalar app</b> (ou "Adicionar à tela inicial").</li>
    <li>Confirme em <b>Instalar</b>. O ícone do MultiLife aparece na tela inicial.</li></ol></div>
  <div class="paCard" id="paIos"><h2>🍎 iPhone e iPad (Safari)</h2><ol>
    <li>Abra o sistema no <b>Safari</b> (no iPhone a instalação é só pelo Safari).</li>
    <li>Toque em <b>Compartilhar</b> (o quadrado com a seta para cima, na barra de baixo).</li>
    <li>Role e toque em <b>Adicionar à Tela de Início</b> → <b>Adicionar</b>.</li></ol>
    <p class="paMuted" style="margin:10px 0 0">Para receber avisos do Chat Interno no iPhone, abra pelo ícone instalado e permita as notificações quando o app pedir (iOS 16.4 ou mais novo).</p></div>
  <div class="paCard"><h2>💻 Computador (Chrome ou Edge)</h2><ol>
    <li>Com o sistema aberto, clique no ícone de <b>instalar</b> no fim da barra de endereço (um monitor com seta) ou no menu → <b>Instalar MultiLife Care</b>.</li>
    <li>O app abre em janela própria e fica no menu Iniciar / Dock.</li></ol></div>
  <p class="paMuted">Por segurança, o app não guarda dados de pacientes no aparelho: sem internet ele mostra só um aviso para tentar de novo. Sair da conta no app funciona igual ao site.</p>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var p = window.mlPwa || {};
  if (p.standalone) { document.getElementById('paOk').style.display = 'block'; return; }
  var ios = p.isIOS;
  document.getElementById(ios ? 'paAndroid' : 'paIos').style.order = '5';
  var show = function () { if (window.mlPwa && window.mlPwa.canPrompt()) document.getElementById('paBtn').style.display = 'block'; };
  show(); window.addEventListener('beforeinstallprompt', function () { setTimeout(show, 0); });
});
</script>
HTML;

if ($logged) {
    view_header('Instalar o app');
    echo $content;
    view_footer();
    exit;
}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Instalar o app · MultiLife Care</title>
<?= pwa_head_tags() ?>
<style>body{margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f4f7f9;color:#0f172a;padding:calc(16px + env(safe-area-inset-top)) 14px 24px}a{color:#0e8080}</style>
</head>
<body>
<?= $content ?>
<p style="text-align:center;margin-top:16px"><a href="/login.php">← Voltar para o login</a></p>
<?= pwa_footer_js() ?>
</body>
</html>
