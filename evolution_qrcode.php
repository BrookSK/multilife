<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$instanceName = trim($_GET['instance'] ?? admin_setting_get('evolution.instance'));

if (empty($instanceName)) {
    flash_set('error', 'Nenhuma instância especificada.');
    header('Location: /evolution_instances.php');
    exit;
}

view_header('QR Code - ' . $instanceName);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Conectar WhatsApp</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Escaneie o QR Code com o WhatsApp para conectar a instância <strong>' . h($instanceName) . '</strong>.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/evolution_instances.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');

if (empty($baseUrl) || empty($apiKey)) {
    echo '<section class="card col12">';
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">Configure as credenciais da Evolution API primeiro.</div>';
    echo '</section>';
    echo '</div>';
    view_footer();
    exit;
}

echo '<section class="card col12">';
echo '<div style="text-align:center;padding:20px">';
echo '<div id="qrcode-container" style="display:inline-block;padding:20px;background:white;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.1)">';
echo '<div style="color:#666;padding:40px">Carregando QR Code...</div>';
echo '</div>';
echo '<div id="status-message" style="margin-top:20px;font-size:14px;color:hsl(var(--muted-foreground))"></div>';
echo '</div>';
echo '</section>';

?>
<script>
let checkInterval;

function loadQRCode(){
  console.log("Iniciando carregamento do QR Code...");
  fetch("/evolution_proxy.php?action=connect&instance=<?= urlencode($instanceName) ?>")
  .then(r => {
    console.log("Resposta recebida, status:", r.status);
    return r.json();
  })
  .then(data => {
    console.log("Dados recebidos:", data);
    console.log("Dados JSON:", JSON.stringify(data, null, 2));
    const container = document.getElementById("qrcode-container");
    
    if(data.base64){
      console.log("QR Code base64 encontrado");
      container.innerHTML = `<img src="${data.base64}" alt="QR Code" style="max-width:300px;width:100%">`;
      document.getElementById("status-message").innerHTML = "Escaneie o QR Code com seu WhatsApp";
      startStatusCheck();
    } else if(data.qrcode && data.qrcode.base64){
      console.log("QR Code em data.qrcode.base64");
      container.innerHTML = `<img src="${data.qrcode.base64}" alt="QR Code" style="max-width:300px;width:100%">`;
      document.getElementById("status-message").innerHTML = "Escaneie o QR Code com seu WhatsApp";
      startStatusCheck();
    } else if(data.qrcode && data.qrcode.code){
      console.log("QR Code em data.qrcode.code");
      container.innerHTML = `<img src="${data.qrcode.code}" alt="QR Code" style="max-width:300px;width:100%">`;
      document.getElementById("status-message").innerHTML = "Escaneie o QR Code com seu WhatsApp";
      startStatusCheck();
    } else if(data.error){
      console.error("Erro da API:", data.error);
      let errorMsg = data.error;
      if(data.url) errorMsg += "<br><small>URL: " + data.url + "</small>";
      if(data.response) errorMsg += "<br><small>Resposta: " + data.response + "</small>";
      container.innerHTML = `<div style="color:red;padding:20px">${errorMsg}</div>`;
    } else {
      console.error("Resposta inesperada:", data);
      console.error("Propriedades do objeto:", Object.keys(data));
      container.innerHTML = `<div style="color:orange;padding:20px">Resposta da API em formato inesperado.<br><small>Verifique o console para detalhes.</small><br><pre style="text-align:left;font-size:11px;margin-top:10px">${JSON.stringify(data, null, 2)}</pre></div>`;
    }
  })
  .catch(e => {
    console.error("Erro ao carregar QR Code:", e);
    document.getElementById("qrcode-container").innerHTML = `<div style="color:red;padding:20px">Erro ao carregar QR Code: ${e.message}</div>`;
  });
}

function checkStatus(){
  fetch("/evolution_proxy.php?action=status&instance=<?= urlencode($instanceName) ?>")
  .then(r => r.json())
  .then(data => {
    if(data.state === "open"){
      clearInterval(checkInterval);
      document.getElementById("status-message").innerHTML = `<span style="color:hsl(142,76%,36%);font-weight:600">✓ Conectado com sucesso!</span>`;
      setTimeout(() => window.location.href = "/evolution_instances.php", 2000);
    }
  });
}

function startStatusCheck(){
  checkInterval = setInterval(checkStatus, 3000);
}

loadQRCode();
</script>
<?php

echo '</div>';

view_footer();
