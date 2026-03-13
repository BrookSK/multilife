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

// Buscar agendamentos do profissional (via patient_assignments)
// Apenas agendamentos aprovados na pré-admissão
$appointmentsStmt = db()->prepare("
    SELECT 
        pa.id,
        DATE(pa.created_at) as appointment_date,
        TIME(pa.created_at) as appointment_time,
        pa.created_at as first_at,
        pa.status,
        COALESCE(pa.agreed_value, pa.payment_value) as value_per_session,
        pa.notes,
        p.id as patient_id,
        p.full_name as patient_name,
        p.phone_primary as patient_phone,
        p.email as patient_email,
        pa.specialty,
        pa.service_type,
        pa.session_quantity,
        pa.session_frequency
    FROM patient_assignments pa
    INNER JOIN patients p ON p.id = pa.patient_id
    WHERE pa.professional_user_id = ?
    AND YEAR(pa.created_at) = ?
    AND MONTH(pa.created_at) = ?
    AND pa.status = 'approved'
    ORDER BY pa.created_at ASC
");
$appointmentsStmt->execute([$userId, $currentYear, $currentMonth]);
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
echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">';

$totalAppointments = count($appointments);
$confirmedCount = count(array_filter($appointments, fn($a) => $a['status'] === 'confirmed'));
$pendingCount = count(array_filter($appointments, fn($a) => $a['status'] === 'pending'));
$completedCount = count(array_filter($appointments, fn($a) => in_array($a['status'], ['approved', 'completed'])));

echo '<div style="padding:20px;background:hsl(var(--card));border:1px solid hsl(var(--border));border-radius:calc(var(--radius) + 4px);box-shadow:var(--shadow-card)">';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));font-weight:600;margin-bottom:8px">Total de Agendamentos</div>';
echo '<div style="font-size:32px;font-weight:900;color:hsl(var(--foreground))">' . $totalAppointments . '</div>';
echo '</div>';

echo '<div style="padding:20px;background:hsl(var(--card));border:1px solid hsl(var(--border));border-radius:calc(var(--radius) + 4px);box-shadow:var(--shadow-card)">';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));font-weight:600;margin-bottom:8px">Confirmados</div>';
echo '<div style="font-size:32px;font-weight:900;color:#10b981">' . $confirmedCount . '</div>';
echo '</div>';

echo '<div style="padding:20px;background:hsl(var(--card));border:1px solid hsl(var(--border));border-radius:calc(var(--radius) + 4px);box-shadow:var(--shadow-card)">';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));font-weight:600;margin-bottom:8px">Pendentes</div>';
echo '<div style="font-size:32px;font-weight:900;color:hsl(var(--warning))">' . $pendingCount . '</div>';
echo '</div>';

echo '<div style="padding:20px;background:hsl(var(--card));border:1px solid hsl(var(--border));border-radius:calc(var(--radius) + 4px);box-shadow:var(--shadow-card)">';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));font-weight:600;margin-bottom:8px">Concluídos</div>';
echo '<div style="font-size:32px;font-weight:900;color:hsl(var(--muted-foreground))">' . $completedCount . '</div>';
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
$today = date('Y-m-d');
for ($day = 1; $day <= $daysInMonth; $day++) {
    $date = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $day);
    $hasAppointments = isset($appointmentsByDate[$date]);
    $appointmentCount = $hasAppointments ? count($appointmentsByDate[$date]) : 0;
    $isToday = $date === $today;
    $isPast = $date < $today;
    
    $bgColor = $isToday ? 'hsla(var(--primary)/.05)' : 'hsl(var(--card))';
    $borderColor = $isToday ? 'hsl(var(--primary))' : 'hsl(var(--border))';
    $blurStyle = $isPast ? 'filter:blur(1px) brightness(1.1);opacity:0.6;' : '';
    $cursor = $hasAppointments ? 'cursor:pointer;' : '';
    $dataAttr = $hasAppointments ? ' data-date="' . $date . '" class="calendar-day-with-appointments"' : '';
    
    echo '<div style="padding:12px;background:' . $bgColor . ';border:1px solid ' . $borderColor . ';border-radius:calc(var(--radius) + 2px);min-height:80px;' . $blurStyle . $cursor . '"' . $dataAttr . '>';
    echo '<div style="font-weight:700;margin-bottom:8px;color:hsl(var(--foreground))">' . $day . '</div>';
    
    if ($hasAppointments) {
        foreach ($appointmentsByDate[$date] as $apt) {
            $statusColor = match($apt['status']) {
                'confirmed' => '#10b981',
                'pending' => 'hsl(var(--warning))',
                'approved', 'completed' => 'hsl(var(--muted-foreground))',
                'cancelled' => '#dc2626',
                default => 'hsl(var(--info))'
            };
            echo '<div style="background:' . $statusColor . ';color:white;font-size:10px;font-weight:600;padding:3px 6px;border-radius:4px;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" data-appointment-id="' . $apt['id'] . '">';
            echo date('H:i', strtotime($apt['appointment_time'])) . ' - ' . h(substr($apt['patient_name'], 0, 15));
            echo '</div>';
        }
    }
    
    echo '</div>';
}

