<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();

$db = db();
$userId = auth_user_id();
$user = auth_user();
$userName = $user ? (string)$user['name'] : '';
$userPhone = '';
try {
    $s = $db->prepare("SELECT phone FROM users WHERE id = ?");
    $s->execute([$userId]);
    $userPhone = (string)($s->fetchColumn() ?: '');
} catch (Throwable $e) {}

// Verificar configuracao da Evolution API
$baseUrl = (string)admin_setting_get('evolution.base_url', '');
$apiKey = (string)admin_setting_get('evolution.api_key', '');

$myInstance = null;
$myInstanceStatus = 'desconectado';
$qrCode = '';
$errorMsg = '';

// Buscar instancia vinculada a este usuario
try {
    $stmt = $db->prepare("SELECT * FROM whatsapp_instances WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $myInstance = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Tabela pode nao ter coluna user_id
    try {
        $cleanPhone = preg_replace('/\D+/', '', $userPhone);
        if (strlen($cleanPhone) >= 10) {
            $stmt = $db->prepare("SELECT * FROM whatsapp_instances WHERE number LIKE ? AND status = 'active' ORDER BY id DESC LIMIT 1");
            $stmt->execute(['%' . substr($cleanPhone, -8) . '%']);
            $myInstance = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e2) {}
}

// Se tem instancia, verificar status
if ($myInstance && $baseUrl !== '' && $apiKey !== '') {
    try {
        $instName = (string)$myInstance['instance_name'];
        $api = new EvolutionApiV1($baseUrl, $apiKey, $instName);
        $connRes = $api->connectionState();
        $connJson = $connRes['json'] ?? [];
        $state = '';
        if (isset($connJson['instance']['state'])) { $state = (string)$connJson['instance']['state']; }
        elseif (isset($connJson['state'])) { $state = (string)$connJson['state']; }
        $myInstanceStatus = in_array(strtolower($state), ['open', 'connected'], true) ? 'conectado' : 'desconectado';
    } catch (Throwable $e) {
        $myInstanceStatus = 'erro';
    }
}

// POST: Conectar (gerar QR)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'connect' && $myInstance) {
    try {
        $instName = (string)$myInstance['instance_name'];
        $api = new EvolutionApiV1($baseUrl, $apiKey, $instName);
        $connRes = $api->connectInstance($instName);
        $connJson = $connRes['json'] ?? [];
        if (isset($connJson['base64'])) {
            $qrCode = (string)$connJson['base64'];
        } elseif (isset($connJson['code'])) {
            $qrCode = (string)$connJson['code'];
        }
        if ($qrCode === '') {
            // Pode ja estar conectado
            $myInstanceStatus = 'conectado';
        }
    } catch (Throwable $e) {
        $errorMsg = 'Erro ao gerar QR Code: ' . $e->getMessage();
    }
}

// POST: Desconectar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disconnect' && $myInstance) {
    try {
        $instName = (string)$myInstance['instance_name'];
        $api = new EvolutionApiV1($baseUrl, $apiKey, $instName);
        $api->logoutInstance($instName);
        $myInstanceStatus = 'desconectado';
        flash_set('success', 'WhatsApp desconectado.');
        header('Location: /my_whatsapp.php');
        exit;
    } catch (Throwable $e) {
        $errorMsg = 'Erro ao desconectar: ' . $e->getMessage();
    }
}

view_header('Meu WhatsApp');

$fl = flash_get('success');
if ($fl) { echo '<div class="alert alertSuccess">' . htmlspecialchars($fl) . '</div>'; }
if ($errorMsg !== '') { echo '<div class="alert alertError">' . htmlspecialchars($errorMsg) . '</div>'; }

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Meu WhatsApp</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Conecte seu WhatsApp para receber e enviar mensagens pelo sistema.</div>';
echo '</div>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</section>';

// Status da conexao
echo '<section class="card col12">';
if ($myInstance) {
    $instName = (string)$myInstance['instance_name'];
    $instNumber = (string)($myInstance['number'] ?? $userPhone);
    $statusColor = $myInstanceStatus === 'conectado' ? '#10b981' : '#ef4444';
    $statusLabel = $myInstanceStatus === 'conectado' ? 'Conectado' : 'Desconectado';
    $statusIcon = $myInstanceStatus === 'conectado' ? '✅' : '❌';

    echo '<div style="display:flex;align-items:center;gap:16px;margin-bottom:20px">';
    echo '<div style="width:50px;height:50px;border-radius:12px;background:' . $statusColor . '15;display:flex;align-items:center;justify-content:center;font-size:24px">' . $statusIcon . '</div>';
    echo '<div>';
    echo '<div style="font-size:18px;font-weight:700">Status: <span style="color:' . $statusColor . '">' . $statusLabel . '</span></div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-top:4px">Instancia: ' . htmlspecialchars($instName) . ' | Numero: ' . htmlspecialchars($instNumber) . '</div>';
    echo '</div>';
    echo '</div>';

    // QR Code se gerado
    if ($qrCode !== '') {
        echo '<div style="text-align:center;padding:20px;background:white;border:1px solid hsl(var(--border));border-radius:12px;margin-bottom:20px">';
        echo '<div style="font-size:14px;font-weight:700;margin-bottom:12px">Escaneie o QR Code com seu WhatsApp</div>';
        if (str_starts_with($qrCode, 'data:image') || str_starts_with($qrCode, 'iVBOR') || str_starts_with($qrCode, '/9j/')) {
            $src = str_starts_with($qrCode, 'data:') ? $qrCode : 'data:image/png;base64,' . $qrCode;
            echo '<img src="' . $src . '" style="max-width:280px;margin:0 auto;display:block">';
        } else {
            echo '<div style="font-family:monospace;font-size:11px;word-break:break-all;max-width:400px;margin:0 auto">' . htmlspecialchars($qrCode) . '</div>';
        }
        echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:12px">Abra o WhatsApp > Menu > Aparelhos conectados > Conectar aparelho</div>';
        echo '</div>';
    }

    // Botoes
    echo '<div style="display:flex;gap:10px">';
    if ($myInstanceStatus !== 'conectado') {
        echo '<form method="post"><input type="hidden" name="action" value="connect"><button type="submit" class="btn btnPrimary">Conectar WhatsApp</button></form>';
    } else {
        echo '<form method="post"><input type="hidden" name="action" value="disconnect"><button type="submit" class="btn" style="background:#fee2e2;color:#dc2626;border-color:#fca5a5" onclick="return confirm(\'Deseja desconectar seu WhatsApp?\')">Desconectar</button></form>';
        echo '<form method="post"><input type="hidden" name="action" value="connect"><button type="submit" class="btn">Gerar novo QR Code</button></form>';
    }
    echo '</div>';

} else {
    // Sem instancia vinculada
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">';
    echo '<div style="font-size:48px;margin-bottom:16px">📱</div>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhuma instancia WhatsApp vinculada</div>';
    echo '<div style="font-size:14px">Entre em contato com o administrador para vincular uma instancia ao seu usuario.</div>';
    echo '</div>';
}

echo '</section>';
echo '</div>';

view_footer();
