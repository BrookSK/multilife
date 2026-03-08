<?php
// Aba de Histórico - Registro de alterações e eventos

$employeeId = (int)$employee['id'];

// Buscar histórico do funcionário
$stmt = db()->prepare('
    SELECT h.*, u.name as created_by_name
    FROM hr_employee_history h
    LEFT JOIN users u ON u.id = h.created_by_user_id
    WHERE h.employee_id = :employee_id
    ORDER BY h.change_date DESC, h.created_at DESC
');
$stmt->execute(['employee_id' => $employeeId]);
$history = $stmt->fetchAll();

echo '<div style="display:grid;gap:24px">';

// Header com botão de adicionar
echo '<div style="display:flex;justify-content:space-between;align-items:center">';
echo '<h3 style="font-size:18px;font-weight:700">Histórico de Alterações</h3>';
echo '<button class="btn btnPrimary" onclick="showHistoryModal()">+ Adicionar Evento</button>';
echo '</div>';

// Timeline de histórico
if (count($history) > 0) {
    echo '<div style="position:relative;padding-left:40px">';
    echo '<div style="position:absolute;left:16px;top:0;bottom:0;width:2px;background:hsl(var(--border))"></div>';
    
    foreach ($history as $item) {
        $typeIcons = [
            'admissao' => '🎉',
            'promocao' => '📈',
            'transferencia' => '🔄',
            'aumento' => '💰',
            'afastamento' => '🏥',
            'retorno' => '↩️',
            'desligamento' => '👋',
            'outro' => '📝'
        ];
        $icon = $typeIcons[$item['change_type']] ?? '📝';
        
        $typeLabels = [
            'admissao' => 'Admissão',
            'promocao' => 'Promoção',
            'transferencia' => 'Transferência',
            'aumento' => 'Aumento Salarial',
            'afastamento' => 'Afastamento',
            'retorno' => 'Retorno',
            'desligamento' => 'Desligamento',
            'outro' => 'Outro'
        ];
        $typeLabel = $typeLabels[$item['change_type']] ?? 'Evento';
        
        echo '<div style="position:relative;margin-bottom:24px">';
        echo '<div style="position:absolute;left:-32px;width:24px;height:24px;background:hsl(var(--primary));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px">' . $icon . '</div>';
        
        echo '<div class="card" style="padding:16px">';
        echo '<div style="display:flex;justify-content:space-between;align-items:start;gap:16px;margin-bottom:8px">';
        echo '<div>';
        echo '<div style="font-weight:700;font-size:16px">' . $typeLabel . '</div>';
        echo '<div style="font-size:14px;color:hsl(var(--muted-foreground));margin-top:4px">' . date('d/m/Y', strtotime($item['change_date'])) . '</div>';
        echo '</div>';
        echo '<form method="post" action="/hr_employee_delete_history_post.php" style="display:inline" onsubmit="return confirm(\'Excluir este evento?\')">';
        echo '<input type="hidden" name="history_id" value="' . (int)$item['id'] . '">';
        echo '<input type="hidden" name="employee_id" value="' . $employeeId . '">';
        echo '<button class="btn" type="submit" style="padding:4px 8px;font-size:12px">Excluir</button>';
        echo '</form>';
        echo '</div>';
        
        echo '<div style="margin-bottom:8px">' . nl2br(h((string)$item['description'])) . '</div>';
        
        if (!empty($item['old_value']) || !empty($item['new_value'])) {
            echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;padding:12px;background:hsl(var(--muted));border-radius:8px">';
            if (!empty($item['old_value'])) {
                echo '<div><div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:4px">Valor Anterior</div><div style="font-weight:600">' . h((string)$item['old_value']) . '</div></div>';
            }
            if (!empty($item['new_value'])) {
                echo '<div><div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:4px">Novo Valor</div><div style="font-weight:600">' . h((string)$item['new_value']) . '</div></div>';
            }
            echo '</div>';
        }
        
        if (!empty($item['created_by_name'])) {
            echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:8px">Registrado por ' . h((string)$item['created_by_name']) . '</div>';
        }
        
        echo '</div>';
        echo '</div>';
    }
    
    echo '</div>';
} else {
    echo '<div style="text-align:center;padding:40px;background:hsl(var(--muted));border-radius:12px">';
    echo '<div style="font-size:48px;margin-bottom:12px">📊</div>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum evento registrado</div>';
    echo '<div style="font-size:14px;color:hsl(var(--muted-foreground))">Adicione eventos importantes como promoções, transferências e afastamentos.</div>';
    echo '</div>';
}

// Botão de navegação
echo '<div style="display:flex;gap:10px;justify-content:flex-end;padding-top:12px;border-top:2px solid hsl(var(--border))">';
echo '<a class="btn" href="/hr_employee_profile.php?id=' . $employeeId . '&tab=documentos">← Anterior</a>';
echo '</div>';

echo '</div>';

// Modal para adicionar evento
echo '<div id="historyModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">';
echo '<div class="card" style="max-width:600px;width:90%;max-height:90vh;overflow-y:auto">';
echo '<h3 style="font-size:20px;font-weight:700;margin-bottom:16px">Adicionar Evento ao Histórico</h3>';

echo '<form method="post" action="/hr_employee_save_history_post.php" style="display:grid;gap:12px">';
echo '<input type="hidden" name="employee_id" value="' . $employeeId . '">';

echo '<label>Tipo de Evento *<select name="change_type" required>';
echo '<option value="">Selecione...</option>';
echo '<option value="admissao">Admissão</option>';
echo '<option value="promocao">Promoção</option>';
echo '<option value="transferencia">Transferência</option>';
echo '<option value="aumento">Aumento Salarial</option>';
echo '<option value="afastamento">Afastamento</option>';
echo '<option value="retorno">Retorno</option>';
echo '<option value="desligamento">Desligamento</option>';
echo '<option value="outro">Outro</option>';
echo '</select></label>';

echo '<label>Data do Evento *<input type="date" name="change_date" required></label>';

echo '<label>Descrição *<textarea name="description" required rows="4" placeholder="Descreva o evento em detalhes"></textarea></label>';

echo '<div class="grid">';
echo '<div class="col6"><label>Valor Anterior<input name="old_value" maxlength="255" placeholder="Ex: Analista Jr"></label></div>';
echo '<div class="col6"><label>Novo Valor<input name="new_value" maxlength="255" placeholder="Ex: Analista Pleno"></label></div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">';
echo '<button type="button" class="btn" onclick="closeHistoryModal()">Cancelar</button>';
echo '<button type="submit" class="btn btnPrimary">Salvar Evento</button>';
echo '</div>';

echo '</form>';
echo '</div>';
echo '</div>';

echo '<script>
function showHistoryModal() {
    document.getElementById("historyModal").style.display = "flex";
}

function closeHistoryModal() {
    document.getElementById("historyModal").style.display = "none";
}

document.getElementById("historyModal").addEventListener("click", function(e) {
    if (e.target === this) {
        closeHistoryModal();
    }
});
</script>';
