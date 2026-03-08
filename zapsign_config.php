<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

// Buscar configuração atual
$stmt = db()->query('SELECT * FROM zapsign_config LIMIT 1');
$config = $stmt->fetch();

if (!$config) {
    // Criar configuração padrão se não existir
    db()->exec("INSERT INTO zapsign_config (api_token, sandbox_mode) VALUES ('', 1)");
    $config = db()->query('SELECT * FROM zapsign_config LIMIT 1')->fetch();
}

// Buscar templates
$templates = db()->query('SELECT * FROM zapsign_contract_templates ORDER BY name ASC')->fetchAll();

view_header('Configuração ZapSign');

echo '<div class="grid">';

// Card de Configuração da API
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Configuração ZapSign</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Configure a integração com ZapSign para assinatura digital de contratos.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_settings.php">← Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:20px"></div>';

echo '<form method="post" action="/zapsign_config_save_post.php" style="display:grid;gap:16px;max-width:800px">';

echo '<div style="padding:16px;background:hsl(var(--muted));border-radius:8px">';
echo '<h3 style="font-size:16px;font-weight:700;margin-bottom:12px">🔑 Credenciais da API</h3>';

echo '<label>Token da API ZapSign *<input name="api_token" required value="' . h((string)$config['api_token']) . '" placeholder="Cole aqui o token da API do ZapSign" style="font-family:monospace"></label>';

echo '<div style="margin-top:12px">';
echo '<label style="display:flex;align-items:center;gap:12px;cursor:pointer">';
$checked = $config['sandbox_mode'] ? ' checked' : '';
echo '<input type="checkbox" name="sandbox_mode" value="1"' . $checked . ' style="width:18px;height:18px">';
echo '<div>';
echo '<div style="font-weight:600">Modo Sandbox (Teste)</div>';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-top:2px">Ative para testar sem enviar documentos reais</div>';
echo '</div>';
echo '</label>';
echo '</div>';

echo '<div style="margin-top:16px;padding:12px;background:#fef3c7;border-left:4px solid #f59e0b;border-radius:4px">';
echo '<div style="font-size:13px;color:#92400e">';
echo '<strong>💡 Como obter o Token:</strong><br>';
echo '1. Acesse <a href="https://app.zapsign.com.br" target="_blank" style="color:#b45309;text-decoration:underline">app.zapsign.com.br</a><br>';
echo '2. Vá em Configurações → API<br>';
echo '3. Copie o Token de API';
echo '</div>';
echo '</div>';

echo '</div>';

echo '<div style="padding:16px;background:hsl(var(--muted));border-radius:8px">';
echo '<h3 style="font-size:16px;font-weight:700;margin-bottom:12px">🔔 Webhook (Recomendado)</h3>';

$webhookUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/zapsign_webhook.php';
echo '<label>URL do Webhook<input name="webhook_url" value="' . h($webhookUrl) . '" readonly style="font-family:monospace;background:#f0f0f0"></label>';

echo '<div style="margin-top:12px;padding:12px;background:#dbeafe;border-left:4px solid #3b82f6;border-radius:4px">';
echo '<div style="font-size:13px;color:#1e40af;line-height:1.6">';
echo '<strong>🔄 Acompanhamento Automático:</strong><br>';
echo '• O sistema receberá notificações automáticas do ZapSign<br>';
echo '• Status dos contratos será atualizado em tempo real<br>';
echo '• Você será notificado quando um contrato for assinado<br>';
echo '• Histórico do funcionário será atualizado automaticamente<br><br>';
echo '<strong>📋 Como configurar no ZapSign:</strong><br>';
echo '1. Acesse <a href="https://app.zapsign.com.br" target="_blank" style="color:#2563eb;text-decoration:underline">app.zapsign.com.br</a><br>';
echo '2. Vá em Configurações → Webhooks<br>';
echo '3. Cole a URL acima no campo de webhook<br>';
echo '4. Ative os eventos: doc_signed, doc_expired, doc_cancelled<br>';
echo '5. Salve as configurações';
echo '</div>';
echo '</div>';

