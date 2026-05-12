(function(){
  var form = document.getElementById("sendMessageForm");
  if(!form) return;
  
  // URL fixa para envio
  var phoneInput = form.querySelector("input[name=phone_number]");
  var chatId = phoneInput ? phoneInput.value : "";
  var isGroup = chatId.indexOf("@g.us") > -1;
  var typeParam = isGroup ? "&type=grupos" : "";
  var sendUrl = "/chat_web.php?chat=" + encodeURIComponent(chatId) + typeParam;
  
  form.addEventListener("submit", function(e){
    e.preventDefault();
    
    var textarea = form.querySelector("textarea[name=message]");
    var message = textarea.value.trim();
    if(!message) return;
    
    var sendBtn = form.querySelector("button[type=submit]");
    sendBtn.disabled = true;
    sendBtn.style.opacity = "0.5";
    
    // Adicionar mensagem ao chat imediatamente (otimista)
    var chatArea = document.querySelector(".whatsapp-messages");
    var msgDiv = null;
    if(chatArea){
      msgDiv = document.createElement("div");
      msgDiv.className = "whatsapp-message sent";
      msgDiv.innerHTML = '<div class="whatsapp-bubble sent"><div class="whatsapp-text">' + message.replace(/\n/g,"<br>") + '</div><div class="whatsapp-time">' + new Date().toLocaleTimeString("pt-BR",{hour:"2-digit",minute:"2-digit"}) + ' \u2713</div></div>';
      chatArea.appendChild(msgDiv);
      chatArea.scrollTop = chatArea.scrollHeight;
    }
    
    // Limpar campo imediatamente
    textarea.value = "";
    textarea.style.height = "auto";
    
    // Enviar via AJAX
    var formData = new FormData(form);
    formData.set("message", message);
    
    fetch(sendUrl, {
      method: "POST",
      headers: {"X-Requested-With": "XMLHttpRequest"},
      body: formData
    })
    .then(function(r){
      var contentType = r.headers.get("content-type") || "";
      if(contentType.indexOf("application/json") === -1){
        // Servidor retornou HTML - mensagem provavelmente foi enviada mas redirecionou
        return {success: true, fallback: true};
      }
      return r.json();
    })
    .then(function(data){
      if(data.success){
        if(msgDiv){
          var timeEl = msgDiv.querySelector(".whatsapp-time");
          if(timeEl) timeEl.innerHTML = timeEl.innerHTML.replace("\u2713","\u2713\u2713");
        }
      } else {
        if(msgDiv && chatArea) chatArea.removeChild(msgDiv);
        alert("Erro ao enviar: " + (data.error || "Erro desconhecido"));
      }
    })
    .catch(function(err){
      console.error("Erro no envio AJAX:", err.message);
      // Não remover mensagem - provavelmente foi enviada
    })
    .finally(function(){
      sendBtn.disabled = false;
      sendBtn.style.opacity = "1";
      textarea.focus();
    });
  });
  
  // Enter para enviar (Shift+Enter para nova linha)
  var ta = form.querySelector("textarea[name=message]");
  if(ta){
    ta.addEventListener("keydown", function(e){
      if(e.key === "Enter" && !e.shiftKey){
        e.preventDefault();
        form.dispatchEvent(new Event("submit", {cancelable:true}));
      }
    });
    ta.addEventListener("input", function(){
      this.style.height = "auto";
      this.style.height = Math.min(this.scrollHeight, 120) + "px";
    });
  }
})();
