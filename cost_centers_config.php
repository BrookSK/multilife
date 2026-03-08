<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$db = db();

// Processar ações (criar, editar, ativar/desativar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $color = trim((string)($_POST['color'] ?? '#3b82f6'));
            
            if (empty($name)) {
                throw new Exception('Nome é obrigatório');
            }
            
            $stmt = $db->prepare("INSERT INTO cost_centers (name, description, color, is_active) VALUES (?, ?, ?, 1)");
            $stmt->execute([$name, $description, $color]);
            
            flash_set('success', 'Centro de custo criado com sucesso!');
            
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $color = trim((string)($_POST['color'] ?? '#3b82f6'));
            
            if (empty($name) || $id === 0) {
                throw new Exception('Dados inválidos');
            }
            
            $stmt = $db->prepare("UPDATE cost_centers SET name = ?, description = ?, color = ? WHERE id = ?");
            $stmt->execute([$name, $description, $color, $id]);
            
            flash_set('success', 'Centro de custo atualizado com sucesso!');
            
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id === 0) {
                throw new Exception('ID inválido');
            }
            
            $stmt = $db->prepare("UPDATE cost_centers SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$id]);
            
            flash_set('success', 'Status atualizado com sucesso!');
        }
        
        header('Location: /cost_centers_config.php');
        exit;
        
    } catch (Exception $e) {
        flash_set('error', 'Erro: ' . $e->getMessage());
    }
}

// Buscar centros de custo
$stmt = $db->query("SELECT * FROM cost_centers ORDER BY is_active DESC, name ASC");
$costCenters = $stmt->fetchAll(PDO::FETCH_ASSOC);

view_header('Centros de Custo');

echo '<div class="grid">';

// Cabeçalho
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Centros de Custo</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Gerencie os centros de custo para organização financeira</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px">';
echo '<button class="btn btnPrimary" onclick="document.getElementById(\'modalCreate\').style.display=\'flex\'">Novo Centro de Custo</button>';
echo '<a class="btn" href="/admin_settings.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Lista de centros de custo
echo '<section class="card col12">';
echo '<h3>Centros de Custo (' . count($costCenters) . ')</h3>';

if (empty($costCenters)) {
    echo '<div style="padding:40px;text-align:center;color:#667781">Nenhum centro de custo cadastrado</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Nome</th>';
    echo '<th>Descrição</th>';
    echo '<th>Cor</th>';
    echo '<th>Status</th>';
    echo '<th>Ações</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($costCenters as $cc) {
        $statusColor = $cc['is_active'] ? '#10b981' : '#dc2626';
        $statusText = $cc['is_active'] ? 'Ativo' : 'Inativo';
        
        echo '<tr>';
        echo '<td><strong>' . h($cc['name']) . '</strong></td>';
        echo '<td>' . h($cc['description'] ?? '-') . '</td>';
        echo '<td><div style="display:flex;align-items:center;gap:8px"><div style="width:20px;height:20px;border-radius:4px;background:' . h($cc['color']) . '"></div>' . h($cc['color']) . '</div></td>';
        echo '<td><span style="color:' . $statusColor . ';font-weight:600">' . $statusText . '</span></td>';
        echo '<td>';
        echo '<button class="btn" style="padding:4px 8px;font-size:12px" onclick="editCostCenter(' . $cc['id'] . ', \'' . addslashes($cc['name']) . '\', \'' . addslashes($cc['description'] ?? '') . '\', \'' . $cc['color'] . '\')">Editar</button> ';
        echo '<form method="post" style="display:inline">';
        echo '<input type="hidden" name="action" value="toggle">';
        echo '<input type="hidden" name="id" value="' . $cc['id'] . '">';
        echo '<button type="submit" class="btn" style="padding:4px 8px;font-size:12px">' . ($cc['is_active'] ? 'Desativar' : 'Ativar') . '</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';

echo '</div>';

// Modal de criação
echo '<div id="modalCreate" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;z-index:1000">';
echo '<div style="background:white;border-radius:12px;padding:24px;max-width:500px;width:90%">';
echo '<h3 style="margin-top:0">Novo Centro de Custo</h3>';
echo '<form method="post">';
echo '<input type="hidden" name="action" value="create">';
echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:4px">Nome *</label>';
echo '<input type="text" name="name" required style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px">';
echo '</div>';
echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:4px">Descrição</label>';
echo '<textarea name="description" rows="3" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px"></textarea>';
echo '</div>';
echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:4px">Cor</label>';
echo '<input type="color" name="color" value="#3b82f6" style="width:100%;height:40px;border:1px solid #e5e7eb;border-radius:6px">';
echo '</div>';
echo '<div style="display:flex;gap:8px;justify-content:flex-end">';
echo '<button type="button" class="btn" onclick="document.getElementById(\'modalCreate\').style.display=\'none\'">Cancelar</button>';
echo '<button type="submit" class="btn btnPrimary">Criar</button>';
echo '</div>';
echo '</form>';
echo '</div>';
echo '</div>';

// Modal de edição
echo '<div id="modalEdit" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;z-index:1000">';
echo '<div style="background:white;border-radius:12px;padding:24px;max-width:500px;width:90%">';
echo '<h3 style="margin-top:0">Editar Centro de Custo</h3>';
echo '<form method="post">';
echo '<input type="hidden" name="action" value="update">';
echo '<input type="hidden" name="id" id="editId">';
echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:4px">Nome *</label>';
echo '<input type="text" name="name" id="editName" required style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px">';
echo '</div>';
echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:4px">Descrição</label>';
echo '<textarea name="description" id="editDescription" rows="3" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px"></textarea>';
echo '</div>';
echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:4px">Cor</label>';
echo '<input type="color" name="color" id="editColor" style="width:100%;height:40px;border:1px solid #e5e7eb;border-radius:6px">';
echo '</div>';
echo '<div style="display:flex;gap:8px;justify-content:flex-end">';
echo '<button type="button" class="btn" onclick="document.getElementById(\'modalEdit\').style.display=\'none\'">Cancelar</button>';
echo '<button type="submit" class="btn btnPrimary">Salvar</button>';
echo '</div>';
echo '</form>';
echo '</div>';
echo '</div>';

echo '<script>
function editCostCenter(id, name, description, color) {
    document.getElementById("editId").value = id;
    document.getElementById("editName").value = name;
    document.getElementById("editDescription").value = description;
    document.getElementById("editColor").value = color;
    document.getElementById("modalEdit").style.display = "flex";
}
</script>';

view_footer();
