<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$id = isset($_GET['id']) ? trim((string)$_GET['id']) : '0';
$tab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : 'cadastro';

$isNew = $id === 'new';
$employee = null;

if (!$isNew) {
    $stmt = db()->prepare('SELECT * FROM hr_employees WHERE id = :id');
    $stmt->execute(['id' => (int)$id]);
    $employee = $stmt->fetch();
    
    if (!$employee) {
        flash_set('error', 'Funcionário não encontrado.');
        header('Location: /hr_dashboard.php');
        exit;
    }
    
    // Buscar usuário vinculado ao funcionário (se existir)
    $linkedUser = null;
    if (!empty($employee['user_id'])) {
        $stmt = db()->prepare('SELECT id, name, email, is_suspended, suspended_at, suspension_reason FROM users WHERE id = :id');
        $stmt->execute(['id' => (int)$employee['user_id']]);
        $linkedUser = $stmt->fetch();
    }
    
    // Verificar se o usuário atual é admin
    $currentUserId = auth_user_id();
    $isAdmin = $currentUserId ? rbac_user_can($currentUserId, 'admin.settings.manage') : false;
}

$validTabs = ['cadastro', 'contrato', 'folha', 'beneficios', 'dependentes', 'documentos', 'historico'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'cadastro';
}

$pageTitle = $isNew ? 'Novo Funcionário' : 'Perfil - ' . ($employee['full_name'] ?? '');

view_header($pageTitle);

echo '<div class="grid">';

// Header com foto e informações básicas
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">';

// Foto
$photoUrl = !$isNew && !empty($employee['photo_url']) ? h((string)$employee['photo_url']) : '/assets/default-avatar.png';
echo '<div style="width:100px;height:100px;border-radius:50%;overflow:hidden;border:3px solid hsl(var(--primary));flex-shrink:0">';
echo '<img src="' . $photoUrl . '" alt="Foto" style="width:100%;height:100%;object-fit:cover" onerror="this.src=\'/assets/default-avatar.png\'">';
echo '</div>';

// Informações
echo '<div style="flex:1">';
if ($isNew) {
    echo '<div style="font-size:24px;font-weight:900">Novo Funcionário</div>';
    echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Preencha os dados para cadastrar um novo colaborador.</div>';
} else {
    echo '<div style="font-size:24px;font-weight:900">' . h((string)$employee['full_name']) . '</div>';
    $position = !empty($employee['position']) ? h((string)$employee['position']) : 'Sem cargo';
    $department = !empty($employee['department']) ? ' - ' . h((string)$employee['department']) : '';
    echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">' . $position . $department . '</div>';
    
    // Status badge
    $statusColors = [
        'active' => 'background:#10b981;color:#fff',
        'inactive' => 'background:#fbbf24;color:#000',
        'terminated' => 'background:#ef4444;color:#fff'
    ];
    $statusLabels = [
        'active' => 'ATIVO',
        'inactive' => 'INATIVO',
        'terminated' => 'DESLIGADO'
    ];
    $statusStyle = $statusColors[$employee['status']] ?? 'background:#6b7280;color:#fff';
    $statusLabel = $statusLabels[$employee['status']] ?? strtoupper($employee['status']);
    echo '<div style="margin-top:8px"><span style="display:inline-block;padding:4px 12px;' . $statusStyle . ';border-radius:4px;font-size:12px;font-weight:700">' . $statusLabel . '</span></div>';
}
echo '</div>';

