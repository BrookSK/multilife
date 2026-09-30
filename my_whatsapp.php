<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();

$userId = (int)auth_user_id();

// Buscar TODAS as instâncias vinculadas ao usuário (vínculo N:N + legado).
$instances = whatsapp_list_user_instances($userId);

$baseUrl = (string)admin_setting_get('evolution.base_url', '');
$apiKey = (string)admin_setting_get('evolution.api_key', '');
$configOk = $baseUrl !== '' && $apiKey !== '';

view_header('Meu WhatsApp');

echo '<div class="grid">';

// Cabeçalho
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Meu WhatsApp</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Veja o status das suas conexões de WhatsApp e reconecte quando alguma estiver desconectada.</div>';
echo '</div>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</section>';

if (!$configOk) {
    echo '<section class="card col12">';
    echo '<div style="padding:30px;text-align:center;color:hsl(var(--muted-foreground))">';
    echo '<div style="font-size:40px;margin-bottom:12px">⚙️</div>';
    echo '<div style="font-size:16px;font-weight:600">Integração WhatsApp não configurada</div>';
    echo '<div style="font-size:14px;margin-top:6px">Entre em contato com o administrador.</div>';
    echo '</div>';
    echo '</section>';
    echo '</div>';
    view_footer();
    return;
}

if (count($instances) === 0) {
    // Nenhuma instância vinculada
    echo '<section class="card col12">';
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">';
    echo '<div style="font-size:48px;margin-bottom:16px">📱</div>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhuma instância WhatsApp vinculada</div>';
    echo '<div style="font-size:14px">Entre em contato com o administrador para vincular uma instância ao seu usuário.</div>';
    echo '</div>';
    echo '</section>';
    echo '</div>';
    view_footer();
    return;
}

// Estilos + área compartilhada de QR
echo '<style>';
echo '@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}';
echo '.waCardInst{border:1px solid hsl(var(--border));border-radius:12px;padding:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}';
echo '.waDot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px}';
echo '.waBadge{font-size:12px;font-weight:700;padding:3px 10px;border-radius:12px}';
echo '</style>';

echo '<section class="card col12">';
echo '<div style="font-size:16px;font-weight:700;margin-bottom:12px">Suas conexões</div>';

foreach ($instances as $inst) {
    $instName = (string)($inst['instance_name'] ?? '');
    $alias = trim((string)($inst['display_name'] ?? ''));
    $number = (string)($inst['owner_phone_formatted'] ?: ($inst['owner_number'] ?? ''));
    $title = $alias !== '' ? $alias : $instName;

    $jsName = htmlspecialchars(json_encode($instName), ENT_QUOTES);

    echo '<div class="waCardInst" id="waCard_' . h($instName) . '" data-instance="' . h($instName) . '">';
    echo '<div>';
    echo '<div style="font-weight:700;font-size:15px">' . h($title) . '</div>';
    $sub = 'Instância: ' . h($instName);
    if ($number !== '') {
        $sub .= ' · Número: ' . h($number);
    }
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-top:2px">' . $sub . '</div>';
    echo '</div>';
    echo '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">';
    // Badge de status (preenchido via JS)
    echo '<span class="waBadge waStatusBadge" data-instance="' . h($instName) . '" style="background:#f1f5f9;color:#64748b"><span class="waDot" style="background:#94a3b8"></span>Verificando...</span>';
    // Botões (habilitados conforme status via JS)
    echo '<button type="button" class="btn btnPrimary waBtnReconnect" data-instance="' . h($instName) . '" style="display:none" onclick=\'myWaReconnect(' . $jsName . ')\'>Reconectar</button>';
    echo '<button type="button" class="btn waBtnDisconnect" data-instance="' . h($instName) . '" style="display:none;background:#fee2e2;color:#dc2626;border-color:#fca5a5" onclick=\'myWaDisconnect(' . $jsName . ')\'>Desconectar</button>';
    echo '</div>';
    echo '</div>';
}

// Área do QR Code (compartilhada)
echo '<div id="myWaQrArea" style="display:none;margin-top:8px;text-align:center;padding:20px;border-top:1px solid hsl(var(--border))">';
echo '<div id="myWaQrTitle" style="font-size:14px;font-weight:700;margin-bottom:12px"></div>';
echo '<div id="myWaQrContainer" style="display:inline-block;padding:16px;background:white;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08)"></div>';
echo '<div id="myWaQrMsg" style="margin-top:12px;font-size:13px;color:hsl(var(--muted-foreground))"></div>';
echo '<div style="margin-top:8px;font-size:12px;color:hsl(var(--muted-foreground))">Abra o WhatsApp &gt; Aparelhos conectados &gt; Conectar aparelho</div>';
echo '</div>';

echo '</section>';
echo '</div>';