echo '</div>';
echo '</section>';

// Modal para detalhes do agendamento
echo '<div id="appointmentModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100;align-items:center;justify-content:center">';
echo '<div style="background:hsl(var(--card));border-radius:calc(var(--radius) + 8px);max-width:500px;width:90%;max-height:90vh;overflow:auto;box-shadow:var(--shadow-elevated)">';
echo '<div style="padding:20px;border-bottom:1px solid hsl(var(--border));display:flex;align-items:center;justify-content:space-between">';
echo '<h3 style="margin:0;font-size:18px;font-weight:900">Detalhes do Agendamento</h3>';
echo '<button onclick="closeAppointmentModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:hsl(var(--muted-foreground))">&times;</button>';
echo '</div>';
echo '<div id="appointmentModalContent" style="padding:20px"></div>';
echo '</div>';
echo '</div>';

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
            'confirmed' => '#10b981',
            'pending' => '#f59e0b',
            'approved' => '#0284c7',
            'completed' => '#667781',
            'cancelled' => '#dc2626'
        ];
        $statusLabels = [
            'confirmed' => 'Confirmado',
            'pending' => 'Pendente',
            'approved' => 'Aprovado',
            'completed' => 'Concluído',
            'cancelled' => 'Cancelado'
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

echo '<script>';
echo 'const appointmentsData = ' . json_encode($appointmentsByDate) . ';';
echo 'document.querySelectorAll(".calendar-day-with-appointments").forEach(day => {';
echo '  day.addEventListener("click", function() {';
echo '    const date = this.getAttribute("data-date");';
echo '    const appointments = appointmentsData[date];';
echo '    if (!appointments || appointments.length === 0) return;';
echo '    ';
echo '    let html = "<div style=\"display:flex;flex-direction:column;gap:16px\">";';
echo '    appointments.forEach(apt => {';
echo '      const statusLabels = {';
echo '        "confirmed": "Confirmado",';
echo '        "pending": "Pendente",';
echo '        "approved": "Aprovado",';
echo '        "completed": "Concluído",';
echo '        "cancelled": "Cancelado"';
echo '      };';
echo '      const statusColors = {';
echo '        "confirmed": "#10b981",';
echo '        "pending": "hsl(var(--warning))",';
echo '        "approved": "hsl(var(--info))",';
echo '        "completed": "hsl(var(--muted-foreground))",';
echo '        "cancelled": "#dc2626"';
echo '      };';
echo '      ';
echo '      html += "<div style=\\"padding:16px;background:hsl(var(--muted));border-radius:8px;border-left:4px solid " + (statusColors[apt.status] || "hsl(var(--border))") + "\\">";';
echo '      html += "<div style=\\"display:flex;align-items:center;justify-content:space-between;margin-bottom:12px\\">";';
echo '      html += "<div style=\\"font-size:16px;font-weight:700;color:hsl(var(--foreground))\\">"+apt.patient_name+"</div>";';
echo '      html += "<span style=\\"font-size:12px;font-weight:600;color:"+(statusColors[apt.status] || "hsl(var(--muted-foreground))")+ "\\">"+(statusLabels[apt.status] || apt.status)+"</span>";';
echo '      html += "</div>";';
echo '      html += "<div style=\\"display:grid;gap:8px;font-size:14px\\">";';
echo '      html += "<div><strong>Horário:</strong> "+apt.appointment_time.substring(0,5)+"</div>";';
echo '      if (apt.patient_phone) html += "<div><strong>Telefone:</strong> "+apt.patient_phone+"</div>";';
echo '      if (apt.patient_email) html += "<div><strong>E-mail:</strong> "+apt.patient_email+"</div>";';
echo '      if (apt.specialty) html += "<div><strong>Especialidade:</strong> "+apt.specialty+"</div>";';
echo '      if (apt.service_type) html += "<div><strong>Tipo de Serviço:</strong> "+apt.service_type+"</div>";';
echo '      if (apt.session_quantity) html += "<div><strong>Sessões:</strong> "+apt.session_quantity+(apt.session_frequency ? " - "+apt.session_frequency : "")+"</div>";';
echo '      if (apt.value_per_session) html += "<div><strong>Valor por Sessão:</strong> R$ "+parseFloat(apt.value_per_session).toFixed(2).replace(".",",")+"</div>";';
echo '      html += "</div>";';
echo '      html += "</div>";';
echo '    });';
echo '    html += "</div>";';
echo '    ';
echo '    document.getElementById("appointmentModalContent").innerHTML = html;';
echo '    document.getElementById("appointmentModal").style.display = "flex";';
echo '  });';
echo '});';
echo '';
echo 'function closeAppointmentModal() {';
echo '  document.getElementById("appointmentModal").style.display = "none";';
echo '}';
echo '';
echo 'document.getElementById("appointmentModal").addEventListener("click", function(e) {';
echo '  if (e.target === this) closeAppointmentModal();';
echo '});';
echo '</script>';

view_footer();