// Botões
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
if (!$isNew) {
    echo '<a class="btn btnPrimary" href="/hr_contract_generate.php?employee_id=' . (int)$employee['id'] . '" style="background:#10b981">📝 Gerar Contrato</a>';
    
    // Botão de suspender/desbloquear (apenas para admins e se houver usuário vinculado)
    if ($isAdmin && $linkedUser) {
        if ($linkedUser['is_suspended']) {
            echo '<form method="post" action="/hr_user_toggle_suspension_post.php" style="display:inline">';
            echo '<input type="hidden" name="user_id" value="' . (int)$linkedUser['id'] . '">';
            echo '<input type="hidden" name="employee_id" value="' . (int)$employee['id'] . '">';
            echo '<input type="hidden" name="action" value="unsuspend">';
            echo '<button class="btn" type="submit" style="background:#10b981;color:#fff" onclick="return confirm(\'Desbloquear acesso deste usuário?\')">🔓 Desbloquear Acesso</button>';
            echo '</form>';
        } else {
            echo '<button class="btn" onclick="showSuspensionModal()" style="background:#ef4444;color:#fff">🔒 Suspender Acesso</button>';
        }
    }
}
echo '<a class="btn" href="/hr_dashboard.php">← Voltar</a>';
echo '</div>';

echo '</div>';
echo '</section>';

// Navegação por abas
if (!$isNew) {
    echo '<section class="card col12" style="padding:0;overflow:hidden">';
    echo '<div style="display:flex;border-bottom:2px solid hsl(var(--border));overflow-x:auto">';
    
    $tabs = [
        'cadastro' => ['label' => 'Cadastro', 'icon' => '📋'],
        'contrato' => ['label' => 'Contrato', 'icon' => '📄'],
        'folha' => ['label' => 'Folha de Pagamento', 'icon' => '💰'],
        'beneficios' => ['label' => 'Benefícios', 'icon' => '🎁'],
        'dependentes' => ['label' => 'Dependentes', 'icon' => '👨‍👩‍👧‍👦'],
        'documentos' => ['label' => 'Documentos', 'icon' => '📁'],
        'historico' => ['label' => 'Histórico', 'icon' => '📊']
    ];
    
    foreach ($tabs as $tabKey => $tabInfo) {
        $isActive = $tab === $tabKey;
        $activeStyle = $isActive ? 'border-bottom:3px solid hsl(var(--primary));color:hsl(var(--primary));font-weight:700' : 'border-bottom:3px solid transparent';
        echo '<a href="/hr_employee_profile.php?id=' . urlencode($id) . '&tab=' . $tabKey . '" style="padding:16px 24px;text-decoration:none;color:inherit;white-space:nowrap;transition:all 0.2s;' . $activeStyle . '" onmouseover="if(this.style.borderBottomColor===\'transparent\')this.style.backgroundColor=\'hsl(var(--muted))\'" onmouseout="this.style.backgroundColor=\'transparent\'">';
        echo '<span style="margin-right:8px">' . $tabInfo['icon'] . '</span>';
        echo $tabInfo['label'];
        echo '</a>';
    }
    
    echo '</div>';
    echo '</section>';
}

// Conteúdo da aba selecionada
echo '<section class="card col12">';

if ($isNew || $tab === 'cadastro') {
    include __DIR__ . '/hr_employee_tab_cadastro.php';
} elseif ($tab === 'contrato') {
    include __DIR__ . '/hr_employee_tab_contrato.php';
} elseif ($tab === 'folha') {
    include __DIR__ . '/hr_employee_tab_folha.php';
} elseif ($tab === 'beneficios') {
    include __DIR__ . '/hr_employee_tab_beneficios.php';
} elseif ($tab === 'dependentes') {
    include __DIR__ . '/hr_employee_tab_dependentes.php';
} elseif ($tab === 'documentos') {
    include __DIR__ . '/hr_employee_tab_documentos.php';
} elseif ($tab === 'historico') {
    include __DIR__ . '/hr_employee_tab_historico.php';
}

echo '</section>';

echo '</div>';

