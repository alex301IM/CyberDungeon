(function($){
  "use strict";

  // ---------- Helpers ----------
  function escapeHtml(str){
    return (str || "").toString()
      .replace(/&/g,"&amp;")
      .replace(/</g,"&lt;")
      .replace(/>/g,"&gt;")
      .replace(/"/g,"&quot;")
      .replace(/'/g,"&#39;");
  }

  function linkifyLine(escapedLine){
    return escapedLine.replace(/(^|[^"'>])(https?:\/\/[^\s<]+)/g, function(_, pre, url){
      const safeUrl = url.replace(/["'<>]/g, "");
      return pre + '<a href="'+safeUrl+'" target="_blank" rel="noopener noreferrer">'+url+'</a>';
    });
  }

  // Supports "Title: https://url" lines; otherwise linkifies bare URLs.
  function renderRichText(text){
    const lines = (text||"").toString().split(/\n/);
    const out = lines.map(line=>{
      const m = line.match(/^\s*([^:]{2,}):\s*(https?:\/\/\S+)\s*$/);
      if(m){
        const title = escapeHtml(m[1].trim());
        const url = m[2].trim().replace(/["'<>]/g,"");
        const urlEsc = escapeHtml(url);
        return '<a href="'+urlEsc+'" target="_blank" rel="noopener noreferrer">'+title+'</a>';
      }
      const esc = escapeHtml(line);
      return linkifyLine(esc);
    });
    return out.join("<br/>");
  }

  function fireGA(eventName, params){
    try{
      if(window.gtag){
        window.gtag("event", eventName, params || {});
      } else if(window.dataLayer){
        window.dataLayer.push(Object.assign({event: eventName}, params || {}));
      }
    }catch(e){}
  }

  function rest(endpoint, method, data){
    return fetch(_301InteractiveBot.restBase + endpoint, {
      method: method || "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": _301InteractiveBot.nonce
      },
      body: data ? JSON.stringify(data) : null
    }).then(async (res)=>{
      if(!res.ok){
        const t = await res.text();
        throw new Error(t || ("HTTP " + res.status));
      }
      return res.json();
    });
  }

  // ---------- UI helpers ----------
  function setStatus($w, msg){
    const $status = $w.find("._301interactivebot-status");
    const text = msg || "";
    $status.text(text);
    $status.toggleClass("is-visible", !!text);
  }

  function addMsg($box, sender, msg){
    const cls = sender === "admin" ? "bot" : sender; // display admin as bot-style bubble
    const html = '<div class="_301interactivebot-msg '+cls+'">'+ renderRichText(msg) +'</div>';
    $box.append(html);
    $box.scrollTop($box[0].scrollHeight);
  }

  function renderRecommended($box, links, chat_id){
    if(!window._301InteractiveBot || !_301InteractiveBot.showRecommendedLinks) return;
    if(!links || !links.length) return;
    const $wrap = $("<div/>").addClass("_301interactivebot-reco-wrap");
    const $title = $("<div/>").addClass("_301interactivebot-reco-title").text("Recommended pages");
    const $cards = $("<div/>").addClass("_301interactivebot-reco-cards");

    links.slice(0,6).forEach(l=>{
      const url = (l.url||"").toString();
      if(!url) return;
      const text = (l.title||url).toString();
      const $a = $("<a/>")
        .addClass("_301interactivebot-reco-card")
        .attr("href", url)
        .attr("target","_blank")
        .attr("rel","noopener noreferrer")
        .append($("<div/>").addClass("_301interactivebot-reco-card-title").text(text))
        .append($("<div/>").addClass("_301interactivebot-reco-card-url").text(url));

      $a.on("click", ()=>{
        try{
          if(chat_id){
            rest("/event","POST",{chat_id:chat_id, event_type:"link_click", event_value: JSON.stringify({title:text,url:url}), url: window.location.href});
          }
        }catch(e){}
      });

      $cards.append($a);
    });

    $wrap.append($title).append($cards);
    $box.append($wrap);
    $box.scrollTop($box[0].scrollHeight);
  }

  // ---------- Polling ----------
  function pollLoop($w, chat_id, seen, onIncoming, state){
    const pollState = state || { sinceId: 0 };
    const $box = $w.find("._301interactivebot-messages");
    const tick = ()=>{
      fetch(_301InteractiveBot.restBase + "/poll?chat_id=" + encodeURIComponent(chat_id) + "&since_id=" + pollState.sinceId, {
        headers: {"X-WP-Nonce": _301InteractiveBot.nonce},
        credentials: "same-origin"
      }).then(r=>r.json()).then(resp=>{
        if(resp && Array.isArray(resp.messages)){
          resp.messages.forEach(m=>{
            const mid = parseInt(m.id, 10) || 0;
            pollState.sinceId = Math.max(pollState.sinceId, mid);
            if(seen && seen.has(m.sender, m.message)) return;
            addMsg($box, m.sender, m.message);
            if(seen) seen.add(m.sender, m.message);
            if(typeof onIncoming === "function"){ try{ onIncoming(m); }catch(e){} }
          });

          if(resp.admin_takeover === 1){
            setStatus($w, "A live team member has joined the chat.");
          } else {
            setStatus($w, "");
          }
        }
      }).catch(()=>{});
    };
    tick();
    return setInterval(tick, (parseInt(_301InteractiveBot.pollIntervalMs||1000,10) || 1000));
  }

  // ---------- Init ----------
  function initWidget($w){
    // Theme/branding
    try{
      $w.css("--_301interactivebot-primary", _301InteractiveBot.primaryColor || "#0b1f3a");
      $w.css("--_301interactivebot-accent", _301InteractiveBot.accentColor || "#2563eb");
      $w.css("--_301interactivebot-bubble", _301InteractiveBot.bubbleColor || "#2563eb");
      $w.css("--_301interactivebot-text", _301InteractiveBot.textColor || "#0b1f3a");

      if(_301InteractiveBot.logoUrl){
        $w.find("._301interactivebot-logo").attr("src", _301InteractiveBot.logoUrl).show();
      }
      if((_301InteractiveBot.closedIcon || "chat") === "none"){
        $w.find("._301interactivebot-bubble").hide();
      } else {
        $w.find("._301interactivebot-bubble").show();
      }
    }catch(e){}

    function applyWidgetPosition(){
      const mode = (_301InteractiveBot.widgetMode || 'floating');
      const pos = (_301InteractiveBot.widgetPosition || 'bottom-right');
      const bp = parseInt(_301InteractiveBot.widgetMobileBreakpoint||768,10);
      const isMobile = window.innerWidth <= bp;
      const ox = isMobile ? (parseInt(_301InteractiveBot.widgetOffsetXMobile||12,10)) : (parseInt(_301InteractiveBot.widgetOffsetXDesktop||20,10));
      const oy = isMobile ? (parseInt(_301InteractiveBot.widgetOffsetYMobile||12,10)) : (parseInt(_301InteractiveBot.widgetOffsetYDesktop||20,10));
      const z = parseInt(_301InteractiveBot.widgetZIndex||999999,10);

      if(mode === 'floating'){
        $w.css({position:'fixed', zIndex: z});
        const clear = {top:'',bottom:'',left:'',right:''};
        $w.css(clear);
        if(pos.indexOf('bottom')===0) $w.css('bottom', oy+'px'); else $w.css('top', oy+'px');
        if(pos.indexOf('right')>0) $w.css('right', ox+'px'); else $w.css('left', ox+'px');
      } else {
        // embedded
        $w.css({position:'relative', top:'',bottom:'',left:'',right:'', zIndex:''});
      }
    }
    applyWidgetPosition();
    window.addEventListener('resize', function(){ applyWidgetPosition(); }, {passive:true});

    const $box   = $w.find("._301interactivebot-messages");
    const $input = $w.find("._301interactivebot-input");
    const $send  = $w.find("._301interactivebot-send");
    const $thinking = $w.find("._301interactivebot-thinking");
    const $bubble = $w.find("._301interactivebot-bubble");
    const $toggle = $w.find("._301interactivebot-toggle");
    const $window = $w.find("._301interactivebot-window");
    const $inputRow = $w.find("._301interactivebot-input-row");
    const $lead = $w.find("._301interactivebot-lead");
    const $leadFirst = $w.find("._301interactivebot-lead-first");
    const $leadLast = $w.find("._301interactivebot-lead-last");
    const $leadPhone = $w.find("._301interactivebot-lead-phone");
    const $leadEmail = $w.find("._301interactivebot-lead-email");
    const $leadAddress = $w.find("._301interactivebot-lead-address");
    const $leadSubmit = $w.find("._301interactivebot-lead-submit");

    let chat_id = null;
    let session_key = null;
    let poller = null;
    let pageWatcher = null;
    let lastPageUrl = window.location.href;
    const storageKey = "301interactivebot_session";
    const uiStateKey = "301interactivebot_widget_state";
    let leadSavedPayload = null;
    let leadSaveTimer = null;
    let leadFlow = null;
    let pendingLeadLinks = null;
    let leadCollected = false;
    const leadCaptureMode = (_301InteractiveBot.leadCaptureMode || "form").toString();
    const requireEmail = !!_301InteractiveBot.requireEmail;
    const requirePhone = !!_301InteractiveBot.requirePhone;
    const requireAddress = !!_301InteractiveBot.requireAddress;
    const escalationEnabled = !!_301InteractiveBot.escalationEnabled;
    const escalationKeywords = Array.isArray(_301InteractiveBot.escalationKeywords) ? _301InteractiveBot.escalationKeywords.map(v=>(v||"").toString().trim().toLowerCase()).filter(Boolean) : [];
    const leadPromptIntro = (_301InteractiveBot.leadPromptIntro || "To help with your request, please share your contact details.").toString();

    // pending lock (only defined once)
    let pending = false;
    function setPending(on, mode){
      pending = !!on;
      $thinking.toggleClass("is-visible", pending);
      $thinking.find("._301interactivebot-thinking-text")
        .text(mode === "admin" ? "Admin is replying…" : "Thinking…");
      $input.prop("disabled", pending);
      $send.prop("disabled", pending);
    }

    // Seen set for de-dup
    const seen = {
      map: {},
      order: [],
      key(sender, msg){ return (sender + "|" + (msg||"")).slice(0, 9000); },
      has(sender, msg){ return !!this.map[this.key(sender,msg)]; },
      add(sender, msg){
        const k = this.key(sender,msg);
        if(this.map[k]) return;
        this.map[k] = 1;
        this.order.push(k);
        if(this.order.length > 300){
          const old = this.order.shift();
          delete this.map[old];
        }
      }
    };

    // Service-area and county/state mapping logic intentionally disabled for generic deployments.

    function detectPricingIntent(text){
      const msg = (text || "").toString().toLowerCase();
      if(/\b(price|pricing|cost|how much|quote|estimate)\b/i.test(msg)) return true;
      if(!escalationEnabled || !escalationKeywords.length) return false;
      return escalationKeywords.some(keyword => keyword && msg.includes(keyword));
    }

    function shouldOpenLeadFromReply(replyText){
      const txt = (replyText || "").toString().toLowerCase();
      if(!txt) return false;
      const asksLead = (
        /first\s*name/.test(txt) &&
        /last\s*name/.test(txt) &&
        /address/.test(txt)
      );
      const explicitLeadPrompt = /please provide|share your|to get your info|get your info|lead info/.test(txt);
      return asksLead || explicitLeadPrompt;
    }

    // Service-area price list sharing intentionally disabled for generic deployments.

    function getLeadPayload(){
      return {
        first_name: ($leadFirst.val() || "").trim(),
        last_name: ($leadLast.val() || "").trim(),
        phone: ($leadPhone.val() || "").trim(),
        email: ($leadEmail.val() || "").trim(),
        address: ($leadAddress.val() || "").trim()
      };
    }

    function isLeadComplete(payload){
      const hasAddress = !requireAddress || !!payload.address;
      const hasEmail = !requireEmail || !!payload.email;
      const hasPhone = !requirePhone || !!payload.phone;
      return payload.first_name && payload.last_name && hasAddress && hasEmail && hasPhone;
    }

    function shouldCollectLeadNow(){
      if(leadCollected) return false;
      if(leadCaptureMode === "chat" && leadFlow && leadFlow.active) return false;
      return true;
    }

    function scheduleLeadSave(){
      if(leadSaveTimer) clearTimeout(leadSaveTimer);
      leadSaveTimer = setTimeout(saveLeadIfReady, 300);
    }

    function saveLeadIfReady(force, payloadOverride, announce){
      if(!chat_id) return;
      const payload = payloadOverride || getLeadPayload();
      if(!force && !isLeadComplete(payload)) return;
      const payloadKey = JSON.stringify(payload);
      if(!force && payloadKey === leadSavedPayload) return;
      leadSavedPayload = payloadKey;
      rest("/lead","POST", Object.assign({chat_id, current_page: window.location.href, announce: !!announce}, payload)).then(()=>{
        leadCollected = true;
        if(force) setStatus($w, "Thanks! We'll be in touch.");
        if(pendingLeadLinks && pendingLeadLinks.length){
          renderRecommended($box, pendingLeadLinks, chat_id);
          pendingLeadLinks = null;
        }
      }).catch(()=>{});
    }

    function handleMessageResponse(resp){
      if(!resp) return;
      if(resp.user_message_id){
        pollState.sinceId = Math.max(pollState.sinceId, parseInt(resp.user_message_id, 10) || 0);
      }
      if(resp.mode === "bot" && resp.reply){
        if(resp.message_id){
          pollState.sinceId = Math.max(pollState.sinceId, parseInt(resp.message_id, 10) || 0);
        }
        addMsg($box, "bot", resp.reply);
        seen.add("bot", resp.reply);
        if(leadCaptureMode === "form" && !leadCollected && shouldOpenLeadFromReply(resp.reply)){
          toggleLeadForm(true);
        }
        setPending(false);
      } else if(resp.mode === "admin"){
        setStatus($w, "A live team member is handling the chat.");
        setPending(true, "admin");
      }
    }

    function requestPostLeadResponse(summary){
      if(!chat_id) return;
      setPending(true, "ai");
      rest("/message","POST",{
        chat_id: chat_id || undefined,
        session_key: session_key || undefined,
        message: summary,
        current_page: window.location.href,
        referrer: document.referrer || ""
      }).then(resp=>{
        handleMessageResponse(resp);
        if(resp && resp.suggested_links && resp.suggested_links.length){
          if(resp.should_collect_lead || (leadFlow && leadFlow.active) || (leadCaptureMode === "form" && $lead.is(":visible"))){
            pendingLeadLinks = resp.suggested_links;
          } else {
            renderRecommended($box, resp.suggested_links, chat_id);
          }
        }
      }).catch(()=>{
        setPending(false);
      });
    }

    function persistWidgetOpenState(isOpen){
      try {
        localStorage.setItem(uiStateKey, JSON.stringify({isOpen: !!isOpen}));
      } catch(e){}
    }

    function getInitialWidgetOpenState(){
      try {
        const raw = localStorage.getItem(uiStateKey);
        if(!raw) return true; // default open
        const parsed = JSON.parse(raw);
        if(parsed && typeof parsed.isOpen === "boolean") return parsed.isOpen;
      } catch(e){}
      return true;
    }

    function setOpen(isOpen, persist){
      $w.toggleClass("minimized", !isOpen);
      $window.attr("aria-hidden", isOpen ? "false" : "true");
      if(persist !== false) persistWidgetOpenState(isOpen);
      if(isOpen){
        setTimeout(()=>{ $input.trigger("focus"); }, 0);
      }
    }

    $bubble.on("click", ()=>{ setOpen(true, true); activity(); });
    $toggle.on("click", ()=>{ setOpen(false, true); });

    function logPageView(url){
      if(!chat_id) return;
      rest("/event","POST",{chat_id: chat_id, event_type:"page_view", event_value:"", url: url || window.location.href}).catch(()=>{});
    }

    function startPageWatcher(){
      if(pageWatcher) return;
      lastPageUrl = window.location.href;
      logPageView(lastPageUrl);
      pageWatcher = setInterval(()=>{
        if(!chat_id) return;
        const current = window.location.href;
        if(current !== lastPageUrl){
          lastPageUrl = current;
          logPageView(current);
        }
      }, 2000);
    }

    function stopPageWatcher(){
      if(pageWatcher){
        clearInterval(pageWatcher);
        pageWatcher = null;
      }
    }

    // Idle timeout
    const idleSeconds = parseInt(_301InteractiveBot.idleTimeoutSeconds || 300, 10);
    let idleTimer = null;
    let warnTimer = null;

    function clearIdle(){
      if(idleTimer) { clearTimeout(idleTimer); idleTimer = null; }
      if(warnTimer) { clearTimeout(warnTimer); warnTimer = null; }
    }

    function showIdleWarning(){
      if(!chat_id) return;
      if($("._301interactivebot-idle-modal").length) return;
      let remaining = 30;
      const $modal = $(
        '<div class="_301interactivebot-idle-modal">'+
          '<div class="_301interactivebot-idle-card">'+
            '<div class="_301interactivebot-idle-title">Still there?</div>'+
            '<div class="_301interactivebot-idle-text">Your chat will end in 30 seconds.</div>'+
            '<div class="_301interactivebot-idle-actions">'+
              '<button type="button" class="_301interactivebot-idle-keep">Keep Open</button>'+
              '<button type="button" class="_301interactivebot-idle-end">End Now</button>'+
            '</div>'+
          '</div>'+
        '</div>'
      );
      $("body").append($modal);
      const $text = $modal.find("._301interactivebot-idle-text");
      $text.text(`Your chat will end in ${remaining} seconds.`);
      const tick = setInterval(()=>{
        remaining -= 1;
        if(remaining <= 0){
          clearInterval(tick);
          $modal.remove();
          endIdle("idle_timeout");
          return;
        }
        $text.text(`Your chat will end in ${remaining} seconds.`);
      }, 1000);
      const clearTick = ()=>{ clearInterval(tick); };
      $modal.find("._301interactivebot-idle-keep").on("click", ()=>{
        clearTick();
        $modal.remove();
        resetIdle();
      });
      $modal.find("._301interactivebot-idle-end").on("click", ()=>{
        clearTick();
        $modal.remove();
        endIdle("idle_end_now");
      });
    }

    function clearChatUI(){
      $box.empty();
      seen.map = {};
      seen.order = [];
    }

    function resetLeadForm(){
      $leadFirst.val("");
      $leadLast.val("");
      $leadPhone.val("");
      $leadEmail.val("");
      $leadAddress.val("");
      leadSavedPayload = null;
      leadFlow = null;
      pendingLeadLinks = null;
      if(leadSaveTimer){
        clearTimeout(leadSaveTimer);
        leadSaveTimer = null;
      }
      toggleLeadForm(false);
    }

    function resetWidgetAfterChatEnd(endMessage){
      if(endMessage){
        addMsg($box, "bot", endMessage);
        seen.add("bot", endMessage);
      }
      setPending(false);
      stopPageWatcher();
      try{ localStorage.removeItem(storageKey); }catch(e){}
      chat_id = null;
      session_key = null;
      pollState.sinceId = 0;
      resetLeadForm();
      clearChatUI();
      setStatus($w, "");
      $input.prop("disabled", false);
      $send.prop("disabled", false);
      setOpen(false, true);
      addMsg($box, "bot", _301InteractiveBot.welcome || "Hi! How can I help?");
      seen.add("bot", _301InteractiveBot.welcome || "Hi! How can I help?");
    }

    function endIdle(reason){
      if(!chat_id) return;
      clearIdle();
      $("._301interactivebot-idle-modal").remove();
      // end server-side
      rest("/end","POST",{chat_id}).catch(()=>{});
      fireGA("301interactivebot_chat_end", {chat_id: chat_id, reason: "idle"});
      rest("/event","POST",{chat_id:chat_id, event_type:"chat_end", event_value:"idle", url: window.location.href}).catch(()=>{});
      if(poller){
        clearInterval(poller);
        poller = null;
      }
      resetWidgetAfterChatEnd("Chat ended due to inactivity.");
    }

    function resetIdle(){
      clearIdle();
      if(!chat_id) return;
      if(!idleSeconds || idleSeconds < 60) return;
      warnTimer = setTimeout(showIdleWarning, Math.max(0, (idleSeconds - 30)) * 1000);
      idleTimer = setTimeout(()=>endIdle("idle_timeout"), idleSeconds * 1000);
    }

    function activity(){
      resetIdle();
    }

    function endChatOnUnexpectedClose(){
      if(!chat_id) return;
      const url = _301InteractiveBot.restBase + "/end";
      const payload = JSON.stringify({ chat_id: chat_id });
      let sent = false;

      try{
        if(navigator && typeof navigator.sendBeacon === "function"){
          const blob = new Blob([payload], {type:"application/json"});
          sent = navigator.sendBeacon(url, blob);
        }
      }catch(e){}

      if(!sent){
        try{
          fetch(url, {
            method: "POST",
            headers: {"Content-Type":"application/json"},
            credentials: "same-origin",
            body: payload,
            keepalive: true
          }).catch(()=>{});
        }catch(e){}
      }
    }

    // Idle reset on activity (clean block, no duplicates)
    const activityEvents = ["click","scroll","keydown","mousemove","touchstart"];
    activityEvents.forEach(ev=>{
      window.addEventListener(ev, activity, {passive:true});
    });
    document.addEventListener("visibilitychange", activity);
    window.addEventListener("pagehide", (e)=>{
      if(e && e.persisted) return;
      endChatOnUnexpectedClose();
    });
    window.addEventListener("beforeunload", ()=>{
      endChatOnUnexpectedClose();
    });

    function toggleLeadForm(show){
      if(leadCaptureMode !== "form"){
        $lead.hide();
        return;
      }
      $lead.toggle(!!show);
      $inputRow.toggle(!show);
    }

    [$leadFirst, $leadLast, $leadPhone, $leadEmail, $leadAddress].forEach($field=>{
      $field.on("input blur", scheduleLeadSave);
    });
    $leadSubmit.on("click", ()=>{
      const payload = getLeadPayload();
      const missing = [];
      if(!payload.first_name) missing.push("First Name");
      if(!payload.last_name) missing.push("Last Name");
      if(requireAddress && !payload.address) missing.push("Address");
      if(requireEmail && !payload.email) missing.push("Email");
      if(requirePhone && !payload.phone) missing.push("Phone");
      if(missing.length){
        setStatus($w, `${missing.join(", ")} ${missing.length === 1 ? "is" : "are"} required.`);
        return;
      }
      setStatus($w, "");
      const emailText = payload.email ? `Email: ${payload.email}` : "Email: (not provided)";
      const summary = `Customer info submitted: ${payload.first_name} ${payload.last_name}, Phone: ${payload.phone || "(not provided)"}, ${emailText}, Address: ${payload.address}.`;
      addMsg($box, "user", summary);
      seen.add("user", summary);
      fireGA("301interactivebot_chat_lead_form", {chat_id: chat_id || undefined});
      saveLeadIfReady(true, payload, true);
      leadCollected = true;
      //maybeSharePriceList(payload);
      toggleLeadForm(false);
      requestPostLeadResponse(summary);
    });

    function startLeadChatFlow(){
      if(leadCaptureMode !== "chat") return;
      if(leadFlow && leadFlow.active) return;
      leadFlow = {active: true, step: "first_name", data: {}};
      addMsg($box, "bot", leadPromptIntro);
      seen.add("bot", leadPromptIntro);
      addMsg($box, "bot", "What’s your first name?");
      seen.add("bot", "What’s your first name?");
    }

    function handleLeadChatInput(message){
      if(!leadFlow || !leadFlow.active) return false;
      const msg = (message || "").trim();
      if(!msg) return true;
      switch(leadFlow.step){
        case "first_name":
          leadFlow.data.first_name = msg;
          leadFlow.step = "last_name";
          addMsg($box, "bot", "Thanks! What’s your last name?");
          seen.add("bot", "Thanks! What’s your last name?");
          return true;
        case "last_name":
          leadFlow.data.last_name = msg;
          leadFlow.step = "phone";
          addMsg($box, "bot", "What’s your phone number? (optional, type skip to continue)");
          seen.add("bot", "What’s your phone number? (optional, type skip to continue)");
          return true;
        case "phone":
          if(requirePhone && msg.toLowerCase() === "skip"){
            addMsg($box, "bot", "Phone is required for this request. What’s your phone number?");
            seen.add("bot", "Phone is required for this request. What’s your phone number?");
            return true;
          }
          leadFlow.data.phone = (msg.toLowerCase() === "skip") ? "" : msg;
          leadFlow.step = "email";
          if(requireEmail){
            addMsg($box, "bot", "What’s your email?");
            seen.add("bot", "What’s your email?");
          } else {
            addMsg($box, "bot", "What’s your email? (optional, type skip to continue)");
            seen.add("bot", "What’s your email? (optional, type skip to continue)");
          }
          return true;
        case "email":
          if(requireEmail && msg.toLowerCase() === "skip"){
            addMsg($box, "bot", "Email is required for this request. What’s your email?");
            seen.add("bot", "Email is required for this request. What’s your email?");
            return true;
          }
          leadFlow.data.email = (msg.toLowerCase() === "skip") ? "" : msg;
          if(requireAddress){
            leadFlow.step = "address";
            addMsg($box, "bot", "What address are you building at or interested in?");
            seen.add("bot", "What address are you building at or interested in?");
          } else {
            leadFlow.step = "done";
          }
          return true;
        case "address":
          leadFlow.data.address = msg;
          if(requireAddress && !leadFlow.data.address){
            addMsg($box, "bot", "Please provide an address so we can follow up.");
            seen.add("bot", "Please provide an address so we can follow up.");
            return true;
          }
          leadFlow.step = "done";
          // fall through
        case "done":
          leadFlow.active = false;
          const payload = {
            first_name: leadFlow.data.first_name || "",
            last_name: leadFlow.data.last_name || "",
            phone: leadFlow.data.phone || "",
            email: leadFlow.data.email || "",
            address: leadFlow.data.address || ""
          };
          saveLeadIfReady(true, payload);
          leadCollected = true;
          addMsg($box, "bot", "Thanks! We’ve passed your info to a New Home Consultant.");
          seen.add("bot", "Thanks! We’ve passed your info to a New Home Consultant.");
          requestPostLeadResponse(`Customer info submitted: ${payload.first_name} ${payload.last_name}, Phone: ${payload.phone || "(not provided)"}, Email: ${payload.email || "(not provided)"}, Address: ${payload.address || "(not provided)"}.`);
          leadFlow = null;
          return true;
        default:
          return false;
      }
    }

    // Start chat
    setOpen(getInitialWidgetOpenState(), false);
    addMsg($box, "bot", _301InteractiveBot.welcome || "Hi! How can I help?");
    seen.add("bot", _301InteractiveBot.welcome || "Hi! How can I help?");
    toggleLeadForm(false);

    let stored = null;
    try{
      stored = JSON.parse(localStorage.getItem(storageKey) || "null");
    }catch(e){}
    const startPayload = {};
    if(stored && stored.chat_id && stored.session_key){
      startPayload.chat_id = stored.chat_id;
      startPayload.session_key = stored.session_key;
    } else if(stored && stored.session_key){
      startPayload.session_key = stored.session_key;
    }

    const pollState = { sinceId: 0 };

    rest("/start","POST",startPayload).then(resp=>{
      chat_id = resp.chat_id || null;
      session_key = resp.session_key || (stored ? stored.session_key : null);
      if(session_key){
        try{
          const toStore = {session_key: session_key};
          if(chat_id){
            toStore.chat_id = chat_id;
          }
          localStorage.setItem(storageKey, JSON.stringify(toStore));
        }catch(e){}
      }
      if(chat_id){
        fireGA("301interactivebot_chat_start", {chat_id: chat_id});
        rest("/event","POST",{chat_id:chat_id, event_type:"chat_start", event_value:"", url: window.location.href}).catch(()=>{});
        resetIdle();
        startPageWatcher();
        // begin polling
        poller = pollLoop($w, chat_id, seen, (m)=>{
          // unlock when AI/admin actually responds
          if(pending && (m.sender === "bot" || m.sender === "admin")){
            setPending(false);
          }
          activity();
        }, pollState);
      }
    }).catch(()=>{
      setStatus($w, "Unable to start chat right now.");
    });

    function send(){
      if(pending) return;
      const msg = ($input.val() || "").trim();
      if(!msg) return;

      $input.val("");
      addMsg($box, "user", msg);
      seen.add("user", msg);
      setPending(true, "ai");
      activity();
      const shouldEscalateNow = detectPricingIntent(msg);

      if(handleLeadChatInput(msg)){
        setPending(false);
        return;
      }

      // Immediate ACK version expects queued response
      rest("/message","POST",{
        chat_id: chat_id || undefined,
        session_key: session_key || undefined,
        message: msg,
        current_page: window.location.href,
        referrer: document.referrer || ""
      }).then(resp=>{
        if(resp && resp.session_key && !session_key){
          session_key = resp.session_key;
        }
        if(resp && resp.chat_id && !chat_id){
          chat_id = resp.chat_id;
          try{
            localStorage.setItem(storageKey, JSON.stringify({chat_id, session_key}));
          }catch(e){}
          fireGA("301interactivebot_chat_start", {chat_id: chat_id});
          rest("/event","POST",{chat_id:chat_id, event_type:"chat_start", event_value:"", url: window.location.href}).catch(()=>{});
          resetIdle();
          if(!poller){
            poller = pollLoop($w, chat_id, seen, (m)=>{
              if(pending && (m.sender === "bot" || m.sender === "admin")){
                setPending(false);
              }
              activity();
            }, pollState);
          }
          startPageWatcher();
        }
        if(resp && resp.user_message_id){
          pollState.sinceId = Math.max(pollState.sinceId, parseInt(resp.user_message_id, 10) || 0);
        }
        if(resp && (resp.should_collect_lead || shouldEscalateNow) && shouldCollectLeadNow()){
          if(leadCaptureMode === "chat"){
            startLeadChatFlow();
          } else {
            addMsg($box, "bot", leadPromptIntro);
            seen.add("bot", leadPromptIntro);
            toggleLeadForm(true);
          }
          if(resp && resp.suggested_links && resp.suggested_links.length){
            pendingLeadLinks = resp.suggested_links;
          }
        }
        handleMessageResponse(resp);
        if(resp && resp.suggested_links && resp.suggested_links.length){
          if(resp.should_collect_lead || (leadFlow && leadFlow.active) || (leadCaptureMode === "form" && $lead.is(":visible"))){
            pendingLeadLinks = resp.suggested_links;
          } else {
            renderRecommended($box, resp.suggested_links, chat_id);
          }
        }
        scheduleLeadSave();
      }).catch(()=>{
        setPending(false);
        addMsg($box, "bot", "Sorry—something went wrong. Please try again.");
        seen.add("bot", "Sorry—something went wrong. Please try again.");
      });
    }

    $send.on("click", send);
    $input.on("keydown", (e)=>{ if(e.key === "Enter"){ e.preventDefault(); send(); } });

    // End chat button (if present)
    $w.find("._301interactivebot-endchat").on("click", ()=>{
      if(!chat_id) return;
      clearIdle();
      $("._301interactivebot-idle-modal").remove();
      rest("/end","POST",{chat_id}).catch(()=>{});
      rest("/event","POST",{chat_id:chat_id, event_type:"chat_end", event_value:"manual", url: window.location.href}).catch(()=>{});
      fireGA("301interactivebot_chat_end", {chat_id: chat_id, reason: "manual"});
      if(poller){
        clearInterval(poller);
        poller = null;
      }
      resetWidgetAfterChatEnd("Chat ended. Thanks for visiting!");
    });
  }

  $(function(){
    // Initialize all widgets rendered on page
    $("._301interactivebot-widget").each(function(){
      initWidget($(this));
    });
  });

})(jQuery);
