// Voice and video calls between two members (App\Support\Calls). The call goes straight between the two browsers
// (WebRTC, always encrypted). The site rings the other member (a "call" event on their own channel, whatever page
// they have open), and the two browsers agree on the connection over the call's private channel
// (routes/channels.php), through our relay (coturn) when their networks can't connect directly. Ringing, answering
// and hanging up go through /messages/calls, which also leaves a note in the chat. Words: window.HL_CALLS.
(function () {
  var cfg = window.HL_LIVE;
  var t = window.HL_CALLS;
  var echo = window.Echo;
  if (!cfg || !t || !echo || typeof echo.private !== 'function' || !window.RTCPeerConnection ||
      !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;
  // The call buttons on the Messages page show only where calls work (site.css).
  document.documentElement.classList.add('can-call');

  var RING_MS = 45000;     // as App\Support\Calls::RING_SECONDS
  var call = null;         // the call on this page: { id, conversation, video, name, incoming, stream, pc, channel, … }

  function label(key, name) { return (t[key] || '').replace(':name', name || ''); }

  function request(method, url, body) {
    return fetch(url, {
      method: method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': cfg.csrf },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) { return { ok: r.ok, data: data }; });
    });
  }

  // The call screen: a <dialog> made once.
  var ICONS = {
    mic: '<path d="M12 3a3 3 0 0 1 3 3v6a3 3 0 0 1-6 0V6a3 3 0 0 1 3-3z"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/>',
    cam: '<rect x="2.5" y="6" width="13" height="12" rx="2"/><path d="M15.5 10.5l6-3.5v10l-6-3.5"/>',
    flip: '<path d="M4 7h11a4 4 0 0 1 4 4v1M20 17H9a4 4 0 0 1-4-4v-1"/><path d="M16 4l3 3-3 3M8 20l-3-3 3-3"/>',
    phone: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
    end: '<path d="M3 15.5c5-4.7 13-4.7 18 0l-2.4 2.4-3.6-1.6v-3a12 12 0 0 0-6 0v3l-3.6 1.6z"/>'
  };
  var ui;
  function button(name, icon) {
    return '<button type="button" class="call-btn call-' + name + '"><span class="call-icon"><svg viewBox="0 0 24 24" width="26" height="26" ' +
      'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      ICONS[icon] + '</svg></span><span class="call-label"></span></button>';
  }
  function build() {
    if (ui) return ui;
    var d = document.createElement('dialog');
    d.className = 'call-screen';
    d.innerHTML =
      '<video class="call-remote" autoplay playsinline muted></video>' +
      '<audio class="call-audio" autoplay></audio>' +
      '<video class="call-self" autoplay playsinline muted></video>' +
      '<div class="call-info"><span class="avatar call-avatar"></span><b class="call-name"></b>' +
      '<span class="call-status" aria-live="polite"></span></div>' +
      '<div class="call-actions">' + button('mute', 'mic') + button('camera', 'cam') + button('switch', 'flip') +
      button('accept', 'phone') + button('decline', 'end') + button('hangup', 'end') + '</div>';
    // Escape doesn't end a call by accident.
    d.addEventListener('cancel', function (e) { e.preventDefault(); });
    document.body.appendChild(d);
    function q(s) { return d.querySelector(s); }
    ui = {
      dialog: d, remote: q('.call-remote'), audio: q('.call-audio'), self: q('.call-self'),
      avatar: q('.call-avatar'), name: q('.call-name'), status: q('.call-status')
    };
    q('.call-mute').addEventListener('click', toggleMute);
    q('.call-camera').addEventListener('click', toggleCamera);
    q('.call-switch').addEventListener('click', switchCamera);
    q('.call-accept').addEventListener('click', accept);
    q('.call-decline').addEventListener('click', decline);
    q('.call-hangup').addEventListener('click', function () { hangUp('hangup'); });
    setLabel('mute', t.mute); setLabel('camera', t.camera_off); setLabel('switch', t.switch_camera);
    setLabel('accept', t.accept); setLabel('decline', t.decline); setLabel('hangup', t.hang_up);
    return ui;
  }
  function setLabel(name, text, pressed) {
    var b = ui.dialog.querySelector('.call-' + name);
    b.querySelector('.call-label').textContent = text;
    b.setAttribute('aria-label', text);
    if (pressed !== undefined) b.setAttribute('aria-pressed', pressed ? 'true' : 'false');
  }
  function show(state, status) {
    build();
    var d = ui.dialog;
    d.dataset.video = call && call.video ? '1' : '0';
    ui.name.textContent = call ? call.name : '';
    ui.avatar.className = 'avatar call-avatar tone-' + ((call && call.conversation) || 0) % 4;
    ui.avatar.innerHTML = '';
    if (call && call.avatar) {
      var img = document.createElement('img');
      img.src = call.avatar; img.alt = '';
      ui.avatar.appendChild(img);
    } else {
      ui.avatar.textContent = call ? call.initials || '' : '';
    }
    setState(state, status);
    if (!d.open) d.showModal();
  }
  function setState(state, status) {
    if (!ui) return;
    ui.dialog.dataset.state = state;
    ui.status.textContent = status !== undefined ? status : {
      outgoing: t.calling,
      incoming: call ? label(call.video ? 'incoming_video' : 'incoming_voice', call.name) : '',
      connecting: t.connecting,
      active: '0:00'
    }[state] || '';
  }
  function showSelf() {
    if (!call || !call.video || !call.stream) return;
    ui.self.srcObject = call.stream;
    ui.self.play().catch(function () {});
    navigator.mediaDevices.enumerateDevices().then(function (devices) {
      var cameras = devices.filter(function (dev) { return dev.kind === 'videoinput'; }).length;
      ui.dialog.dataset.cameras = cameras > 1 ? 'many' : 'one';
    }).catch(function () {});
  }

  // Ringing: two tones made in the browser (and a buzz on phones that can).
  var tone = null;
  function ring(incoming) {
    stopRing();
    var Ctx = window.AudioContext || window.webkitAudioContext;
    var ctx = Ctx ? new Ctx() : null;
    function beep() {
      if (incoming && navigator.vibrate) navigator.vibrate([300, 150, 300]);
      if (!ctx) return;
      if (ctx.state === 'suspended') ctx.resume().catch(function () {});
      [0, 0.45].forEach(function (at) {
        var o = ctx.createOscillator();
        var g = ctx.createGain();
        var start = ctx.currentTime + at;
        o.frequency.value = incoming ? 660 : 440;
        g.gain.setValueAtTime(0.0001, start);
        g.gain.exponentialRampToValueAtTime(incoming ? 0.18 : 0.06, start + 0.04);
        g.gain.exponentialRampToValueAtTime(0.0001, start + 0.38);
        o.connect(g); g.connect(ctx.destination);
        o.start(start); o.stop(start + 0.4);
      });
    }
    beep();
    tone = { ctx: ctx, timer: setInterval(beep, incoming ? 2000 : 3000) };
  }
  function stopRing() {
    if (!tone) return;
    clearInterval(tone.timer);
    if (tone.ctx) tone.ctx.close().catch(function () {});
    if (navigator.vibrate) navigator.vibrate(0);
    tone = null;
  }

  function media(video) {
    return navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
      video: video ? { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 }, frameRate: { ideal: 24 } } : false
    });
  }
  // A camera or microphone that stops by itself during a call (the phone gave it to another app, or the page was in
  // the background): take it again so the call carries on, a few times at most. Stopping it ourselves (hanging up,
  // switching camera) doesn't count: that doesn't end it this way.
  function watch(track) {
    track.addEventListener('ended', function () {
      if (!call || !call.stream || call.stream.getTracks().indexOf(track) < 0 || (call.retries = (call.retries || 0) + 1) > 3) return;
      var constraints = track.kind === 'video'
        ? { video: { facingMode: call.facing || 'user', width: { ideal: 640 }, height: { ideal: 480 } } }
        : { audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true } };
      navigator.mediaDevices.getUserMedia(constraints).then(function (stream) {
        var fresh = stream.getTracks()[0];
        if (!call || !call.stream) { fresh.stop(); return; }
        fresh.enabled = track.enabled;
        call.stream.removeTrack(track);
        call.stream.addTrack(fresh);
        var sender = call.pc && call.pc.getSenders().filter(function (s) { return s.track === track; })[0];
        if (sender) sender.replaceTrack(fresh);
        if (fresh.kind === 'video') ui.self.srcObject = call.stream;
        watch(fresh);
      }).catch(function () {});
    });
  }
  function mediaError(err) {
    return err && /NotAllowed|NotFound|NotReadable|Security|Overconstrained/.test(err.name || '') ? t.no_media : t.failed;
  }

  // The connection between the two browsers.
  // The caller adds their microphone and camera now; the one who answers adds theirs once the caller's offer is in
  // (onOffer), so they go into the places the offer made for them.
  function connect(ice) {
    var pc = new RTCPeerConnection({ iceServers: ice || [] });
    call.pc = pc;
    call.queue = [];
    if (!call.incoming) addTracks(pc);
    pc.onicecandidate = function (e) {
      if (e.candidate && call && call.pc === pc) call.channel.whisper('ice', { c: e.candidate.toJSON() });
    };
    // Sound and picture each get their own player, set when that part arrives (a picture added later to a stream
    // a player already has is sometimes never shown).
    pc.ontrack = function (e) {
      var player = e.track.kind === 'video' ? ui.remote : ui.audio;
      player.srcObject = new MediaStream([e.track]);
      player.play().catch(function () {});
    };
    function changed() {
      if (!call || call.pc !== pc) return;
      var s = pc.connectionState || pc.iceConnectionState;
      if (s === 'connected' || s === 'completed') { clearTimeout(call.lost); started(); }
      else if (s === 'failed') hangUp('failed');
      else if (s === 'disconnected') {
        clearTimeout(call.lost);
        call.lost = setTimeout(function () {
          if (call && call.pc === pc && (pc.connectionState || pc.iceConnectionState) !== 'connected') hangUp('failed');
        }, 10000);
      }
    }
    pc.onconnectionstatechange = changed;
    pc.oniceconnectionstatechange = changed;
    return pc;
  }
  function started() {
    if (!call || call.startedAt) return;
    call.startedAt = Date.now();
    setState('active');
    call.clock = setInterval(function () {
      if (!call) return;
      var s = Math.floor((Date.now() - call.startedAt) / 1000);
      ui.status.textContent = Math.floor(s / 60) + ':' + ('0' + s % 60).slice(-2);
    }, 1000);
  }
  function join(id) {
    return new Promise(function (resolve) {
      var channel = echo.private('call.' + id)
        .listenForWhisper('offer', onOffer)
        .listenForWhisper('answer', onAnswer)
        .listenForWhisper('ice', onIce);
      call.channel = channel;
      var done = false;
      function ready() { if (!done) { done = true; resolve(channel); } }
      if (typeof channel.subscribed === 'function') channel.subscribed(ready);
      setTimeout(ready, 4000);
    });
  }
  function flush() {
    var queued = call.queue;
    call.queue = [];
    return Promise.all(queued.map(function (c) { return call.pc.addIceCandidate(c).catch(function () {}); }));
  }
  function addTracks(pc) {
    call.stream.getTracks().forEach(function (track) { pc.addTrack(track, call.stream); });
  }
  function onOffer(e) {
    if (!call || !call.pc || !call.incoming) return;
    var pc = call.pc;
    pc.setRemoteDescription(e.sdp)
      .then(function () { addTracks(pc); return flush(); })
      .then(function () { return pc.createAnswer(); })
      .then(function (answer) { return pc.setLocalDescription(answer); })
      .then(function () { call.channel.whisper('answer', { sdp: pc.localDescription.toJSON() }); })
      .catch(function () { hangUp('failed'); });
  }
  function onAnswer(e) {
    if (!call || !call.pc || call.incoming) return;
    call.pc.setRemoteDescription(e.sdp).then(flush).catch(function () { hangUp('failed'); });
  }
  function onIce(e) {
    if (!call || !call.pc || !e.c) return;
    if (call.pc.remoteDescription) call.pc.addIceCandidate(e.c).catch(function () {});
    else call.queue.push(e.c);
  }

  // Calling someone (the buttons on the Messages page).
  function startCall(conversation, video) {
    if (call) return;
    call = { conversation: conversation, video: video, name: '', incoming: false };
    media(video).then(function (stream) {
      if (!call) { stream.getTracks().forEach(function (tr) { tr.stop(); }); return null; }
      call.stream = stream;
      stream.getTracks().forEach(watch);
      return request('POST', '/messages/calls', { conversation: conversation, video: video });
    }).then(function (res) {
      if (!res || !call) return;
      if (!res.ok) { finish((res.data && res.data.message) || t.failed); return; }
      Object.assign(call, res.data, { incoming: false });
      show('outgoing');
      showSelf();
      return join(call.id).then(function () {
        if (!call) return;
        connect(res.data.ice);
        if (call.answered) { sendOffer(); return; }
        ring(false);
        call.ringing = setTimeout(function () { hangUp('timeout'); }, RING_MS);
      });
    }).catch(function (err) { finish(mediaError(err)); });
  }

  // Being called: on any page.
  function incoming(e) {
    if (call) return;
    call = { id: e.id, conversation: e.conversation, video: !!e.video, name: e.name, initials: e.initials, avatar: e.avatar, incoming: true };
    show('incoming');
    ring(true);
    call.ringing = setTimeout(function () { finish(null); }, RING_MS + 5000);
  }
  function accept() {
    if (!call || !call.incoming || call.answeredHere) return;
    var id = call.id;
    call.answeredHere = true;
    stopRing();
    clearTimeout(call.ringing);
    setState('connecting');
    media(call.video).then(function (stream) {
      if (!call || call.id !== id) { stream.getTracks().forEach(function (tr) { tr.stop(); }); return null; }
      call.stream = stream;
      stream.getTracks().forEach(watch);
      showSelf();
      return join(id);
    }).then(function (channel) {
      // The relay login first, so the connection is ready when the caller's offer comes.
      return channel ? request('GET', '/messages/calls/' + id) : null;
    }).then(function (res) {
      if (!res || !call || call.id !== id) return null;
      connect(res.data.ice);
      return request('POST', '/messages/calls/' + id + '/answer');
    }).then(function (res) {
      if (res && !res.ok) finish((res.data && res.data.message) || t.gone);
    }).catch(function (err) {
      request('POST', '/messages/calls/' + id + '/decline');
      finish(mediaError(err));
    });
  }
  function decline() {
    if (!call || !call.incoming) return;
    request('POST', '/messages/calls/' + call.id + '/decline');
    finish(null);
  }
  function hangUp(reason) {
    if (!call) return;
    if (call.id) request('POST', '/messages/calls/' + call.id + '/end', { reason: reason });
    finish(reason === 'failed' ? t.failed : reason === 'timeout' ? t.no_answer : t.ended);
  }

  // The site: ringing, answered (here, or on the member's other device), ended.
  echo.private('App.Models.User.' + cfg.userId).listen('.call', function (e) {
    if (e.action === 'ring') { incoming(e); return; }
    if (!call || call.id !== e.id) return;
    if (e.action === 'answered') {
      if (call.incoming) { if (!call.answeredHere) finish(null); return; }
      call.answered = true;
      if (call.pc) sendOffer();
    } else if (e.action === 'ended') {
      finish(e.reason === 'declined' ? t.declined : e.reason === 'missed' ? (call.incoming ? null : t.no_answer) : t.ended);
    }
  });

  // They answered: the caller's browser proposes the connection.
  function sendOffer() {
    var pc = call.pc;
    stopRing();
    clearTimeout(call.ringing);
    setState('connecting');
    pc.createOffer()
      .then(function (offer) { return pc.setLocalDescription(offer); })
      .then(function () { if (call && call.pc === pc) call.channel.whisper('offer', { sdp: pc.localDescription.toJSON() }); })
      .catch(function () { hangUp('failed'); });
  }

  function finish(message) {
    var c = call;
    call = null;
    stopRing();
    if (c) {
      clearTimeout(c.ringing);
      clearTimeout(c.lost);
      clearInterval(c.clock);
      if (c.pc) { try { c.pc.close(); } catch (err) { /* already closed */ } }
      if (c.stream) c.stream.getTracks().forEach(function (tr) { tr.stop(); });
      if (c.id) echo.leave('call.' + c.id);
    }
    if (!ui) {
      if (message && c) { call = c; show('ended', message); call = null; setTimeout(close, 2500); }
      return;
    }
    ui.remote.srcObject = null;
    ui.audio.srcObject = null;
    ui.self.srcObject = null;
    if (message) {
      if (!ui.dialog.open && c) { call = c; show('ended', message); call = null; }
      else setState('ended', message);
      setTimeout(close, 2500);
    } else {
      close();
    }
  }
  function close() { if (ui && ui.dialog.open && !call) ui.dialog.close(); }

  function toggleMute() {
    var track = call && call.stream && call.stream.getAudioTracks()[0];
    if (!track) return;
    track.enabled = !track.enabled;
    setLabel('mute', track.enabled ? t.mute : t.unmute, !track.enabled);
  }
  function toggleCamera() {
    var track = call && call.stream && call.stream.getVideoTracks()[0];
    if (!track) return;
    track.enabled = !track.enabled;
    setLabel('camera', track.enabled ? t.camera_off : t.camera_on, !track.enabled);
  }
  function switchCamera() {
    if (!call || !call.video || !call.stream) return;
    call.facing = call.facing === 'environment' ? 'user' : 'environment';
    navigator.mediaDevices.getUserMedia({ video: { facingMode: call.facing } }).then(function (stream) {
      if (!call) { stream.getTracks().forEach(function (tr) { tr.stop(); }); return; }
      var track = stream.getVideoTracks()[0];
      var old = call.stream.getVideoTracks()[0];
      var sender = call.pc && call.pc.getSenders().filter(function (s) { return s.track && s.track.kind === 'video'; })[0];
      if (sender) sender.replaceTrack(track);
      if (old) { call.stream.removeTrack(old); old.stop(); }
      call.stream.addTrack(track);
      watch(track);
      ui.self.srcObject = call.stream;
    }).catch(function () {});
  }

  // The call buttons in a chat (resources/views/livewire/messages.blade.php).
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-call]');
    if (!b) return;
    e.preventDefault();
    startCall(Number(b.dataset.conversation), b.dataset.call === 'video');
  });

  // Leaving the page ends a call: ask first, and tell the site if they go anyway.
  function onCall() { return call && call.id && (!call.incoming || call.answeredHere); }
  window.addEventListener('beforeunload', function (e) {
    if (!onCall()) return;
    e.preventDefault();
    e.returnValue = t.leave_warning;
    return t.leave_warning;
  });
  window.addEventListener('pagehide', function () {
    if (!onCall() || !navigator.sendBeacon) return;
    var form = new FormData();
    form.append('_token', cfg.csrf);
    navigator.sendBeacon('/messages/calls/' + call.id + '/end', form);
  });

  // Opened from the "… is calling you" phone notification (?call=): still ringing? Then answer here.
  var linked = Number(new URLSearchParams(location.search).get('call'));
  if (linked) {
    request('GET', '/messages/calls/' + linked).then(function (res) {
      if (res.ok && res.data.status === 'ringing' && res.data.incoming) incoming(res.data);
    });
  }
})();
