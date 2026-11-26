<?php

declare(strict_types=1);

/**
 * App instalável (PWA): manifesto, ícones, service worker (/sw.js) e o botão
 * "Instalar app". Usado por app/view.php (telas logadas) e login.php.
 * Instruções para o usuário: /app_instalar.php.
 */

const PWA_THEME_COLOR = '#1aa8a8';

/** Tags do <head>: manifesto, cor da barra, ícones (Android, iPhone, computador). */
function pwa_head_tags(): string
{
    return '<link rel="manifest" href="/manifest.webmanifest">'
        . '<meta name="theme-color" content="' . PWA_THEME_COLOR . '">'
        . '<meta name="mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-status-bar-style" content="default">'
        . '<meta name="apple-mobile-web-app-title" content="MultiLife">'
        . '<meta name="application-name" content="MultiLife Care">'
        . '<meta name="format-detection" content="telephone=no">'
        . '<link rel="apple-touch-icon" href="/pwa/apple-touch-icon.png">'
        . '<link rel="icon" type="image/png" sizes="32x32" href="/pwa/favicon-32.png">'
        . '<link rel="icon" type="image/png" sizes="192x192" href="/pwa/icon-192.png">';
}

/**
 * Registra o service worker e controla o botão "Instalar app":
 *  - Android/Chrome/Edge: guarda o pedido de instalação do navegador e mostra o botão;
 *  - iPhone/iPad (Safari não tem o pedido): o botão leva às instruções;
 *  - já instalado (aberto como app): esconde o botão.
 * Elementos opcionais na página: [data-pwa-install] (botões) e #pwaBanner.
 */
function pwa_footer_js(): string
{
    return <<<'JS'
<script>
(function () {
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {}); });
  }
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  var ua = navigator.userAgent || '';
  var isIOS = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var deferred = null;
  window.mlPwa = { standalone: standalone, isIOS: isIOS, canPrompt: function () { return !!deferred; } };
  function buttons() { return document.querySelectorAll('[data-pwa-install]'); }
  function show(on) { buttons().forEach(function (b) { b.style.display = on ? '' : 'none'; }); }
  window.mlPwaInstall = function () {
    if (deferred) {
      deferred.prompt();
      deferred.userChoice.finally(function () { deferred = null; show(false); hideBanner(); });
      return false;
    }
    location.href = '/app_instalar.php';
    return false;
  };
  function hideBanner() { var b = document.getElementById('pwaBanner'); if (b) b.style.display = 'none'; }
  window.mlPwaDismiss = function () { try { localStorage.setItem('ml_pwa_dismissed', String(Date.now())); } catch (e) {} hideBanner(); };
  function maybeBanner() {
    var b = document.getElementById('pwaBanner');
    if (!b || standalone || window.innerWidth > 900) return;
    // nas telas de conversa o aviso cobriria o campo de digitar
    if (/^\/(internal_chat|chat_web|chat_view|app_instalar)\.php/.test(location.pathname)) return;
    var d = 0; try { d = +localStorage.getItem('ml_pwa_dismissed') || 0; } catch (e) {}
    if (Date.now() - d < 14 * 86400000) return;
    if (deferred || isIOS) b.style.display = 'flex';
  }
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; show(true); maybeBanner(); });
  window.addEventListener('appinstalled', function () { deferred = null; show(false); hideBanner(); });
  document.addEventListener('DOMContentLoaded', function () {
    if (standalone) { show(false); document.documentElement.classList.add('isStandalone'); return; }
    if (isIOS) { show(true); }
    maybeBanner();
  });
})();
</script>
JS;
}