echo '<div style="margin-top:12px;padding:12px;background:#fef3c7;border-left:4px solid #f59e0b;border-radius:4px">';
echo '<div style="font-size:13px;color:#92400e;line-height:1.6">';
echo '<strong>⚠️ Importante:</strong><br>';
echo '• A URL do webhook deve ser acessível publicamente<br>';
echo '• Certifique-se de que o arquivo zapsign_webhook.php existe<br>';
echo '• Logs são salvos em /logs/zapsign_webhook.log para debug';
echo '</div>';
echo '</div>';

echo '</div>';

echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
echo '<button class="btn btnPrimary" type="submit">💾 Salvar Configurações</button>';
echo '</div>';

echo '</form>';

echo '</section>';

// Card de Templates de Contratos
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:18px;font-weight:700">📄 Templates de Contratos</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Gerencie os modelos de contratos para cada tipo de vínculo.</div>';
echo '</div>';
echo '<button class="btn btnPrimary" onclick="showTemplateModal(0)">+ Novo Template</button>';
echo '</div>';

echo '<div style="height:16px"></div>';

if (count($templates) > 0) {
    echo '<div style="display:grid;gap:12px">';
    
    foreach ($templates as $tpl) {
        $typeLabels = [
            'clt' => 'CLT',
            'pj' => 'PJ',
            'estagio' => 'Estágio',
            'temporario' => 'Temporário',
            'autonomo' => 'Autônomo',
            'outro' => 'Outro'
        ];
        $typeLabel = $typeLabels[$tpl['template_type']] ?? $tpl['template_type'];
        
        $statusBadge = $tpl['is_active'] 
            ? '<span style="padding:4px 10px;background:#10b981;color:#fff;border-radius:4px;font-size:12px;font-weight:600">ATIVO</span>'
            : '<span style="padding:4px 10px;background:#6b7280;color:#fff;border-radius:4px;font-size:12px;font-weight:600">INATIVO</span>';
        
        echo '<div style="padding:16px;border:1px solid hsl(var(--border));border-radius:8px">';
        echo '<div style="display:flex;justify-content:space-between;align-items:start;gap:16px">';
        echo '<div style="flex:1">';
        echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">';
        echo '<div style="font-weight:700;font-size:16px">' . h((string)$tpl['name']) . '</div>';
        echo '<span style="padding:2px 8px;background:hsl(var(--primary));color:#fff;border-radius:4px;font-size:11px;font-weight:600">' . $typeLabel . '</span>';
        echo $statusBadge;
        echo '</div>';
        
        if (!empty($tpl['description'])) {
            echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;margin-bottom:8px">' . h((string)$tpl['description']) . '</div>';
        }
        
        if (!empty($tpl['zapsign_template_token'])) {
            echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));font-family:monospace">Token: ' . h((string)$tpl['zapsign_template_token']) . '</div>';
        }
        
        echo '</div>';
        echo '<div style="display:flex;gap:8px">';
        echo '<button class="btn" onclick="showTemplateModal(' . (int)$tpl['id'] . ')">Editar</button>';
        echo '<form method="post" action="/zapsign_template_delete_post.php" style="display:inline" onsubmit="return confirm(\'Excluir este template?\')">';
        echo '<input type="hidden" name="template_id" value="' . (int)$tpl['id'] . '">';
        echo '<button class="btn" type="submit">Excluir</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    
    echo '</div>';
} else {
    echo '<div style="text-align:center;padding:40px;background:hsl(var(--muted));border-radius:12px">';
    echo '<div style="font-size:48px;margin-bottom:12px">📄</div>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum template cadastrado</div>';
    echo '<div style="font-size:14px;color:hsl(var(--muted-foreground))">Adicione templates de contratos para cada tipo de vínculo.</div>';
    echo '</div>';
}

echo '</section>';

