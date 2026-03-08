<?php
// Aba de Folha de Pagamento

$employeeId = (int)$employee['id'];

// Buscar configuração de salário ativa
$stmt = db()->prepare('SELECT * FROM hr_employee_payroll WHERE employee_id = :employee_id AND is_active = 1 ORDER BY start_date DESC LIMIT 1');
$stmt->execute(['employee_id' => $employeeId]);
$activePayroll = $stmt->fetch();

// Buscar histórico de salários
$stmt = db()->prepare('SELECT * FROM hr_employee_payroll WHERE employee_id = :employee_id ORDER BY start_date DESC');
$stmt->execute(['employee_id' => $employeeId]);
$payrollHistory = $stmt->fetchAll();

// Buscar lançamentos gerados
$stmt = db()->prepare('
    SELECT pe.*, fe.status as financial_status
    FROM hr_payroll_entries pe
    LEFT JOIN financial_entries fe ON fe.id = pe.financial_entry_id
    WHERE pe.employee_id = :employee_id
    ORDER BY pe.reference_month DESC
    LIMIT 12
');
$stmt->execute(['employee_id' => $employeeId]);
$payrollEntries = $stmt->fetchAll();

echo '<div style="display:grid;gap:24px">';

// Card de Salário Atual
echo '<div>';
echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">';
echo '<h3 style="font-size:18px;font-weight:700">💰 Salário Atual</h3>';
if (!$activePayroll) {
    echo '<button class="btn btnPrimary" onclick="showPayrollModal(0)">+ Cadastrar Salário</button>';
} else {
    echo '<button class="btn" onclick="showPayrollModal(' . (int)$activePayroll['id'] . ')">Editar Salário</button>';
}
echo '</div>';

if ($activePayroll) {
    echo '<div class="card" style="background:linear-gradient(135deg, hsl(var(--primary)) 0%, hsl(var(--primary)/0.8) 100%);color:#fff;padding:24px">';
    echo '<div style="font-size:14px;opacity:0.9;margin-bottom:8px">Salário Base Mensal</div>';
    echo '<div style="font-size:36px;font-weight:900;margin-bottom:16px">R$ ' . number_format((float)$activePayroll['base_salary'], 2, ',', '.') . '</div>';
    
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,0.2)">';
    echo '<div><div style="font-size:12px;opacity:0.8">Data de Início</div><div style="font-weight:600;margin-top:4px">' . date('d/m/Y', strtotime($activePayroll['start_date'])) . '</div></div>';
    echo '<div><div style="font-size:12px;opacity:0.8">Dia de Pagamento</div><div style="font-weight:600;margin-top:4px">Todo dia ' . (int)$activePayroll['payment_day'] . '</div></div>';
    echo '<div><div style="font-size:12px;opacity:0.8">Próximo Vencimento</div><div style="font-weight:600;margin-top:4px">' . date('d/m/Y', strtotime($activePayroll['first_payment_due_date'])) . '</div></div>';
    echo '</div>';
    
    if (!empty($activePayroll['notes'])) {
        echo '<div style="margin-top:16px;padding:12px;background:rgba(255,255,255,0.1);border-radius:8px;font-size:14px">' . nl2br(h((string)$activePayroll['notes'])) . '</div>';
    }
    
    echo '</div>';
} else {
    echo '<div style="text-align:center;padding:40px;background:hsl(var(--muted));border-radius:12px">';
    echo '<div style="font-size:48px;margin-bottom:12px">💰</div>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum salário cadastrado</div>';
    echo '<div style="font-size:14px;color:hsl(var(--muted-foreground))">Cadastre o salário para gerar lançamentos automáticos no financeiro.</div>';
    echo '</div>';
}

echo '</div>';