// JavaScript: verifica status de cada instância, exibe QR e autodetecta conexão.
echo <<<'MYWAJS'
<script>
(function(){
  var pollTimer = null;
  var pollingInstance = "";

  function endpoint(action, instance){
    return "/my_whatsapp_proxy.php?action=" + action + "&instance=" + encodeURIComponent(instance);
  }

  function setBadge(instance, state){
    var badges = document.querySelectorAll('.waStatusBadge[data-instance="' + cssEscape(instance) + '"]');
    var btnReconnect = document.querySelector('.waBtnReconnect[data-instance="' + cssEscape(instance) + '"]');
    var btnDisconnect = document.querySelector('.waBtnDisconnect[data-instance="' + cssEscape(instance) + '"]');
    var label, bg, color, dot;
    if(state === "open" || state === "connected"){
      label = "Conectado"; bg = "#dcfce7"; color = "#166534"; dot = "#22c55e";
      if(btnReconnect) btnReconnect.style.display = "none";
      if(btnDisconnect) btnDisconnect.style.display = "";
    } else if(state === "connecting"){
      label = "Conectando..."; bg = "#e0f2fe"; color = "#075985"; dot = "#0ea5e9";
      if(btnReconnect) btnReconnect.style.display = "";
      if(btnDisconnect) btnDisconnect.style.display = "none";
    } else {
      label = "Desconectado"; bg = "#fee2e2"; color = "#991b1b"; dot = "#ef4444";
      if(btnReconnect) btnReconnect.style.display = "";
      if(btnDisconnect) btnDisconnect.style.display = "none";
    }
    badges.forEach(function(b){
      b.style.background = bg; b.style.color = color;
      b.innerHTML = '<span class="waDot" style="background:' + dot + '"></span>' + label;
    });
  }

  function cssEscape(s){ return String(s).replace(/["\\]/g, '\\$&'); }

  function checkStatus(instance){
    fetch(endpoint("status", instance))
    .then(function(r){ return r.json(); })
    .then(function(d){
      var state = d.state || (d.instance && d.instance.state) || "";
      setBadge(instance, state);
    })
    .catch(function(){ setBadge(instance, "disconnected"); });
  }

  window.myWaReconnect = function(instance){
    var area = document.getElementById("myWaQrArea");
    var title = document.getElementById("myWaQrTitle");
    var container = document.getElementById("myWaQrContainer");
    var msg = document.getElementById("myWaQrMsg");
    area.style.display = "block";
    area.scrollIntoView({behavior:"smooth", block:"center"});
    title.textContent = "Reconectar: " + instance;
    container.innerHTML = '<div style="color:#666;padding:30px">Gerando QR Code...</div>';
    msg.textContent = "";
    fetch(endpoint("connect", instance))
    .then(function(r){ return r.json(); })
    .then(function(data){
      if(data.instance && (data.instance.state === "open" || data.instance.state === "connected")){
        container.innerHTML = '<div style="color:#166534;padding:30px;font-weight:700">Já conectado!</div>';
        setBadge(instance, "open");
        setTimeout(function(){ area.style.display = "none"; }, 1500);
        return;
      }
      var base64 = data.base64 || (data.qrcode && data.qrcode.base64) || (data.qrcode && data.qrcode.code) || "";
      if(base64){
        var src = base64.indexOf("data:") === 0 ? base64 : "data:image/png;base64," + base64;
        container.innerHTML = '<img src="' + src + '" alt="QR Code" style="max-width:280px;width:100%;display:block">';
        msg.innerHTML = '<span style="color:#0ea5e9;font-weight:600">Escaneie o QR Code com o WhatsApp desta conta</span>';
        startPolling(instance);
      } else if(data.error){
        container.innerHTML = '<div style="color:#ef4444;padding:20px">' + data.error + '</div>';
      } else {
        container.innerHTML = '<div style="color:#f59e0b;padding:20px">QR Code não disponível. Tente novamente.</div>';
      }
    })
    .catch(function(e){
      container.innerHTML = '<div style="color:#ef4444;padding:20px">Erro: ' + e.message + '</div>';
    });
  };

  window.myWaDisconnect = function(instance){
    if(!confirm("Desconectar o WhatsApp da instância '" + instance + "'?")) return;
    fetch(endpoint("logout", instance))
    .then(function(r){ return r.json().catch(function(){ return {success:true}; }); })
    .then(function(){ setBadge(instance, "disconnected"); })
    .catch(function(e){ alert("Erro ao desconectar: " + e.message); });
  };

  function startPolling(instance){
    if(pollTimer){ clearInterval(pollTimer); }
    pollingInstance = instance;
    pollTimer = setInterval(function(){
      fetch(endpoint("status", instance))
      .then(function(r){ return r.json(); })
      .then(function(d){
        var state = d.state || (d.instance && d.instance.state) || "";
        setBadge(instance, state);
        if(state === "open" || state === "connected"){
          clearInterval(pollTimer); pollTimer = null;
          var container = document.getElementById("myWaQrContainer");
          var msg = document.getElementById("myWaQrMsg");
          container.innerHTML = '<div style="color:#166534;padding:30px;font-weight:700">Conectado com sucesso!</div>';
          msg.textContent = "";
          setTimeout(function(){ document.getElementById("myWaQrArea").style.display = "none"; }, 2500);
        }
      });
    }, 3000);
  }

  // Verificar status de todas as instâncias ao carregar.
  document.querySelectorAll('.waCardInst').forEach(function(card){
    checkStatus(card.getAttribute("data-instance"));
  });
})();
</script>
MYWAJS;

view_footer();