echo '</div>';

// Modal para criar/editar template
echo '<div id="templateModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">';
echo '<div class="card" style="max-width:700px;width:90%;max-height:90vh;overflow-y:auto">';
echo '<h3 style="font-size:20px;font-weight:700;margin-bottom:16px" id="templateModalTitle">Novo Template</h3>';

echo '<form method="post" action="/zapsign_template_save_post.php" enctype="multipart/form-data" style="display:grid;gap:12px">';
echo '<input type="hidden" name="template_id" id="templateId" value="0">';

echo '<label>Nome do Template *<input name="name" id="templateName" required maxlength="160" placeholder="Ex: Contrato CLT Padrão"></label>';

echo '<label>Tipo de Contrato *<select name="template_type" id="templateType" required>';
echo '<option value="">Selecione...</option>';
echo '<option value="clt">CLT</option>';
echo '<option value="pj">PJ (Pessoa Jurídica)</option>';
echo '<option value="estagio">Estágio</option>';
echo '<option value="temporario">Temporário</option>';
echo '<option value="autonomo">Autônomo</option>';
echo '<option value="outro">Outro</option>';
echo '</select></label>';

echo '<label>Descrição<textarea name="description" id="templateDescription" rows="3" placeholder="Descreva quando usar este template"></textarea></label>';

echo '<label>Token do Template ZapSign (Opcional)<input name="zapsign_template_token" id="templateToken" maxlength="255" placeholder="Cole o token do template criado no ZapSign" style="font-family:monospace"></label>';

echo '<div style="padding:12px;background:#dbeafe;border-left:4px solid #3b82f6;border-radius:4px">';
echo '<div style="font-size:13px;color:#1e40af">';
echo '<strong>💡 Dica:</strong> Você pode criar templates diretamente no ZapSign e colar o token aqui, ou fazer upload de um PDF que será enviado como documento.';
echo '</div>';
echo '</div>';

echo '<label>Upload de PDF (Opcional)<input type="file" name="pdf_file" accept=".pdf"></label>';

echo '<label style="display:flex;align-items:center;gap:12px;cursor:pointer">';
echo '<input type="checkbox" name="is_active" id="templateIsActive" value="1" checked style="width:18px;height:18px">';
echo '<span>Template Ativo</span>';
echo '</label>';

echo '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">';
echo '<button type="button" class="btn" onclick="closeTemplateModal()">Cancelar</button>';
echo '<button type="submit" class="btn btnPrimary">Salvar Template</button>';
echo '</div>';

echo '</form>';
echo '</div>';
echo '</div>';

echo '<script>
const templatesData = ' . json_encode($templates) . ';

function showTemplateModal(id) {
    const modal = document.getElementById("templateModal");
    const title = document.getElementById("templateModalTitle");
    
    if (id === 0) {
        title.textContent = "Novo Template";
        document.getElementById("templateId").value = "0";
        document.getElementById("templateName").value = "";
        document.getElementById("templateType").value = "";
        document.getElementById("templateDescription").value = "";
        document.getElementById("templateToken").value = "";
        document.getElementById("templateIsActive").checked = true;
    } else {
        const tpl = templatesData.find(t => t.id == id);
        if (tpl) {
            title.textContent = "Editar Template";
            document.getElementById("templateId").value = tpl.id;
            document.getElementById("templateName").value = tpl.name;
            document.getElementById("templateType").value = tpl.template_type;
            document.getElementById("templateDescription").value = tpl.description || "";
            document.getElementById("templateToken").value = tpl.zapsign_template_token || "";
            document.getElementById("templateIsActive").checked = tpl.is_active == 1;
        }
    }
    
    modal.style.display = "flex";
}

function closeTemplateModal() {
    document.getElementById("templateModal").style.display = "none";
}

document.getElementById("templateModal").addEventListener("click", function(e) {
    if (e.target === this) {
        closeTemplateModal();
    }
});
</script>';

view_footer();
