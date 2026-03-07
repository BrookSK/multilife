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
  document.getElementById("newChatModal").style.display = "flex";
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
      if (data.specialty) {
        const specialtySelect = document.getElementById("specialty");
        for (let i = 0; i < specialtySelect.options.length; i++) {
          if (specialtySelect.options[i].value === data.specialty) {
            specialtySelect.selectedIndex = i;
            break;
          }
        }
      }
    } catch (err) {
      console.error("Erro ao buscar especialidade:", err);
    }
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

console.log("✅ Chat Web Functions carregadas com sucesso");
