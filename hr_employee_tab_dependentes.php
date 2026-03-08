<?php
// Aba de Dependentes

$employeeId = (int)$employee['id'];

// Buscar dependentes cadastrados
$stmt = db()->prepare('SELECT * FROM hr_employee_dependents WHERE employee_id = :employee_id ORDER BY full_name ASC');
$stmt->execute(['employee_id' => $employeeId]);
$dependents = $stmt->fetchAll();

echo '<div style="display:grid;gap:24px">';

// Header com botão de adicionar
echo '<div style="display:flex;justify-content:space-between;align-items:center">';
echo '<h3 style="font-size:18px;font-weight:700">Dependentes Cadastrados</h3>';
echo '<button class="btn btnPrimary" onclick="showDependentModal(0)">+ Adicionar Dependente</button>';
echo '</div>';

// Lista de dependentes
if (count($dependents) > 0) {
    echo '<div style="display:grid;gap:12px">';
    
    foreach ($dependents as $dep) {
        $relationshipLabels = [
            'filho' => '👦 Filho',
            'filha' => '👧 Filha',
            'conjuge' => '💑 Cônjuge',
            'pai' => '👨 Pai',
            'mae' => '👩 Mãe',
            'outro' => '👤 Outro'
        ];
        $relationshipLabel = $relationshipLabels[$dep['relationship']] ?? '👤 ' . $dep['relationship'];
        
        $badges = [];
        if ($dep['is_ir_dependent']) $badges[] = '<span style="padding:2px 8px;background:#10b981;color:#fff;border-radius:4px;font-size:11px;font-weight:600">IR</span>';
        if ($dep['is_health_plan_dependent']) $badges[] = '<span style="padding:2px 8px;background:#3b82f6;color:#fff;border-radius:4px;font-size:11px;font-weight:600">PLANO SAÚDE</span>';
        
        echo '<div class="card" style="padding:16px">';
        echo '<div style="display:flex;justify-content:space-between;align-items:start;gap:16px">';
        echo '<div style="flex:1">';
        echo '<div style="font-weight:700;font-size:16px;margin-bottom:4px">' . h((string)$dep['full_name']) . '</div>';
        echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;margin-bottom:8px">' . $relationshipLabel;
        if (!empty($dep['birth_date'])) {
            $birthDate = new DateTime($dep['birth_date']);
            $now = new DateTime();
            $age = $now->diff($birthDate)->y;
            echo ' • ' . $age . ' anos';
        }
        echo '</div>';
        if (!empty($dep['cpf'])) {
            echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">CPF: ' . h((string)$dep['cpf']) . '</div>';
        }
        if (count($badges) > 0) {
            echo '<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">' . implode('', $badges) . '</div>';
        }
        echo '</div>';
        echo '<div style="display:flex;gap:8px">';
        echo '<button class="btn" onclick="showDependentModal(' . (int)$dep['id'] . ')">Editar</button>';
        echo '<form method="post" action="/hr_employee_delete_dependent_post.php" style="display:inline" onsubmit="return confirm(\'Excluir este dependente?\')">';
        echo '<input type="hidden" name="dependent_id" value="' . (int)$dep['id'] . '">';
        echo '<input type="hidden" name="employee_id" value="' . $employeeId . '">';
        echo '<button class="btn" type="submit">Excluir</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    
    echo '</div>';
} else {
    echo '<div style="text-align:center;padding:40px;background:hsl(var(--muted));border-radius:12px">';
    echo '<div style="font-size:48px;margin-bottom:12px">👨‍👩‍👧‍👦</div>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum dependente cadastrado</div>';
    echo '<div style="font-size:14px;color:hsl(var(--muted-foreground))">Adicione dependentes para IR e plano de saúde.</div>';
    echo '</div>';
}

// Botões de navegação
echo '<div style="display:flex;gap:10px;justify-content:flex-end;padding-top:12px;border-top:2px solid hsl(var(--border))">';
echo '<a class="btn" href="/hr_employee_profile.php?id=' . $employeeId . '&tab=beneficios">← Anterior</a>';
echo '<a class="btn" href="/hr_employee_profile.php?id=' . $employeeId . '&tab=documentos">Próximo →</a>';
echo '</div>';

echo '</div>';

// Modal para adicionar/editar dependente
echo '<div id="dependentModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">';
echo '<div class="card" style="max-width:600px;width:90%;max-height:90vh;overflow-y:auto">';
echo '<h3 style="font-size:20px;font-weight:700;margin-bottom:16px" id="modalTitle">Adicionar Dependente</h3>';

echo '<form method="post" action="/hr_employee_save_dependent_post.php" style="display:grid;gap:12px">';
echo '<input type="hidden" name="employee_id" value="' . $employeeId . '">';
echo '<input type="hidden" name="dependent_id" id="dependentId" value="0">';

echo '<label>Nome Completo *<input name="full_name" id="dependentFullName" required maxlength="160" placeholder="Nome completo do dependente"></label>';

echo '<div class="grid">';
echo '<div class="col6"><label>CPF<input name="cpf" id="dependentCpf" maxlength="20" placeholder="000.000.000-00"></label></div>';
echo '<div class="col6"><label>Data de Nascimento<input type="date" name="birth_date" id="dependentBirthDate"></label></div>';
echo '</div>';

echo '<label>Grau de Parentesco *<select name="relationship" id="dependentRelationship" required>';
echo '<option value="">Selecione...</option>';
echo '<option value="filho">Filho</option>';
echo '<option value="filha">Filha</option>';
echo '<option value="conjuge">Cônjuge</option>';
echo '<option value="pai">Pai</option>';
echo '<option value="mae">Mãe</option>';
echo '<option value="outro">Outro</option>';
echo '</select></label>';

echo '<div style="padding:12px;background:hsl(var(--muted));border-radius:8px">';
echo '<label style="display:flex;align-items:center;gap:12px;cursor:pointer;margin-bottom:8px">';
echo '<input type="checkbox" name="is_ir_dependent" id="dependentIsIr" value="1" style="width:18px;height:18px">';
echo '<span>Dependente para Imposto de Renda</span>';
echo '</label>';
echo '<label style="display:flex;align-items:center;gap:12px;cursor:pointer">';
echo '<input type="checkbox" name="is_health_plan_dependent" id="dependentIsHealth" value="1" style="width:18px;height:18px">';
echo '<span>Dependente para Plano de Saúde</span>';
echo '</label>';
echo '</div>';

echo '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">';
echo '<button type="button" class="btn" onclick="closeDependentModal()">Cancelar</button>';
echo '<button type="submit" class="btn btnPrimary">Salvar Dependente</button>';
echo '</div>';

echo '</form>';
echo '</div>';
echo '</div>';

// JavaScript para controlar o modal
echo '<script>
const dependentsData = ' . json_encode($dependents) . ';

function showDependentModal(id) {
    const modal = document.getElementById("dependentModal");
    const title = document.getElementById("modalTitle");
    
    if (id === 0) {
        title.textContent = "Adicionar Dependente";
        document.getElementById("dependentId").value = "0";
        document.getElementById("dependentFullName").value = "";
        document.getElementById("dependentCpf").value = "";
        document.getElementById("dependentBirthDate").value = "";
        document.getElementById("dependentRelationship").value = "";
        document.getElementById("dependentIsIr").checked = false;
        document.getElementById("dependentIsHealth").checked = false;
    } else {
        const dep = dependentsData.find(d => d.id == id);
        if (dep) {
            title.textContent = "Editar Dependente";
            document.getElementById("dependentId").value = dep.id;
            document.getElementById("dependentFullName").value = dep.full_name;
            document.getElementById("dependentCpf").value = dep.cpf || "";
            document.getElementById("dependentBirthDate").value = dep.birth_date || "";
            document.getElementById("dependentRelationship").value = dep.relationship;
            document.getElementById("dependentIsIr").checked = dep.is_ir_dependent == 1;
            document.getElementById("dependentIsHealth").checked = dep.is_health_plan_dependent == 1;
        }
    }
    
    modal.style.display = "flex";
}

function closeDependentModal() {
    document.getElementById("dependentModal").style.display = "none";
}

// Fechar modal ao clicar fora
document.getElementById("dependentModal").addEventListener("click", function(e) {
    if (e.target === this) {
        closeDependentModal();
    }
});
</script>';
