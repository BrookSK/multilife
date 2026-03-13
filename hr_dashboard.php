<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$status = isset($_GET['status']) ? (string)$_GET['status'] : 'active';

if (!in_array($status, ['', 'active', 'inactive', 'terminated'], true)) {
    $status = 'active';
}

$sql = 'SELECT id, full_name, email, phone, position, department, role_id, photo_url, status, created_at FROM hr_employees WHERE 1=1';
$params = [];

if ($status !== '') {
    $sql .= ' AND status = :status';
    $params['status'] = $status;
}

if ($q !== '') {
    $sql .= ' AND (full_name LIKE :q OR email LIKE :q OR phone LIKE :q OR position LIKE :q OR department LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' ORDER BY full_name ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

view_header('RH - Funcionários');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Recursos Humanos</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Gerencie funcionários, contratos, benefícios e documentos.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/hr_employee_profile.php?id=new">+ Novo Funcionário</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/hr_dashboard.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="min-width:180px">';
echo '<option value=""' . ($status === '' ? ' selected' : '') . '>Todos os status</option>';
echo '<option value="active"' . ($status === 'active' ? ' selected' : '') . '>Ativos</option>';
echo '<option value="inactive"' . ($status === 'inactive' ? ' selected' : '') . '>Inativos</option>';
echo '<option value="terminated"' . ($status === 'terminated' ? ' selected' : '') . '>Desligados</option>';
echo '</select>';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar funcionário..." style="flex:1;min-width:240px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

// Cards de funcionários
if (count($employees) > 0) {
    echo '<div class="col12" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:20px;margin-top:10px">';
    
    foreach ($employees as $emp) {
        $fullName = h((string)$emp['full_name']);
        $position = !empty($emp['position']) ? h((string)$emp['position']) : 'Sem cargo';
        $department = !empty($emp['department']) ? h((string)$emp['department']) : '';
        $statusBadge = '';
        
        if ($emp['status'] === 'inactive') {
            $statusBadge = '<span style="display:inline-block;padding:2px 8px;background:#fbbf24;color:#000;border-radius:4px;font-size:11px;font-weight:600;margin-top:4px">INATIVO</span>';
        } elseif ($emp['status'] === 'terminated') {
            $statusBadge = '<span style="display:inline-block;padding:2px 8px;background:#ef4444;color:#fff;border-radius:4px;font-size:11px;font-weight:600;margin-top:4px">DESLIGADO</span>';
        }
        
        echo '<a href="/hr_employee_profile.php?id=' . (int)$emp['id'] . '" style="text-decoration:none;color:inherit">';
        echo '<div class="card" style="padding:20px;text-align:center;cursor:pointer;transition:all 0.2s;border:2px solid transparent" onmouseover="this.style.borderColor=\'hsl(var(--primary))\';this.style.transform=\'translateY(-4px)\'" onmouseout="this.style.borderColor=\'transparent\';this.style.transform=\'translateY(0)\';">';
        
        // Foto circular - usar placeholder inline quando não houver foto
        echo '<div style="width:100px;height:100px;border-radius:50%;overflow:hidden;margin:0 auto 12px;border:3px solid hsl(var(--primary))">';
        if (!empty($emp['photo_url'])) {
            $photoUrl = h((string)$emp['photo_url']);
            echo '<img src="' . $photoUrl . '" alt="' . $fullName . '" style="width:100%;height:100%;object-fit:cover" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">';
            echo '<div style="display:none;width:100%;height:100%;background:#e5e7eb;align-items:center;justify-content:center;font-size:48px;color:#9ca3af">👤</div>';
        } else {
            echo '<div style="display:flex;width:100%;height:100%;background:#e5e7eb;align-items:center;justify-content:center;font-size:48px;color:#9ca3af">👤</div>';
        }
        echo '</div>';
        
        // Nome em negrito
        echo '<div style="font-weight:700;font-size:16px;margin-bottom:4px;line-height:1.3">' . $fullName . '</div>';
        
        // Cargo
        echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;margin-bottom:2px">' . $position . '</div>';
        
        // Departamento
        if ($department !== '') {
            echo '<div style="color:hsl(var(--muted-foreground));font-size:12px">' . $department . '</div>';
        }
        
        // Badge de status
        if ($statusBadge !== '') {
            echo $statusBadge;
        }
        
        echo '</div>';
        echo '</a>';
    }
    
    echo '</div>';
} else {
    echo '<section class="card col12">';
    echo '<div style="text-align:center;padding:40px;color:hsl(var(--muted-foreground))">';
    echo '<div style="font-size:48px;margin-bottom:12px">👥</div>';
    echo '<div style="font-size:18px;font-weight:600;margin-bottom:8px">Nenhum funcionário encontrado</div>';
    echo '<div style="font-size:14px">Cadastre o primeiro funcionário para começar.</div>';
    echo '</div>';
    echo '</section>';
}

echo '</div>';

view_footer();
