<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;

// Buscar funcionário
$stmt = db()->prepare('SELECT * FROM hr_employees WHERE id = :id');
$stmt->execute(['id' => $employeeId]);
$employee = $stmt->fetch();

if (!$employee) {
    flash_set('error', 'Funcionário não encontrado.');
    header('Location: /hr_dashboard.php');
    exit;
}

// Buscar templates ativos
$templates = db()->query('SELECT * FROM zapsign_contract_templates WHERE is_active = 1 ORDER BY name ASC')->fetchAll();

// Buscar contratos já enviados para este funcionário
$stmt = db()->prepare('
    SELECT c.*, t.name as template_name
    FROM hr_employee_contracts c
    LEFT JOIN zapsign_contract_templates t ON t.id = c.template_id
    WHERE c.employee_id = :employee_id
    ORDER BY c.created_at DESC
');
$stmt->execute(['employee_id' => $employeeId]);
$contracts = $stmt->fetchAll();

// Buscar configuração do ZapSign
$config = db()->query('SELECT * FROM zapsign_config LIMIT 1')->fetch();
$isConfigured = $config && !empty($config['api_token']);

view_header('Gerar Contrato - ' . $employee['full_name']);

echo '<div class="grid">';

// Header
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">📝 Gerar Contrato</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Funcionário: <strong>' . h((string)$employee['full_name']) . '</strong></div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/hr_employee_profile.php?id=' . $employeeId . '">← Voltar ao Perfil</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Verificar se ZapSign está configurado
if (!$isConfigured) {
    echo '<section class="card col12">';
    echo '<div style="padding:20px;background:#fef3c7;border-left:4px solid #f59e0b;border-radius:8px">';
    echo '<div style="font-size:16px;font-weight:700;color:#92400e;margin-bottom:8px">⚠️ ZapSign não configurado</div>';
    echo '<div style="color:#92400e;margin-bottom:12px">Configure a integração com ZapSign antes de gerar contratos.</div>';
    echo '<a class="btn btnPrimary" href="/zapsign_config.php">Configurar ZapSign</a>';
    echo '</div>';
    echo '</section>';
} else {
    // Formulário de geração de contrato
    echo '<section class="card col12">';
    echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:16px">Novo Contrato</h3>';
    
    if (count($templates) === 0) {
        echo '<div style="padding:20px;background:#fef3c7;border-left:4px solid #f59e0b;border-radius:8px">';
        echo '<div style="font-size:16px;font-weight:700;color:#92400e;margin-bottom:8px">⚠️ Nenhum template disponível</div>';
        echo '<div style="color:#92400e;margin-bottom:12px">Cadastre templates de contratos antes de gerar.</div>';
        echo '<a class="btn btnPrimary" href="/zapsign_config.php">Gerenciar Templates</a>';
        echo '</div>';
    } else {
        echo '<form method="post" action="/hr_contract_send_post.php" style="display:grid;gap:16px;max-width:700px">';
        echo '<input type="hidden" name="employee_id" value="' . $employeeId . '">';
        
        echo '<label>Template de Contrato *<select name="template_id" required onchange="updateTemplateInfo(this.value)">';
        echo '<option value="">Selecione um template...</option>';
        foreach ($templates as $tpl) {
            $typeLabels = ['clt' => 'CLT', 'pj' => 'PJ', 'estagio' => 'Estágio', 'temporario' => 'Temporário', 'autonomo' => 'Autônomo', 'outro' => 'Outro'];
            $typeLabel = $typeLabels[$tpl['template_type']] ?? $tpl['template_type'];
            echo '<option value="' . (int)$tpl['id'] . '" data-description="' . h((string)($tpl['description'] ?? '')) . '">' . h((string)$tpl['name']) . ' (' . $typeLabel . ')</option>';
        }
        echo '</select></label>';
        
        echo '<div id="templateInfo" style="display:none;padding:12px;background:hsl(var(--muted));border-radius:8px"></div>';
        
        echo '<div style="padding:16px;background:hsl(var(--muted));border-radius:8px">';
        echo '<h4 style="font-size:14px;font-weight:700;margin-bottom:12px">Dados do Signatário</h4>';
        
        $defaultEmail = !empty($employee['email']) ? $employee['email'] : '';
        $defaultCpf = !empty($employee['cpf']) ? $employee['cpf'] : '';
        
        echo '<div class="grid" style="gap:12px">';
        echo '<div class="col12"><label>Nome Completo *<input name="signer_name" required value="' . h((string)$employee['full_name']) . '"></label></div>';
        echo '<div class="col6"><label>E-mail *<input type="email" name="signer_email" required value="' . h($defaultEmail) . '" placeholder="email@exemplo.com"></label></div>';
        echo '<div class="col6"><label>CPF *<input name="signer_cpf" required value="' . h($defaultCpf) . '" placeholder="000.000.000-00"></label></div>';
        echo '</div>';
        echo '</div>';
        
        echo '<div style="padding:12px;background:#dbeafe;border-left:4px solid #3b82f6;border-radius:4px">';
        echo '<div style="font-size:13px;color:#1e40af">';
        echo '<strong>ℹ️ Como funciona:</strong><br>';
        echo '1. O contrato será enviado para o e-mail do funcionário<br>';
        echo '2. Ele receberá um link para assinar digitalmente<br>';
        echo '3. Você será notificado quando o contrato for assinado<br>';
        echo '4. O PDF assinado ficará disponível para download';
        echo '</div>';
        echo '</div>';
        
        echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
        echo '<a class="btn" href="/hr_employee_profile.php?id=' . $employeeId . '">Cancelar</a>';
        echo '<button class="btn btnPrimary" type="submit">📤 Enviar Contrato para Assinatura</button>';
        echo '</div>';
        
        echo '</form>';
    }
    
    echo '</section>';
}

// Histórico de contratos
if (count($contracts) > 0) {
    echo '<section class="card col12">';
    echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:16px">Contratos Enviados</h3>';
    
    echo '<div style="display:grid;gap:12px">';
    
    foreach ($contracts as $contract) {
        $statusColors = [
            'pending' => 'background:#fbbf24;color:#000',
            'signed' => 'background:#10b981;color:#fff',
            'expired' => 'background:#6b7280;color:#fff',
            'cancelled' => 'background:#ef4444;color:#fff',
            'error' => 'background:#dc2626;color:#fff'
        ];
        $statusLabels = [
            'pending' => '⏳ Aguardando Assinatura',
            'signed' => '✅ Assinado',
            'expired' => '⏰ Expirado',
            'cancelled' => '❌ Cancelado',
            'error' => '⚠️ Erro'
        ];
        $statusStyle = $statusColors[$contract['zapsign_status']] ?? 'background:#6b7280;color:#fff';
        $statusLabel = $statusLabels[$contract['zapsign_status']] ?? $contract['zapsign_status'];
        
        echo '<div style="padding:16px;border:1px solid hsl(var(--border));border-radius:8px">';
        echo '<div style="display:flex;justify-content:space-between;align-items:start;gap:16px">';
        echo '<div style="flex:1">';
        
        echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">';
        echo '<div style="font-weight:700;font-size:16px">' . h((string)($contract['template_name'] ?? 'Contrato')) . '</div>';
        echo '<span style="padding:4px 10px;' . $statusStyle . ';border-radius:4px;font-size:12px;font-weight:600">' . $statusLabel . '</span>';
        echo '</div>';
        
        echo '<div style="font-size:14px;color:hsl(var(--muted-foreground));margin-bottom:4px">';
        echo 'Enviado em: ' . date('d/m/Y H:i', strtotime($contract['created_at']));
        echo '</div>';
        
        if (!empty($contract['signer_email'])) {
            echo '<div style="font-size:14px;color:hsl(var(--muted-foreground))">Para: ' . h((string)$contract['signer_email']) . '</div>';
        }
        
        if ($contract['zapsign_status'] === 'signed' && !empty($contract['signed_at'])) {
            echo '<div style="font-size:14px;color:#10b981;margin-top:4px">Assinado em: ' . date('d/m/Y H:i', strtotime($contract['signed_at'])) . '</div>';
        }
        
        if (!empty($contract['error_message'])) {
            echo '<div style="margin-top:8px;padding:8px;background:#fee;border-left:3px solid #dc2626;border-radius:4px;font-size:13px;color:#dc2626">' . h((string)$contract['error_message']) . '</div>';
        }
        
        echo '</div>';
        
        echo '<div style="display:flex;gap:8px;flex-direction:column">';
        if ($contract['zapsign_status'] === 'signed' && !empty($contract['pdf_signed_url'])) {
            echo '<a class="btn btnPrimary" href="' . h((string)$contract['pdf_signed_url']) . '" target="_blank" download>⬇️ Baixar PDF</a>';
        }
        if ($contract['zapsign_status'] === 'pending') {
            echo '<form method="post" action="/hr_contract_cancel_post.php" style="display:inline" onsubmit="return confirm(\'Cancelar este contrato?\')">';
            echo '<input type="hidden" name="contract_id" value="' . (int)$contract['id'] . '">';
            echo '<button class="btn" type="submit">Cancelar</button>';
            echo '</form>';
        }
        echo '</div>';
        
        echo '</div>';
        echo '</div>';
    }
    
    echo '</div>';
    
    echo '</section>';
}

echo '</div>';

echo '<script>
const templatesData = ' . json_encode($templates) . ';

function updateTemplateInfo(templateId) {
    const infoDiv = document.getElementById("templateInfo");
    if (!templateId) {
        infoDiv.style.display = "none";
        return;
    }
    
    const template = templatesData.find(t => t.id == templateId);
    if (template && template.description) {
        infoDiv.innerHTML = "<strong>Sobre este template:</strong><br>" + template.description;
        infoDiv.style.display = "block";
    } else {
        infoDiv.style.display = "none";
    }
}
</script>';

view_footer();
