// Voice messages on the Messages page (App\Livewire\Messages; the server converts them, App\Support\MessageVoice).
//
// Recording: with nothing written, 🎤 stands where Send is (CSS, where this browser can record: html.can-record).
// It asks for the microphone and records, showing a bar over the box: a clock, a meter of how loud the microphone
// hears it, Cancel and Send. A microphone that gives only silence shows still bars and, after a moment, "No sound
// yet"; such a recording isn't sent (the server checks again). Recording stops by itself at data-max seconds; Send
// uploads the recording to the Livewire component and calls sendVoice. The bar is left alone by Livewire's redraws
// (wire:ignore); if it's gone anyway (another chat opened), the recording is dropped.
//
// Playing: each voice message has a play/pause button, a bar to see and move through it, and its length (the time
// played while it plays). One plays at a time.
(function () {
  var canRecord = !!(window.MediaRecorder && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  if (canRecord) document.documentElement.classList.add('can-record');

  // What the recording is: the first kind this browser can record (Chrome, Firefox and new Safari: WebM or Ogg with
  // Opus; older Safari: MP4). The server converts any of them.
  var KINDS = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus', 'audio/webm'];

  // The recording in progress: { bar, stream, recorder, chunks, type, started, stopped, timer, sending, and while
  // listening: ctx, frame, measured, loudest }.
  var current = null;

  // Quieter than this at its loudest (decibels below full scale), nobody spoke: App\Support\MessageVoice::SILENT_DB.
  var SILENT_DB = -50;
  var AudioCtx = window.AudioContext || window.webkitAudioContext;

  function clock(seconds) {
    seconds = Math.max(0, Math.floor(seconds || 0));
    return Math.floor(seconds / 60) + ':' + ('0' + (seconds % 60)).slice(-2);
  }

  function kind() {
    for (var i = 0; i < KINDS.length; i++) {
      if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(KINDS[i])) return KINDS[i];
    }
    return '';
  }

  function say(bar, text, problem) {
    bar.querySelector('[data-voice-status]').textContent = text || '';
    bar.classList.toggle('voice-rec-problem', !!problem);
  }

  function hide(bar) {
    bar.hidden = true;
    bar.classList.remove('voice-rec-problem', 'voice-rec-sending', 'voice-rec-stopped');
  }

  // Shows a problem for a few seconds, then gives the box back.
  function problem(bar, text) {
    say(bar, text, true);
    bar.classList.add('voice-rec-stopped');
    setTimeout(function () { if (!current || current.bar !== bar) hide(bar); }, 4000);
  }

  function seconds(rec) {
    return ((rec.stopped || Date.now()) - rec.started) / 1000;
  }

  function release(rec) {
    clearInterval(rec.timer);
    if (rec.frame) cancelAnimationFrame(rec.frame);
    if (rec.stream) rec.stream.getTracks().forEach(function (track) { track.stop(); });
    quiet(rec.ctx);
    rec.bar.querySelectorAll('[data-voice-meter] i').forEach(function (i) { i.style.transform = ''; });
  }

  function quiet(ctx) {
    if (ctx && ctx.state !== 'closed') ctx.close().catch(function () {});
  }

  // Listens to the microphone (Web Audio): moves the meter's bars with the last moments' loudness, and keeps the
  // loudest moment so far. Only while the audio is really being measured (rec.measured).
  function listen(rec, ctx) {
    if (!ctx) return;
    try {
      var analyser = ctx.createAnalyser();
      analyser.fftSize = 1024;
      ctx.createMediaStreamSource(rec.stream).connect(analyser);
      var samples = new Float32Array(analyser.fftSize);
      var bars = rec.bar.querySelectorAll('[data-voice-meter] i');
      var levels = [0, 0, 0, 0, 0];
      var drawn = 0;
      rec.ctx = ctx;
      rec.loudest = -Infinity;
      var frame = function (now) {
        if (rec.stopped) return;
        rec.frame = requestAnimationFrame(frame);
        if (ctx.state !== 'running') return;
        analyser.getFloatTimeDomainData(samples);
        var sum = 0;
        var peak = 0;
        for (var i = 0; i < samples.length; i++) {
          sum += samples[i] * samples[i];
          if (Math.abs(samples[i]) > peak) peak = Math.abs(samples[i]);
        }
        rec.measured = true;
        if (peak > 0) rec.loudest = Math.max(rec.loudest, 20 * Math.log10(peak));
        if (now - drawn < 90) return;
        drawn = now;
        var rms = Math.sqrt(sum / samples.length);
        levels.shift();
        levels.push(rms > 0 ? Math.max(0, Math.min(1, (20 * Math.log10(rms) + 60) / 50)) : 0);
        for (var b = 0; b < bars.length; b++) bars[b].style.transform = 'scaleY(' + (0.15 + 0.85 * levels[b]).toFixed(2) + ')';
      };
      rec.frame = requestAnimationFrame(frame);
    } catch (e) {
      quiet(ctx);
      rec.ctx = null;
    }
  }

  // Whether the microphone was heard to give only silence.
  function silent(rec) {
    return rec.measured && rec.loudest < SILENT_DB;
  }

  // Stops recording (if it still is) and hands over what was recorded.
  function stop(rec, then) {
    function done() {
      release(rec);
      if (then) then(new Blob(rec.chunks, { type: rec.type }));
    }
    if (!rec.stopped) rec.stopped = Date.now();
    if (rec.recorder.state === 'inactive') return done();
    rec.recorder.addEventListener('stop', done, { once: true });
    rec.recorder.stop();
  }

  function start(button) {
    if (current) return;
    var bar = button.closest('.chat-compose') && button.closest('.chat-compose').querySelector('[data-voice-rec]');
    if (!bar) return;
    bar.hidden = false;
    bar.classList.remove('voice-rec-problem', 'voice-rec-sending', 'voice-rec-stopped');
    bar.querySelector('[data-voice-clock]').textContent = '0:00';
    say(bar, bar.getAttribute('data-t-recording'));
    bar.classList.remove('voice-rec-quiet');
    // Made during the tap, so phones let it run.
    var ctx = null;
    try { ctx = AudioCtx ? new AudioCtx() : null; if (ctx && ctx.resume) ctx.resume().catch(function () {}); } catch (e) { ctx = null; }
    var waiting = { bar: bar, ctx: ctx };
    current = waiting;

    navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true } }).then(function (stream) {
      if (current !== waiting) { stream.getTracks().forEach(function (track) { track.stop(); }); quiet(ctx); return; }
      var type = kind();
      var options = { audioBitsPerSecond: 64000 };
      if (type) options.mimeType = type;
      var recorder = new MediaRecorder(stream, options);
      var rec = { bar: bar, stream: stream, recorder: recorder, chunks: [], type: recorder.mimeType || type || 'audio/webm', started: Date.now() };
      recorder.addEventListener('dataavailable', function (e) { if (e.data && e.data.size) rec.chunks.push(e.data); });
      recorder.start(1000);
      current = rec;
      listen(rec, ctx);
      var max = parseInt(bar.getAttribute('data-max'), 10) || 300;
      rec.timer = setInterval(function () {
        if (!bar.isConnected) return cancel(rec);
        bar.querySelector('[data-voice-clock]').textContent = clock(seconds(rec));
        // Nothing heard after a few seconds: say so (while still recording), until something is.
        var hush = !rec.stopped && seconds(rec) > 2.5 && silent(rec);
        if (hush !== bar.classList.contains('voice-rec-quiet')) {
          bar.classList.toggle('voice-rec-quiet', hush);
          say(bar, bar.getAttribute(hush ? 'data-t-no-sound' : 'data-t-recording'));
        }
        if (!rec.stopped && seconds(rec) >= max) {
          stop(rec);
          bar.classList.add('voice-rec-stopped');
          say(bar, bar.getAttribute('data-t-limit'));
        }
      }, 250);
    }, function () {
      if (current === waiting) current = null;
      quiet(ctx);
      problem(bar, bar.getAttribute('data-t-mic'));
    });
  }

  function cancel(rec) {
    if (!rec) return;
    if (rec.recorder) stop(rec);
    else quiet(rec.ctx);
    if (current === rec) current = null;
    hide(rec.bar);
  }

  // The Livewire component the box belongs to ($wire).
  function component(el) {
    var root = el.closest('[wire\\:id]');
    return root && window.Livewire ? window.Livewire.find(root.getAttribute('wire:id')) : null;
  }

  function send(rec) {
    if (!rec || !rec.recorder || rec.sending) return;
    rec.sending = true;
    var bar = rec.bar;
    stop(rec, function (blob) {
      if (current === rec) current = null;
      bar.classList.remove('voice-rec-quiet');
      if (seconds(rec) < 1 || !blob.size) return problem(bar, bar.getAttribute('data-t-short'));
      if (silent(rec)) return problem(bar, bar.getAttribute('data-t-silent'));
      var wire = component(bar);
      if (!wire) return problem(bar, bar.getAttribute('data-t-error'));

      var ext = /mp4/.test(rec.type) ? 'm4a' : /ogg/.test(rec.type) ? 'ogg' : 'webm';
      var file = new File([blob], 'voice.' + ext, { type: rec.type.split(';')[0] });
      bar.classList.add('voice-rec-sending');
      say(bar, bar.getAttribute('data-t-sending'));
      wire.upload('voice', file, function () {
        wire.call('sendVoice').then(function () { hide(bar); }, function () { problem(bar, bar.getAttribute('data-t-error')); });
      }, function () {
        problem(bar, bar.getAttribute('data-t-error'));
      }, function (e) {
        var progress = e && e.detail ? e.detail.progress : null;
        if (progress != null) say(bar, bar.getAttribute('data-t-sending') + ' ' + progress + '%');
      });
    });
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var record = e.target.closest('[data-voice-record]');
    if (record && canRecord) { e.preventDefault(); start(record); return; }
    if (e.target.closest('[data-voice-cancel]')) { e.preventDefault(); cancel(current); return; }
    if (e.target.closest('[data-voice-send]')) { e.preventDefault(); send(current); return; }

    var toggle = e.target.closest('[data-voice-toggle]');
    if (toggle) { play(toggle.closest('[data-voice-player]')); return; }
    var track = e.target.closest('[data-voice-seek]');
    if (track) seek(track.closest('[data-voice-player]'), track, e.clientX);
  });

  // Players.
  function length(player, audio) {
    return isFinite(audio.duration) && audio.duration > 0 ? audio.duration : parseFloat(player.getAttribute('data-seconds')) || 0;
  }

  function play(player) {
    var audio = player && player.querySelector('audio');
    if (!audio) return;
    if (!audio.paused) return audio.pause();
    document.querySelectorAll('[data-voice-player] audio').forEach(function (other) { if (other !== audio) other.pause(); });
    var playing = audio.play();
    if (playing && playing.catch) playing.catch(function () {});
  }

  function seek(player, track, x) {
    var audio = player && player.querySelector('audio');
    var total = audio ? length(player, audio) : 0;
    if (!total) return;
    var box = track.getBoundingClientRect();
    var at = Math.min(1, Math.max(0, (x - box.left) / box.width)) * total;
    if (audio.readyState > 0) {
      audio.currentTime = at;
    } else {
      audio.addEventListener('loadedmetadata', function () { audio.currentTime = at; }, { once: true });
      audio.preload = 'auto';
      audio.load();
    }
    draw(player, audio, at);
  }

  function draw(player, audio, at) {
    var total = length(player, audio);
    var now = at != null ? at : audio.currentTime;
    player.querySelector('.voice-fill').style.width = (total ? Math.min(100, (now / total) * 100) : 0) + '%';
    player.querySelector('[data-voice-time]').textContent = clock(now > 0 && !audio.ended ? now : total);
    var playing = !audio.paused && !audio.ended;
    player.classList.toggle('playing', playing);
    var toggle = player.querySelector('[data-voice-toggle]');
    toggle.setAttribute('aria-label', toggle.getAttribute(playing ? 'data-t-pause' : 'data-t-play'));
  }

  // Media events don't bubble, so they're caught on the way down.
  ['play', 'pause', 'timeupdate', 'ended', 'loadedmetadata'].forEach(function (type) {
    document.addEventListener(type, function (e) {
      var audio = e.target;
      var player = audio && audio.closest && audio.closest('[data-voice-player]');
      if (!player) return;
      if (type === 'ended') { audio.currentTime = 0; draw(player, audio, 0); return; }
      draw(player, audio);
    }, true);
  });
})();
