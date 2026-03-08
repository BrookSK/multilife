// Funções do Chat Web - Arquivo JavaScript externo

// Captura de erros
window.onerror = function(msg, url, line, col, error) {
  var errorData = {
    message: msg,
    url: url,
    line: line,
    col: col,
    stack: error ? error.stack : null
  };
  fetch("/chat_log_error.php", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify(errorData)
  }).catch(function(){});
  console.error("JS Error:", msg, "at", line + ":" + col);
  return false;
};

// Funções de modal
function openNewChatModal() {
  const modal = document.getElementById("newChatModal");
  if (modal) {
    modal.style.display = "flex";
  }
}

function closeNewChatModal() {
  document.getElementById("newChatModal").style.display = "none";
}

var groupModalInitialized = false;

function openCreateGroupModal() {
  document.getElementById("createGroupModal").style.display = "flex";
  if(!groupModalInitialized) {
    initGroupModalCities();
    groupModalInitialized = true;
  }
}

function closeCreateGroupModal() {
  document.getElementById("createGroupModal").style.display = "none";
}

function closeAssignmentModal() {
  document.getElementById("assignmentModal").style.display = "none";
  document.getElementById("assignmentForm").reset();
}

function closeInfoPanel() {
  window.location.href = "/chat_web.php";
}

// Função de atribuição de paciente
async function openAssignmentModal() {
  const demandSelect = document.getElementById("demandSelect");
  if(!demandSelect || !demandSelect.value) {
    alert("Por favor, selecione um card de captação primeiro.");
    return;
  }
  const selectedOption = demandSelect.options[demandSelect.selectedIndex];
  const demandId = demandSelect.value;
  const demandText = selectedOption.text;
  const professionalUserId = selectedOption.getAttribute("data-user-id");
  
  document.getElementById("professionalName").textContent = window.chatName || window.chatId || "";
  document.getElementById("demandInfo").textContent = demandText;
  document.getElementById("assignmentModal").style.display = "flex";
  
  if (professionalUserId) {
    try {
      const response = await fetch("/api/get_user_specialty.php?user_id=" + professionalUserId);
      const data = await response.json();
      if (data.specialty_id) {
        const specialtySelect = document.getElementById("specialty");
        specialtySelect.value = data.specialty_id;
        // Carregar serviços da especialidade automaticamente
        loadSpecialtyServices();
      }
    } catch (err) {
      console.error("Erro ao buscar especialidade:", err);
    }
  }
}

function handlePatientSelection() {
  const patientSelect = document.getElementById("patientId");
  if (patientSelect && patientSelect.value === "new") {
    if (confirm("Você será redirecionado para o formulário de cadastro de paciente. Deseja continuar?")) {
      const chatId = window.chatId || "";
      window.location.href = "/patients_create.php?from_chat=1&from_assignment_modal=1&chat_id=" + encodeURIComponent(chatId);
    } else {
      patientSelect.value = "";
    }
  }
}

// Carregar serviços da especialidade selecionada
async function loadSpecialtyServices() {
  const specialtySelect = document.getElementById("specialty");
  const serviceSelect = document.getElementById("serviceType");
  const serviceMinValue = document.getElementById("serviceMinValue");
  
  if (!specialtySelect || !serviceSelect) return;
  
  const specialtyId = specialtySelect.value;
  
  // Limpar select de serviços
  serviceSelect.innerHTML = '<option value="">Carregando...</option>';
  serviceMinValue.value = "0";
  
  if (!specialtyId) {
    serviceSelect.innerHTML = '<option value="">Selecione primeiro a especialidade...</option>';
    return;
  }
  
  try {
    const response = await fetch("/api/get_specialty_services.php?specialty_id=" + specialtyId);
    const data = await response.json();
    
    if (data.error) {
      serviceSelect.innerHTML = '<option value="">Erro ao carregar serviços</option>';
      return;
    }
    
    if (!data.services || data.services.length === 0) {
      serviceSelect.innerHTML = '<option value="">Nenhum serviço cadastrado para esta especialidade</option>';
      return;
    }
    
    serviceSelect.innerHTML = '<option value="">Selecione o tipo de serviço...</option>';
    data.services.forEach(function(service) {
      const option = document.createElement("option");
      option.value = service.id;
      option.textContent = service.service_name + (service.description ? " - " + service.description : "");
      option.setAttribute("data-min-value", service.base_value);
      serviceSelect.appendChild(option);
    });
    
  } catch (err) {
    console.error("Erro ao carregar serviços:", err);
    serviceSelect.innerHTML = '<option value="">Erro ao carregar serviços</option>';
  }
}

