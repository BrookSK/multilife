<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$userId = auth_user_id();
$currentMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$currentYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Validar mês e ano
if ($currentMonth < 1 || $currentMonth > 12) {
    $currentMonth = (int)date('m');
}
if ($currentYear < 2020 || $currentYear > 2030) {
    $currentYear = (int)date('Y');
}

// Buscar agendamentos do profissional
$appointmentsStmt = db()->prepare("
    SELECT 
        a.id,
        DATE(a.first_at) as appointment_date,
        TIME(a.first_at) as appointment_time,
        a.status,
        a.recurrence_rule as notes,
        p.full_name as patient_name,
        p.phone_primary as patient_phone,
        pa.specialty,
        pa.service_type
    FROM appointments a
    INNER JOIN patients p ON p.id = a.patient_id
    LEFT JOIN patient_assignments pa ON pa.patient_id = p.id AND pa.professional_user_id = ?
    WHERE a.professional_user_id = ?
    AND YEAR(a.first_at) = ?
    AND MONTH(a.first_at) = ?
    ORDER BY a.first_at ASC
");
$appointmentsStmt->execute([$userId, $userId, $currentYear, $currentMonth]);
$appointments = $appointmentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por data
$appointmentsByDate = [];
foreach ($appointments as $apt) {
    $date = $apt['appointment_date'];
    if (!isset($appointmentsByDate[$date])) {
        $appointmentsByDate[$date] = [];
    }
    $appointmentsByDate[$date][] = $apt;
}

// Calcular dias do mês
$firstDay = mktime(0, 0, 0, $currentMonth, 1, $currentYear);
$daysInMonth = (int)date('t', $firstDay);
$firstDayOfWeek = (int)date('w', $firstDay);

$prevMonth = $currentMonth - 1;
$prevYear = $currentYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $currentMonth + 1;
$nextYear = $currentYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

view_header('Agendamentos');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Meus Agendamentos</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Visualize seus agendamentos mensais</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px">';
echo '<a href="/profissional_agendamentos.php?month=' . $prevMonth . '&year=' . $prevYear . '" class="btn">← Anterior</a>';
echo '<div style="padding:10px 20px;background:#f0f9ff;border-radius:6px;font-weight:700">' . date('F Y', $firstDay) . '</div>';
echo '<a href="/profissional_agendamentos.php?month=' . $nextMonth . '&year=' . $nextYear . '" class="btn">Próximo →</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Resumo do Mês
echo '<section class="card col12">';
echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px">';

$totalAppointments = count($appointments);
$confirmedCount = count(array_filter($appointments, fn($a) => $a['status'] === 'agendado'));
$pendingCount = count(array_filter($appointments, fn($a) => $a['status'] === 'pendente_formulario'));
$completedCount = count(array_filter($appointments, fn($a) => $a['status'] === 'realizado'));

echo '<div style="padding:20px;background:#f0f9ff;border-radius:8px;border-left:4px solid #0284c7">';
echo '<div style="font-size:14px;color:#0369a1;font-weight:600;margin-bottom:8px">Total de Agendamentos</div>';
echo '<div style="font-size:32px;font-weight:700;color:#0c4a6e">' . $totalAppointments . '</div>';
echo '</div>';

echo '<div style="padding:20px;background:#f0fdf4;border-radius:8px;border-left:4px solid #10b981">';
echo '<div style="font-size:14px;color:#059669;font-weight:600;margin-bottom:8px">Confirmados</div>';
echo '<div style="font-size:32px;font-weight:700;color:#065f46">' . $confirmedCount . '</div>';
echo '</div>';

echo '<div style="padding:20px;background:#fef3c7;border-radius:8px;border-left:4px solid #f59e0b">';
echo '<div style="font-size:14px;color:#d97706;font-weight:600;margin-bottom:8px">Pendentes</div>';
echo '<div style="font-size:32px;font-weight:700;color:#92400e">' . $pendingCount . '</div>';
echo '</div>';

echo '<div style="padding:20px;background:#f3f4f6;border-radius:8px;border-left:4px solid #667781">';
echo '<div style="font-size:14px;color:#4b5563;font-weight:600;margin-bottom:8px">Concluídos</div>';
echo '<div style="font-size:32px;font-weight:700;color:#1f2937">' . $completedCount . '</div>';
echo '</div>';

echo '</div>';
echo '</section>';

// Calendário
echo '<section class="card col12">';
echo '<h3>Calendário</h3>';

echo '<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px">';

// Cabeçalho dos dias da semana
$weekDays = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
foreach ($weekDays as $day) {
    echo '<div style="padding:12px;text-align:center;font-weight:700;color:#667781">' . $day . '</div>';
}

// Dias vazios antes do primeiro dia
for ($i = 0; $i < $firstDayOfWeek; $i++) {
    echo '<div style="padding:12px;background:#f9fafb;border-radius:6px"></div>';
}

// Dias do mês
for ($day = 1; $day <= $daysInMonth; $day++) {
    $date = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $day);
    $hasAppointments = isset($appointmentsByDate[$date]);
    $appointmentCount = $hasAppointments ? count($appointmentsByDate[$date]) : 0;
    $isToday = $date === date('Y-m-d');
    
    $bgColor = $isToday ? '#f0f9ff' : 'white';
    $borderColor = $isToday ? '#0284c7' : '#e5e7eb';
    
    echo '<div style="padding:12px;background:' . $bgColor . ';border:2px solid ' . $borderColor . ';border-radius:6px;min-height:80px">';
    echo '<div style="font-weight:700;margin-bottom:8px">' . $day . '</div>';
    
    if ($hasAppointments) {
        echo '<div style="background:#10b981;color:white;font-size:11px;font-weight:700;padding:4px 8px;border-radius:4px;text-align:center">';
        echo $appointmentCount . ' agend.';
        echo '</div>';
    }
    
    echo '</div>';
}

