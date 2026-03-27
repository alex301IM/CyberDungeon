(function($){
  let selectedChatId = null;
  let sinceId = 0;
  let polling = false;
  let chatMeta = null;

  function api(path, method="POST", data=null){
    return fetch(_301InteractiveBotAdmin.restBase + path, {
      method,
      headers: {
        "Content-Type":"application/json",
        "X-WP-Nonce": _301InteractiveBotAdmin.nonce
      },
      body: data ? JSON.stringify(data) : null
    }).then(r=>r.json());
  }

  function renderList(chats){
    const $list = $("#301interactivebot-chat-list");
    $list.empty();
    chats.forEach(c=>{
      const county = c.build_county || c.build_city || "No County";
      const title = `Chat #${c.id} — ${county} — ${c.lead_first || ""} ${c.lead_last || ""}`.trim();
      const $i = $(`<div class="301interactivebot-live-item"></div>`);
      $i.text(title);
      $i.toggleClass("active", c.id === selectedChatId);
      $i.on("click", ()=>selectChat(c.id));
      $list.append($i);
    });
  }

  function renderMessages(msgs){
    const $box = $("#301interactivebot-chat-messages");
    msgs.forEach(m=>{
      const $b = $(`<div class="301interactivebot-live-bubble ${m.sender}"></div>`);
      $b.text(m.message);
      $box.append($b);
      $box.scrollTop($box[0].scrollHeight);
      sinceId = Math.max(sinceId, parseInt(m.id,10));
    });
  }

  function escapeHtml(str){
    return (str || "").toString()
      .replace(/&/g,"&amp;")
      .replace(/</g,"&lt;")
      .replace(/>/g,"&gt;")
      .replace(/"/g,"&quot;")
      .replace(/'/g,"&#39;");
  }

  function renderSummary(summary){
    const $summary = $("#301interactivebot-chat-summary");
    if(!summary){
      $summary.html("");
      return;
    }
    const html = escapeHtml(summary).replace(/\n/g, "<br/>");
    $summary.html(
      '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px;background:#f8fafc;">' +
        '<div style="font-weight:600;margin-bottom:6px;color:#0b1f3a;">Summary</div>' +
        '<div style="font-size:13px;color:#334155;line-height:1.4;">' + html + '</div>' +
      '</div>'
    );
  }

  function renderPages(pages){
    const $pages = $("#301interactivebot-chat-pages");
    if(!pages || !pages.length){
      $pages.html("");
      return;
    }
    const items = pages.map(p=>`<li><a href="${escapeHtml(p)}" target="_blank" rel="noopener noreferrer">${escapeHtml(p)}</a></li>`).join("");
    $pages.html(
      '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px;background:#f8fafc;">' +
        '<div style="font-weight:600;margin-bottom:6px;color:#0b1f3a;">Pages Visited</div>' +
        '<ul style="margin:0 0 0 18px;padding:0;color:#334155;font-size:13px;line-height:1.4;">' + items + '</ul>' +
      '</div>'
    );
  }

  function setInputEnabled(enabled){
    $("#301interactivebot-admin-input").prop("disabled", !enabled);
    $("#301interactivebot-admin-send").prop("disabled", !enabled);
  }

  function setTakeoverButtons(takeover){
    $("#301interactivebot-takeover").prop("disabled", takeover);
    $("#301interactivebot-release").prop("disabled", !takeover);
  }

  function selectChat(chatId){
    selectedChatId = chatId;
    sinceId = 0;
    $("#301interactivebot-chat-title").text("Chat #" + chatId);
    $("#301interactivebot-chat-messages").empty();
    setInputEnabled(false);
    setTakeoverButtons(false);
    fetchMeta();
    pollOnce();
  }

  function pollOnce(){
    if(!selectedChatId) return;
    fetch(_301InteractiveBotAdmin.restBase + "/poll?chat_id=" + encodeURIComponent(selectedChatId) + "&since_id=" + sinceId, {
      headers: {"X-WP-Nonce": _301InteractiveBotAdmin.nonce}
    }).then(r=>r.json()).then(resp=>{
      if(resp && Array.isArray(resp.messages)){
        renderMessages(resp.messages);
        const takeover = resp.admin_takeover === 1;
        setTakeoverButtons(takeover);
        setInputEnabled(takeover);
      }
    }).catch(()=>{});
  }

  function fetchMeta(){
    if(!selectedChatId) return;
    fetch(_301InteractiveBotAdmin.restBase + "/admin/chat?chat_id=" + encodeURIComponent(selectedChatId), {
      headers: {"X-WP-Nonce": _301InteractiveBotAdmin.nonce}
    }).then(r=>r.json()).then(resp=>{
      chatMeta = resp && resp.chat ? resp.chat : null;
      const $meta = $("#301interactivebot-chat-meta");
      if(chatMeta){
        const parts = [];
        if(chatMeta.session_key) parts.push("Session: " + chatMeta.session_key);
        if(chatMeta.last_user_ip) parts.push("IP: " + chatMeta.last_user_ip);
        if(chatMeta.lead_email) parts.push("Email: " + chatMeta.lead_email);
        if(chatMeta.lead_phone) parts.push("Phone: " + chatMeta.lead_phone);
        $meta.text(parts.join(" | "));
        renderSummary(chatMeta.summary || "");
        renderPages(chatMeta.pages || []);
        $("#301interactivebot-block").prop("disabled", false);
        $("#301interactivebot-endchat").prop("disabled", false);
      } else {
        $meta.text("");
        renderSummary("");
        renderPages([]);
      }
    }).catch(()=>{});
  }

  function loop(){
    if(polling) return;
    polling = true;
    setInterval(()=>{ pollOnce(); refreshList(); }, 2000);
  }

  function bindExportButton(){
    const $btn = $("#301interactivebot-export-vector");
    if(!$btn.length) return;
    const $status = $("#301interactivebot-export-status");
    $btn.on("click", ()=>{
      $status.text("Exporting content to Vector Store…");
      $btn.prop("disabled", true);
      $.post(ajaxurl, {
        action: "301interactivebot_export_vector",
        nonce: _301InteractiveBotAdmin.exportNonce
      }).done((resp)=>{
        if(resp && resp.success){
          const data = resp.data || {};
          $status.text(`Export complete. Uploaded ${data.files_uploaded || 0} file(s), ${data.chunks || 0} chunks.`);
        } else {
          $status.text((resp && resp.data) ? resp.data : "Export failed.");
        }
      }).fail((xhr)=>{
        const msg = (xhr && xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data : "Export failed.";
        $status.text(msg);
      }).always(()=>{
        $btn.prop("disabled", false);
      });
    });
  }
  window._301InteractiveBotAdminBindExport = bindExportButton;

  function refreshList(){
    // Minimal list query using WP AJAX endpoint via admin-ajax.php is possible,
    // but we keep it simple: list is derived from a custom REST endpoint in PHP.
    // To avoid extra endpoints in this MVP, we piggyback on an embedded AJAX call:
    $.post(ajaxurl, { action: "301interactivebot_list_chats" }, function(resp){
      if(resp && resp.success && Array.isArray(resp.data)){
        renderList(resp.data);
      }
    });
  }

  $(function(){
    // list chats via admin-ajax action
    refreshList();
    loop();

    // Auto-open a specific chat (e.g., from All Chats -> View Chat)
    let initialId = _301InteractiveBotAdmin && _301InteractiveBotAdmin.initialChatId ? parseInt(_301InteractiveBotAdmin.initialChatId, 10) : 0;
    if(!initialId){
      try{
        const params = new URLSearchParams(window.location.search);
        initialId = parseInt(params.get("chat_id") || "0", 10);
      }catch(e){}
    }
    if(initialId){
      selectChat(initialId);
    }

    $("#301interactivebot-takeover").on("click", ()=>{
      if(!selectedChatId) return;
      api("/admin/takeover","POST",{chat_id:selectedChatId}).then(()=>pollOnce());
    });
    $("#301interactivebot-release").on("click", ()=>{
      if(!selectedChatId) return;
      api("/admin/release","POST",{chat_id:selectedChatId}).then(()=>pollOnce());
    });
    $("#301interactivebot-endchat").on("click", ()=>{
      if(!selectedChatId) return;
      api("/admin/end","POST",{chat_id:selectedChatId}).then(()=>pollOnce());
    });

    $("#301interactivebot-resend-transcript").on("click", ()=>{
      if(!selectedChatId) return;
      api("/admin/resend-transcript","POST",{chat_id:selectedChatId}).then((resp)=>{
        if(resp && !resp.error){
          alert("Transcript email resent.");
        } else {
          alert((resp && resp.error) ? resp.error : "Failed to resend transcript email.");
        }
      }).catch(()=>{
        alert("Failed to resend transcript email.");
      });
    });

    $("#301interactivebot-block").on("click", ()=>{
      if(!selectedChatId) return;
      const blockType = prompt("Block type: session, ip, email, or phone", "session");
      if(!blockType) return;
      let blockValue = "";
      if(chatMeta){
        if(blockType === "session") blockValue = chatMeta.session_key || "";
        if(blockType === "ip") blockValue = chatMeta.last_user_ip || "";
        if(blockType === "email") blockValue = chatMeta.lead_email || "";
        if(blockType === "phone") blockValue = chatMeta.lead_phone || "";
      }
      if(!blockValue) blockValue = prompt("Enter value to block for type " + blockType + ":", "");
      if(!blockValue) return;
      const reason = prompt("Reason (optional):", "");
      api("/admin/block","POST",{chat_id:selectedChatId, block_type:blockType, block_value:blockValue, reason: reason || ""}).then(()=>{
        alert("Blocked: " + blockType + " = " + blockValue);
      });
    });

    $("#301interactivebot-admin-send").on("click", ()=>{
      const msg = ($("#301interactivebot-admin-input").val()||"").toString().trim();
      if(!msg || !selectedChatId) return;
      $("#301interactivebot-admin-input").val("");
      api("/admin/send","POST",{chat_id:selectedChatId, message: msg}).then(()=>pollOnce());
    });
    $("#301interactivebot-admin-input").on("keydown", (e)=>{
      if(e.key==="Enter"){ e.preventDefault(); $("#301interactivebot-admin-send").click(); }
    });
  });

})(jQuery);

jQuery(function($){
  // Color pickers
  $('.301interactivebot-color').wpColorPicker();

  // Media uploader for logo
  let frame = null;
  $('#301interactivebot_logo_pick').on('click', function(){
    if(frame){ frame.open(); return; }
    frame = wp.media({ title: 'Select Logo', button: { text: 'Use this logo' }, multiple: false });
    frame.on('select', function(){
      const att = frame.state().get('selection').first().toJSON();
      $('#301interactivebot_logo_id').val(att.id);
      const url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
      $('#301interactivebot_logo_preview').html('<img src="'+url+'" style="max-height:40px;width:auto" />');
    });
    frame.open();
  });
  $('#301interactivebot_logo_clear').on('click', function(){
    $('#301interactivebot_logo_id').val('0');
    $('#301interactivebot_logo_preview').empty();
  });

  function serializeFaqs(){
    const items = [];
    $("#301interactivebot-faq-list .301interactivebot-faq-row").each(function(){
      const q = ($(this).find(".301interactivebot-faq-q").val() || "").toString().trim();
      const a = ($(this).find(".301interactivebot-faq-a").val() || "").toString().trim();
      if(q && a){
        items.push({ q, a });
      }
    });
    $("#301interactivebot_faq_json").val(JSON.stringify(items));
  }

  function bindFaqRow($row){
    $row.find(".301interactivebot-faq-q, .301interactivebot-faq-a").on("input", serializeFaqs);
    $row.find(".301interactivebot-faq-remove").on("click", function(){
      $row.remove();
      serializeFaqs();
    });
  }

  $("#301interactivebot-faq-list .301interactivebot-faq-row").each(function(){
    bindFaqRow($(this));
  });

  $("#301interactivebot-faq-add").on("click", function(){
    const $row = $(
      '<div class="301interactivebot-faq-row" style="margin-bottom:12px;border:1px solid #e5e7eb;padding:12px;border-radius:10px;">' +
        '<div style="margin-bottom:8px;">' +
          '<label style="display:block;font-weight:600;margin-bottom:4px;">Question</label>' +
          '<input type="text" class="301interactivebot-faq-q" style="width:100%;" />' +
        '</div>' +
        '<div style="margin-bottom:8px;">' +
          '<label style="display:block;font-weight:600;margin-bottom:4px;">Answer</label>' +
          '<textarea class="301interactivebot-faq-a" rows="3" style="width:100%;"></textarea>' +
        '</div>' +
        '<button type="button" class="button 301interactivebot-faq-remove">Remove</button>' +
      '</div>'
    );
    $("#301interactivebot-faq-list").append($row);
    bindFaqRow($row);
    serializeFaqs();
  });


  function serviceAreaIndex(){
    return $("#301interactivebot-service-area-list .301interactivebot-service-area-row").length;
  }

  function bindServiceAreaRow($row){
    $row.find('.301interactivebot-service-area-remove').on('click', function(){
      $row.remove();
    });
  }

  $("#301interactivebot-service-area-list .301interactivebot-service-area-row").each(function(){
    bindServiceAreaRow($(this));
  });

  $("#301interactivebot-service-area-add").on('click', function(){
    const i = serviceAreaIndex();
    const $row = $(
      '<div class="301interactivebot-service-area-row" style="margin-bottom:12px;border:1px solid #e5e7eb;padding:12px;border-radius:10px;">' +
        '<div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:center;margin-bottom:8px;">' +
          '<label>State</label>' +
          '<input type="text" name="301interactivebot_settings[service_areas]['+i+'][state]" placeholder="KY" />' +
          '<label>Counties</label>' +
          '<textarea name="301interactivebot_settings[service_areas]['+i+'][counties]" rows="4" placeholder="One county per line"></textarea>' +
          '<label>Price List Name</label>' +
          '<input type="text" name="301interactivebot_settings[service_areas]['+i+'][price-list-name]" />' +
          '<label>Price List Link</label>' +
          '<input type="url" name="301interactivebot_settings[service_areas]['+i+'][price-list-link]" />' +
          '<label>Community</label>' +
          '<input type="text" name="301interactivebot_settings[service_areas]['+i+'][community]" />' +
          '<label>Email List</label>' +
          '<input type="text" name="301interactivebot_settings[service_areas]['+i+'][email-list]" placeholder="a@x.com, b@y.com" />' +
        '</div>' +
        '<button type="button" class="button 301interactivebot-service-area-remove">Remove</button>' +
      '</div>'
    );
    $("#301interactivebot-service-area-list").append($row);
    bindServiceAreaRow($row);
  });

  if(window._301InteractiveBotAdminBindExport){
    window._301InteractiveBotAdminBindExport();
  }
  serializeFaqs();
});