// Atualizar valor mínimo quando serviço é selecionado
function updateMinimumValue() {
  const serviceSelect = document.getElementById("serviceType");
  const serviceMinValue = document.getElementById("serviceMinValue");
  const agreedValueInput = document.getElementById("agreedValue");
  const authorizedValueInput = document.getElementById("authorizedValue");
  
  if (!serviceSelect || !serviceMinValue) return;
  
  const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
  const minValue = parseFloat(selectedOption.getAttribute("data-min-value") || "0");
  
  serviceMinValue.value = minValue.toString();
  
  // Atualizar atributo min dos inputs
  if (agreedValueInput) {
    agreedValueInput.setAttribute("min", minValue.toString());
    agreedValueInput.setAttribute("placeholder", "Mínimo: R$ " + minValue.toFixed(2));
  }
  if (authorizedValueInput) {
    authorizedValueInput.setAttribute("min", minValue.toString());
    authorizedValueInput.setAttribute("placeholder", "Mínimo: R$ " + minValue.toFixed(2));
  }
}

// Função de filtro de grupos
function loadGroupsByFilter() {
  const specialty = document.getElementById("groupSpecialty").value;
  const region = document.getElementById("groupRegion").value;
  const select = document.getElementById("selectedGroup");
  
  select.innerHTML = "<option value=''>Carregando...</option>";
  
  fetch("/chat_get_filtered_groups.php?specialty=" + encodeURIComponent(specialty) + "&region=" + encodeURIComponent(region))
    .then(r => r.json())
    .then(data => {
      if(data.success && data.groups) {
        select.innerHTML = "<option value=''>Selecione um grupo...</option>";
        data.groups.forEach(group => {
          const option = document.createElement("option");
          option.value = group.group_jid;
          option.textContent = group.group_name + (group.specialty ? " (" + group.specialty + ")" : "");
          select.appendChild(option);
        });
        if(data.groups.length === 0) {
          select.innerHTML = "<option value=''>Nenhum grupo encontrado</option>";
        }
      } else {
        select.innerHTML = "<option value=''>Erro ao carregar grupos</option>";
      }
    })
    .catch(e => {
      select.innerHTML = "<option value=''>Erro ao carregar grupos</option>";
      console.error("Erro:", e);
    });
}

// Função de envio de convite para grupo
function sendGroupInvite() {
  const chatId = window.chatId || "";
  const groupJid = document.getElementById("selectedGroup").value;
  const welcomeMessage = document.getElementById("welcomeMessage").value;
  
  if(!groupJid) {
    alert("Por favor, selecione um grupo");
    return;
  }
  
  if(confirm("Deseja enviar o convite para este grupo?")) {
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = "Enviando...";
    
    fetch("/chat_send_group_invite.php", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({
        chat_id: chatId,
        group_jid: groupJid,
        welcome_message: welcomeMessage
      })
    })
    .then(r => r.json())
    .then(data => {
      if(data.success) {
        alert("Convite enviado com sucesso!");
        document.getElementById("selectedGroup").value = "";
      } else {
        alert("Erro ao enviar convite: " + (data.error || "Erro desconhecido"));
      }
    })
    .catch(e => {
      alert("Erro ao enviar convite: " + e.message);
    })
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:6px"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>Enviar Convite';
    });
  }
}

// Outras funções auxiliares
function syncEvolution() {
  const btn = document.getElementById("syncBtn");
  const icon = document.getElementById("syncIcon");
  btn.disabled = true;
  icon.classList.add("rotating");
  fetch("/chat_sync_evolution.php")
    .then(r => r.json())
    .then(data => {
      if(data.success) {
        alert("Sincronização concluída!");
        location.reload();
      } else {
        alert("Erro na sincronização: " + (data.error || "Erro desconhecido"));
      }
    })
    .catch(err => {
      alert("Erro ao sincronizar: " + err.message);
    })
    .finally(() => {
      btn.disabled = false;
      icon.classList.remove("rotating");
    });
}

function syncWhatsApp() {
  var msg = "Sincronizar todas as conversas e grupos do WhatsApp?";
  if(confirm(msg)) {
    window.location.href = "/chat_sync_whatsapp.php";
  }
}

function toggleActionsMenu(e) {
  if(e) {
    e.preventDefault();
    e.stopPropagation();
  }
  const menu = document.getElementById("actionsMenu");
  if(menu) {
    menu.classList.toggle("show");
  }
}

function toggleChatMenu(e) {
  e.stopPropagation();
  alert("Menu do chat em desenvolvimento");
}

function searchInChat() {
  alert("Busca no chat em desenvolvimento");
}

function dispararFluxo() {
  alert("Funcionalidade em desenvolvimento");
}

function dispararRemarketing() {
  alert("Funcionalidade em desenvolvimento");
}

function filterProfessionals() {
  const search = document.getElementById("professionalSearch").value.toLowerCase();
  const select = document.getElementById("professionalSelect");
  const options = select.options;
  for (let i = 0; i < options.length; i++) {
    const option = options[i];
    if (i === 0) continue;
    const name = option.getAttribute("data-name") || "";
    const phone = option.getAttribute("data-phone") || "";
    if (name.includes(search) || phone.includes(search)) {
      option.style.display = "";
    } else {
      option.style.display = "none";
    }
  }
}

function filterPatients() {
  const search = document.getElementById("patientSearch").value.toLowerCase();
  const select = document.getElementById("patientSelect");
  const options = select.options;
  for (let i = 0; i < options.length; i++) {
    const option = options[i];
    if (i === 0) continue;
    const name = option.getAttribute("data-name") || "";
    const phone = option.getAttribute("data-phone") || "";
    if (name.includes(search) || phone.includes(search)) {
      option.style.display = "";
    } else {
      option.style.display = "none";
    }
  }
}

