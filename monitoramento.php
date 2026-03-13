<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
view_start('Monitoramento de Atendimentos');

$db = db();

// Buscar atendimentos
$sql = "SELECT a.id, a.first_at, a.status, a.value_per_session,
        p.id as patient_id, p.full_name as patient_name, p.whatsapp as patient_phone,
        u.id as professional_id, u.name as professional_name, u.phone as professional_phone,
        d.specialty, pa.service_type
        FROM appointments a
        INNER JOIN patients p ON p.id = a.patient_id
        INNER JOIN users u ON u.id = a.professional_user_id
        LEFT JOIN demands d ON d.id = a.demand_id
        LEFT JOIN patient_assignments pa ON pa.patient_id = p.id
        WHERE a.status IN ('agendado', 'pendente_formulario', 'realizado')
        AND a.first_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

$appointments = $db->query($sql)->fetchAll();

$events = [];
foreach ($appointments as $apt) {
    $color = ['agendado'=>'#10b981','pendente_formulario'=>'#f59e0b','realizado'=>'#6366f1'][(string)$apt['status']] ?? '#6366f1';
    $events[] = [
        'id' => (int)$apt['id'],
        'title' => $apt['patient_name'] . ' - ' . $apt['professional_name'],
        'start' => $apt['first_at'],
        'backgroundColor' => $color,
        'extendedProps' => [
            'patient_name' => $apt['patient_name'],
            'patient_phone' => $apt['patient_phone'] ?? '',
            'professional_name' => $apt['professional_name'],
            'professional_phone' => $apt['professional_phone'] ?? '',
            'status' => $apt['status'],
            'value' => (float)$apt['value_per_session'],
            'specialty' => $apt['specialty'] ?? ''
        ]
    ];
}
?>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css" rel="stylesheet">
<style>
.container{padding:24px;max-width:1400px;margin:0 auto}
.header{margin-bottom:24px}
.title{font-size:28px;font-weight:800;margin:0 0 8px 0}
.calendar{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px}
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center}
.modal.open{display:flex}
.modalContent{background:#fff;border-radius:16px;padding:32px;max-width:600px;width:90%}
.modalHeader{display:flex;justify-content:space-between;margin-bottom:24px}
.modalTitle{font-size:24px;font-weight:800;margin:0}
.close{background:none;border:none;font-size:24px;cursor:pointer}
.section{margin-bottom:20px}
.sectionTitle{font-size:14px;font-weight:700;color:#6b7280;margin:0 0 12px 0}
.info{display:flex;flex-direction:column;gap:8px}
.row{display:flex;gap:12px}
.label{font-weight:600;min-width:120px}
.value{color:#6b7280}
.actions{display:flex;gap:12px;margin-top:24px;padding-top:24px;border-top:1px solid #e5e7eb}
.btn{padding:10px 20px;border-radius:8px;font-weight:600;border:none;cursor:pointer;text-decoration:none;background:#14b8a6;color:#fff}
.btn:hover{background:#0d9488;text-decoration:none}
</style>

<div class="container">
    <div class="header">
        <h1 class="title">Monitoramento de Atendimentos</h1>
    </div>
    <div class="calendar"><div id="calendar"></div></div>
</div>

<div id="modal" class="modal">
    <div class="modalContent">
        <div class="modalHeader">
            <h2 class="modalTitle" id="modalTitle">Detalhes</h2>
            <button class="close" onclick="closeModal()">×</button>
        </div>
        <div class="section">
            <h3 class="sectionTitle">Paciente</h3>
            <div class="info">
                <div class="row"><span class="label">Nome:</span><span class="value" id="patientName">-</span></div>
                <div class="row"><span class="label">Telefone:</span><span class="value" id="patientPhone">-</span></div>
            </div>
        </div>
        <div class="section">
            <h3 class="sectionTitle">Profissional</h3>
            <div class="info">
                <div class="row"><span class="label">Nome:</span><span class="value" id="profName">-</span></div>
                <div class="row"><span class="label">Telefone:</span><span class="value" id="profPhone">-</span></div>
            </div>
        </div>
        <div class="actions" id="actions"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/locales/pt-br.global.min.js"></script>
<script>
const events = <?php echo json_encode($events); ?>;
document.addEventListener('DOMContentLoaded', function() {
    const calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
        initialView: 'dayGridMonth',
        locale: 'pt-br',
        headerToolbar: {left:'prev,next today',center:'title',right:'dayGridMonth,timeGridWeek'},
        events: events,
        eventClick: function(info) {
            const p = info.event.extendedProps;
            document.getElementById('modalTitle').textContent = 'Atendimento #' + info.event.id;
            document.getElementById('patientName').textContent = p.patient_name;
            document.getElementById('patientPhone').textContent = p.patient_phone || 'Não informado';
            document.getElementById('profName').textContent = p.professional_name;
            document.getElementById('profPhone').textContent = p.professional_phone || 'Não informado';
            
            const actions = document.getElementById('actions');
            actions.innerHTML = '';
            if(p.patient_phone) {
                const btn = document.createElement('a');
                btn.href = '/chat_web.php?phone=' + encodeURIComponent(p.patient_phone);
                btn.className = 'btn';
                btn.textContent = '💬 Chat Paciente';
                actions.appendChild(btn);
            }
            if(p.professional_phone) {
                const btn = document.createElement('a');
                btn.href = '/chat_web.php?phone=' + encodeURIComponent(p.professional_phone);
                btn.className = 'btn';
                btn.textContent = '💬 Chat Profissional';
                actions.appendChild(btn);
            }
            
            document.getElementById('modal').classList.add('open');
        }
    });
    calendar.render();
});

function closeModal() {
    document.getElementById('modal').classList.remove('open');
}
</script>

<?php view_end(); ?>