// Lançamentos Gerados
if (count($payrollEntries) > 0) {
    echo '<div>';
    echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:16px">📊 Lançamentos Gerados (Últimos 12 meses)</h3>';
    
    echo '<div style="overflow-x:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Mês Referência</th><th>Valor</th><th>Vencimento</th><th>Status</th><th style="text-align:right">Ações</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($payrollEntries as $entry) {
        $statusColors = [
            'pending' => 'background:#fbbf24;color:#000',
            'generated' => 'background:#3b82f6;color:#fff',
            'paid' => 'background:#10b981;color:#fff',
            'cancelled' => 'background:#6b7280;color:#fff'
        ];
        $statusLabels = [
            'pending' => 'Pendente',
            'generated' => 'Lançado',
            'paid' => 'Pago',
            'cancelled' => 'Cancelado'
        ];
        $statusStyle = $statusColors[$entry['status']] ?? 'background:#6b7280;color:#fff';
        $statusLabel = $statusLabels[$entry['status']] ?? $entry['status'];
        
        $monthYear = DateTime::createFromFormat('Y-m', $entry['reference_month']);
        $monthLabel = $monthYear ? $monthYear->format('m/Y') : $entry['reference_month'];
        
        echo '<tr>';
        echo '<td style="font-weight:600">' . $monthLabel . '</td>';
        echo '<td style="font-weight:600;color:#dc2626">R$ ' . number_format((float)$entry['amount'], 2, ',', '.') . '</td>';
        echo '<td>' . date('d/m/Y', strtotime($entry['due_date'])) . '</td>';
        echo '<td><span style="padding:4px 10px;' . $statusStyle . ';border-radius:4px;font-size:12px;font-weight:600">' . $statusLabel . '</span></td>';
        echo '<td style="text-align:right">';
        
        if ($entry['status'] === 'pending') {
            echo '<form method="post" action="/hr_payroll_generate_entry_post.php" style="display:inline">';
            echo '<input type="hidden" name="entry_id" value="' . (int)$entry['id'] . '">';
            echo '<input type="hidden" name="employee_id" value="' . $employeeId . '">';
            echo '<button class="btn" type="submit">Gerar Lançamento</button>';
            echo '</form>';
        } elseif ($entry['status'] === 'generated' && $entry['financial_entry_id']) {
            echo '<a class="btn" href="/finance_entry_view.php?id=' . (int)$entry['financial_entry_id'] . '">Ver Lançamento</a>';
        }
        
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
    
    echo '</div>';
}