function switchTab(tab) {
  document.getElementById("tabProfessionals").style.borderBottomColor = "transparent";
  document.getElementById("tabProfessionals").style.color = "#54656f";
  document.getElementById("tabPatients").style.borderBottomColor = "transparent";
  document.getElementById("tabPatients").style.color = "#54656f";
  document.getElementById("tabManual").style.borderBottomColor = "transparent";
  document.getElementById("tabManual").style.color = "#54656f";
  document.getElementById("contentProfessionals").style.display = "none";
  document.getElementById("contentPatients").style.display = "none";
  document.getElementById("contentManual").style.display = "none";
  document.getElementById("professionalSelect").value = "";
  document.getElementById("patientSelect").value = "";
  document.getElementById("manualPhone").value = "";
  if (tab === "professionals") {
    document.getElementById("tabProfessionals").style.borderBottomColor = "#00a884";
    document.getElementById("tabProfessionals").style.color = "#00a884";
    document.getElementById("contentProfessionals").style.display = "block";
  } else if (tab === "patients") {
    document.getElementById("tabPatients").style.borderBottomColor = "#00a884";
    document.getElementById("tabPatients").style.color = "#00a884";
    document.getElementById("contentPatients").style.display = "block";
  } else if (tab === "manual") {
    document.getElementById("tabManual").style.borderBottomColor = "#00a884";
    document.getElementById("tabManual").style.color = "#00a884";
    document.getElementById("contentManual").style.display = "block";
  }
}

// Listener do formulário de atribuição de paciente
document.addEventListener("DOMContentLoaded", function() {
  const assignmentForm = document.getElementById("assignmentForm");
  if (assignmentForm) {
    assignmentForm.addEventListener("submit", function(e) {
      e.preventDefault();
      
      const demandSelect = document.getElementById("demandSelect");
      const patientId = document.getElementById("patientId").value;
      const specialtySelect = document.getElementById("specialty");
      const specialtyId = specialtySelect.value;
      const specialtyName = specialtySelect.options[specialtySelect.selectedIndex].getAttribute("data-name") || "";
      const serviceType = document.getElementById("serviceType").value;
      const sessionQuantity = document.getElementById("sessionQuantity").value;
      const sessionFrequency = document.getElementById("sessionFrequency").value;
      const agreedValue = parseFloat(document.getElementById("agreedValue").value);
      const authorizedValue = parseFloat(document.getElementById("authorizedValue").value);
      const serviceMinValue = parseFloat(document.getElementById("serviceMinValue").value || "0");
      const notes = document.getElementById("assignmentNotes").value;
      const healthInsurerId = document.getElementById("healthInsurer").value;
      
      if (!demandSelect || !demandSelect.value) {
        alert("Por favor, selecione um card de captação primeiro.");
        return;
      }
      
      const demandId = demandSelect.value;
      const professionalJid = window.chatId || "";
      
      if (!patientId || patientId === "new") {
        alert("Por favor, selecione um paciente válido.");
        return;
      }
      
      // Validar valores mínimos
      if (agreedValue < serviceMinValue) {
        alert("O Valor Acordado (R$ " + agreedValue.toFixed(2) + ") não pode ser menor que o valor mínimo do serviço (R$ " + serviceMinValue.toFixed(2) + ")");
        return;
      }
      
      if (authorizedValue < serviceMinValue) {
        alert("O Valor Autorizado (R$ " + authorizedValue.toFixed(2) + ") não pode ser menor que o valor mínimo do serviço (R$ " + serviceMinValue.toFixed(2) + ")");
        return;
      }
      
      if (agreedValue > authorizedValue) {
        if (!confirm("ATENÇÃO: O Valor Acordado (R$ " + agreedValue.toFixed(2) + ") é MAIOR que o Valor Autorizado (R$ " + authorizedValue.toFixed(2) + "). Isso resultará em PREJUÍZO de R$ " + (agreedValue - authorizedValue).toFixed(2) + ". Deseja continuar?")) {
          return;
        }
      }
      
      fetch("/chat_assign_patient.php", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({
          demand_id: demandId,
          patient_id: patientId,
          professional_jid: professionalJid,
          specialty_id: specialtyId,
          specialty: specialtyName,
          service_type_id: serviceType,
          session_quantity: sessionQuantity,
          session_frequency: sessionFrequency,
          agreed_value: agreedValue,
          authorized_value: authorizedValue,
          health_insurer_id: healthInsurerId,
          notes: notes
        })
      })
      .then(r => r.json())
      .then(data => {
        if(data.success) {
          alert("Paciente atribuído com sucesso! Mensagem enviada ao profissional.");
          closeAssignmentModal();
          location.reload();
        } else {
          alert("Erro: " + (data.error || "Erro ao atribuir paciente"));
        }
      })
      .catch(err => {
        alert("Erro ao processar atribuição: " + err.message);
      });
    });
  }
});

console.log("✅ Chat Web Functions carregadas com sucesso");
