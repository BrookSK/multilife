<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

// Buscar profissionais para adicionar ao grupo
$professionals = db()->query(
    "SELECT u.id, u.name, u.phone, s.name as specialty
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    LEFT JOIN specialties s ON s.id = u.specialty_id
    WHERE u.status = 'active' AND r.slug = 'profissional'
    ORDER BY u.name ASC"
)->fetchAll();

// Buscar especialidades
$specialties = db()->query("SELECT id, name FROM specialties ORDER BY name ASC")->fetchAll();

view_header('Criar Grupo WhatsApp');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<h2>Criar Grupo WhatsApp</h2>';
echo '<p style="color:hsl(var(--muted-foreground))">Crie um grupo no WhatsApp e adicione profissionais automaticamente.</p>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/chat_create_group_post.php">';

echo '<label>Nome do Grupo *<input type="text" name="group_name" required placeholder="Ex: Fisioterapia - São Paulo"></label>';

echo '<label>Descrição<textarea name="description" rows="3" placeholder="Descrição do grupo (opcional)"></textarea></label>';

echo '<div style="margin:20px 0">';
echo '<h3>Adicionar Participantes</h3>';
echo '</div>';

echo '<label>Filtrar por Especialidade<select name="filter_specialty" id="filterSpecialty" onchange="filterProfessionals()">';
echo '<option value="">Todas as especialidades</option>';
foreach ($specialties as $spec) {
    echo '<option value="' . (int)$spec['id'] . '">' . h($spec['name']) . '</option>';
}
echo '</select></label>';

echo '<div style="margin:16px 0">';
echo '<label style="display:flex;align-items:center;gap:8px">';
echo '<input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">';
echo '<span>Selecionar todos</span>';
echo '</label>';
echo '</div>';

echo '<div style="max-height:400px;overflow-y:auto;border:1px solid hsl(var(--border));border-radius:8px;padding:12px">';
foreach ($professionals as $prof) {
    $phone = preg_replace('/\D/', '', $prof['phone'] ?? '');
    if (empty($phone)) continue;
    
    echo '<label style="display:flex;align-items:center;gap:8px;padding:8px;border-bottom:1px solid hsl(var(--border))" class="professional-item" data-specialty="' . (int)($prof['specialty_id'] ?? 0) . '">';
    echo '<input type="checkbox" name="participants[]" value="' . h($phone) . '">';
    echo '<div style="flex:1">';
    echo '<div style="font-weight:600">' . h($prof['name']) . '</div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">';
    echo h($prof['specialty'] ?? 'Sem especialidade') . ' • ' . h($prof['phone'] ?? '');
    echo '</div>';
    echo '</div>';
    echo '</label>';
}
echo '</div>';

echo '<div style="margin-top:20px;display:flex;gap:10px">';
echo '<button type="submit" class="btn btnPrimary">Criar Grupo</button>';
echo '<a href="/chat_web.php" class="btn">Cancelar</a>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

echo '<script>';
echo 'function filterProfessionals(){';
echo '  const specialty=document.getElementById("filterSpecialty").value;';
echo '  const items=document.querySelectorAll(".professional-item");';
echo '  items.forEach(item=>{';
echo '    if(!specialty||item.dataset.specialty===specialty){';
echo '      item.style.display="flex";';
echo '    }else{';
echo '      item.style.display="none";';
echo '      item.querySelector("input").checked=false;';
echo '    }';
echo '  });';
echo '}';
echo 'function toggleSelectAll(checkbox){';
echo '  const items=document.querySelectorAll(".professional-item");';
echo '  items.forEach(item=>{';
echo '    if(item.style.display!=="none"){';
echo '      item.querySelector("input").checked=checkbox.checked;';
echo '    }';
echo '  });';
echo '}';
echo '</script>';

view_footer();