// Histórico de Salários
if (count($payrollHistory) > 1) {
    echo '<div>';
    echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:16px">📜 Histórico de Salários</h3>';
    
    echo '<div style="display:grid;gap:12px">';
    
    foreach ($payrollHistory as $payroll) {
        $isActive = $payroll['is_active'];
        $borderColor = $isActive ? 'hsl(var(--primary))' : 'hsl(var(--border))';
        
        echo '<div style="padding:16px;border:1px solid ' . $borderColor . ';border-radius:8px">';
        echo '<div style="display:flex;justify-content:between;align-items:start;gap:16px">';
        echo '<div style="flex:1">';
        
        echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">';
        echo '<div style="font-weight:700;font-size:18px">R$ ' . number_format((float)$payroll['base_salary'], 2, ',', '.') . '</div>';
        if ($isActive) {
            echo '<span style="padding:2px 8px;background:#10b981;color:#fff;border-radius:4px;font-size:11px;font-weight:600">ATIVO</span>';
        }
        echo '</div>';
        
        echo '<div style="font-size:14px;color:hsl(var(--muted-foreground))">';
        echo 'Início: ' . date('d/m/Y', strtotime($payroll['start_date']));
        if (!empty($payroll['end_date'])) {
            echo ' • Fim: ' . date('d/m/Y', strtotime($payroll['end_date']));
        }
        echo ' • Pagamento dia ' . (int)$payroll['payment_day'];
        echo '</div>';
        
        if (!empty($payroll['notes'])) {
            echo '<div style="margin-top:8px;font-size:13px;color:hsl(var(--muted-foreground))">' . nl2br(h((string)$payroll['notes'])) . '</div>';
        }
        
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    
    echo '</div>';
    echo '</div>';
}

// Botões de navegação
echo '<div style="display:flex;gap:10px;justify-content:flex-end;padding-top:12px;border-top:2px solid hsl(var(--border))">';
echo '<a class="btn" href="/hr_employee_profile.php?id=' . $employeeId . '&tab=contrato">← Anterior</a>';
echo '<a class="btn" href="/hr_employee_profile.php?id=' . $employeeId . '&tab=beneficios">Próximo →</a>';
echo '</div>';

echo '</div>';

// Modal para cadastrar/editar salário
echo '<div id="payrollModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">';
echo '<div class="card" style="max-width:700px;width:90%;max-height:90vh;overflow-y:auto">';
echo '<h3 style="font-size:20px;font-weight:700;margin-bottom:16px" id="payrollModalTitle">Cadastrar Salário</h3>';

echo '<form method="post" action="/hr_payroll_save_post.php" style="display:grid;gap:12px">';
echo '<input type="hidden" name="employee_id" value="' . $employeeId . '">';
echo '<input type="hidden" name="payroll_id" id="payrollId" value="0">';

echo '<div style="padding:12px;background:#dbeafe;border-left:4px solid #3b82f6;border-radius:4px;margin-bottom:8px">';
echo '<div style="font-size:13px;color:#1e40af;line-height:1.6">';
echo '<strong>💡 Como funciona:</strong><br>';
echo '• O sistema irá gerar automaticamente lançamentos mensais no financeiro<br>';
echo '• Os lançamentos serão criados como despesas (Contas a Pagar)<br>';
echo '• Você pode gerar manualmente ou configurar geração automática';
echo '</div>';
echo '</div>';

echo '<label>Salário Base Mensal (R$) *<input type="number" step="0.01" min="0" name="base_salary" id="payrollBaseSalary" required placeholder="0.00"></label>';

echo '<div class="grid">';
echo '<div class="col6"><label>Data de Início *<input type="date" name="start_date" id="payrollStartDate" required></label></div>';
echo '<div class="col6"><label>Primeiro Vencimento *<input type="date" name="first_payment_due_date" id="payrollFirstDueDate" required></label></div>';
echo '</div>';

echo '<label>Dia de Pagamento (1-31) *<input type="number" min="1" max="31" name="payment_day" id="payrollPaymentDay" required value="5" placeholder="5"><span class="helpText">Dia do mês em que o salário deve ser pago</span></label>';

echo '<label>Observações<textarea name="notes" id="payrollNotes" rows="3" placeholder="Observações sobre o salário (opcional)"></textarea></label>';

echo '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">';
echo '<button type="button" class="btn" onclick="closePayrollModal()">Cancelar</button>';
echo '<button type="submit" class="btn btnPrimary">Salvar Salário</button>';
echo '</div>';

echo '</form>';
echo '</div>';
echo '</div>';

echo '<script>
const payrollHistoryData = ' . json_encode($payrollHistory) . ';

function showPayrollModal(id) {
    const modal = document.getElementById("payrollModal");
    const title = document.getElementById("payrollModalTitle");
    
    if (id === 0) {
        title.textContent = "Cadastrar Salário";
        document.getElementById("payrollId").value = "0";
        document.getElementById("payrollBaseSalary").value = "";
        document.getElementById("payrollStartDate").value = "";
        document.getElementById("payrollFirstDueDate").value = "";
        document.getElementById("payrollPaymentDay").value = "5";
        document.getElementById("payrollNotes").value = "";
    } else {
        const payroll = payrollHistoryData.find(p => p.id == id);
        if (payroll) {
            title.textContent = "Editar Salário";
            document.getElementById("payrollId").value = payroll.id;
            document.getElementById("payrollBaseSalary").value = payroll.base_salary;
            document.getElementById("payrollStartDate").value = payroll.start_date;
            document.getElementById("payrollFirstDueDate").value = payroll.first_payment_due_date;
            document.getElementById("payrollPaymentDay").value = payroll.payment_day;
            document.getElementById("payrollNotes").value = payroll.notes || "";
        }
    }
    
    modal.style.display = "flex";
}

function closePayrollModal() {
    document.getElementById("payrollModal").style.display = "none";
}

document.getElementById("payrollModal").addEventListener("click", function(e) {
    if (e.target === this) {
        closePayrollModal();
    }
});
</script>';