// Modal de suspensão (apenas para admins)
if (!$isNew && $isAdmin && $linkedUser && !$linkedUser['is_suspended']) {
    echo '<div id="suspensionModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">';
    echo '<div class="card" style="max-width:600px;width:90%;max-height:90vh;overflow-y:auto">';
    echo '<h3 style="font-size:20px;font-weight:700;margin-bottom:16px;color:#dc2626">🔒 Suspender Acesso do Usuário</h3>';
    
    echo '<div style="padding:12px;background:#fee;border-left:4px solid #dc2626;border-radius:4px;margin-bottom:16px">';
    echo '<div style="font-size:14px;color:#dc2626;line-height:1.6">';
    echo '<strong>⚠️ Atenção:</strong> Esta ação irá:<br>';
    echo '• Impedir que o usuário faça login no sistema<br>';
    echo '• Realizar logout imediato se estiver conectado<br>';
    echo '• Bloquear acesso até que um admin desbloqueie';
    echo '</div>';
    echo '</div>';
    
    echo '<form method="post" action="/hr_user_toggle_suspension_post.php" style="display:grid;gap:12px">';
    echo '<input type="hidden" name="user_id" value="' . (int)$linkedUser['id'] . '">';
    echo '<input type="hidden" name="employee_id" value="' . (int)$employee['id'] . '">';
    echo '<input type="hidden" name="action" value="suspend">';
    
    echo '<div style="padding:12px;background:hsl(var(--muted));border-radius:8px">';
    echo '<div style="font-weight:600;margin-bottom:8px">Usuário: ' . h((string)$linkedUser['name']) . '</div>';
    echo '<div style="font-size:14px;color:hsl(var(--muted-foreground))">E-mail: ' . h((string)$linkedUser['email']) . '</div>';
    echo '</div>';
    
    echo '<label>Motivo da Suspensão *<textarea name="suspension_reason" required rows="4" placeholder="Descreva o motivo da suspensão (obrigatório)"></textarea></label>';
    
    echo '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">';
    echo '<button type="button" class="btn" onclick="closeSuspensionModal()">Cancelar</button>';
    echo '<button type="submit" class="btn" style="background:#dc2626;color:#fff">Confirmar Suspensão</button>';
    echo '</div>';
    
    echo '</form>';
    echo '</div>';
    echo '</div>';
    
    echo '<script>
    function showSuspensionModal() {
        document.getElementById("suspensionModal").style.display = "flex";
    }
    
    function closeSuspensionModal() {
        document.getElementById("suspensionModal").style.display = "none";
    }
    
    document.getElementById("suspensionModal").addEventListener("click", function(e) {
        if (e.target === this) {
            closeSuspensionModal();
        }
    });
    </script>';
}

// Alerta de usuário suspenso
if (!$isNew && $linkedUser && $linkedUser['is_suspended']) {
    echo '<section class="card col12" style="background:#fee;border:2px solid #dc2626">';
    echo '<div style="padding:20px">';
    echo '<div style="display:flex;align-items:start;gap:16px">';
    echo '<div style="font-size:32px">🔒</div>';
    echo '<div style="flex:1">';
    echo '<div style="font-size:18px;font-weight:700;color:#dc2626;margin-bottom:8px">Acesso Suspenso</div>';
    echo '<div style="color:#7f1d1d;margin-bottom:12px">Este usuário está suspenso e não pode fazer login no sistema.</div>';
    
    if (!empty($linkedUser['suspended_at'])) {
        echo '<div style="font-size:14px;color:#7f1d1d;margin-bottom:4px">Suspenso em: ' . date('d/m/Y H:i', strtotime($linkedUser['suspended_at'])) . '</div>';
    }
    
    if (!empty($linkedUser['suspension_reason'])) {
        echo '<div style="margin-top:12px;padding:12px;background:#fff;border-left:4px solid #dc2626;border-radius:4px">';
        echo '<div style="font-weight:600;font-size:14px;margin-bottom:4px;color:#dc2626">Motivo:</div>';
        echo '<div style="color:#7f1d1d">' . nl2br(h((string)$linkedUser['suspension_reason'])) . '</div>';
        echo '</div>';
    }
    
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

view_footer();