echo '</div>';
echo '</section>';

// Lista de Agendamentos
echo '<section class="card col12">';
echo '<h3>Lista de Agendamentos</h3>';

if (count($appointments) === 0) {
    echo '<div style="padding:40px;text-align:center;color:#667781">';
    echo '<svg style="width:48px;height:48px;margin:0 auto 16px;opacity:0.3" fill="currentColor" viewBox="0 0 24 24"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum agendamento neste mês</div>';
    echo '<div style="font-size:14px">Seus agendamentos aparecerão aqui</div>';
    echo '</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Data</th><th>Horário</th><th>Paciente</th><th>Telefone</th><th>Especialidade</th><th>Status</th><th>Observações</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($appointments as $apt) {
        $statusColors = [
            'agendado' => '#10b981',
            'pendente_formulario' => '#f59e0b',
            'realizado' => '#667781',
            'atrasado' => '#dc2626',
            'cancelado' => '#dc2626',
            'revisao_admin' => '#0284c7'
        ];
        $statusLabels = [
            'agendado' => 'Agendado',
            'pendente_formulario' => 'Pendente Formulário',
            'realizado' => 'Realizado',
            'atrasado' => 'Atrasado',
            'cancelado' => 'Cancelado',
            'revisao_admin' => 'Em Revisão'
        ];
        $statusColor = $statusColors[$apt['status']] ?? '#667781';
        $statusLabel = $statusLabels[$apt['status']] ?? $apt['status'];
        
        echo '<tr>';
        echo '<td style="font-weight:600">' . date('d/m/Y', strtotime($apt['appointment_date'])) . '</td>';
        echo '<td>' . date('H:i', strtotime($apt['appointment_time'])) . '</td>';
        echo '<td>' . h($apt['patient_name']) . '</td>';
        echo '<td>' . h($apt['patient_phone'] ?? '-') . '</td>';
        echo '<td>' . h($apt['specialty'] ?? '-') . '</td>';
        echo '<td><span style="color:' . $statusColor . ';font-weight:600">' . $statusLabel . '</span></td>';
        echo '<td>' . h($apt['notes'] ? substr($apt['notes'], 0, 50) . '...' : '-') . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';

echo '</div>';

view_footer();
