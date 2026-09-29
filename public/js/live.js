// Live comment features for signed-in members (Laravel Reverb through Echo):
// shows "Name is typing…" under the comment box and refreshes the list when someone posts.
(function () {
  var cfg = window.HL_LIVE;
  var EchoClass = window.Echo && (window.Echo.default || window.Echo);
  if (!cfg || !cfg.key || typeof EchoClass !== 'function' || !window.Pusher) {
    // Don't leave the library object on window.Echo: Livewire would treat it as a live connection.
    window.Echo = undefined;
    return;
  }

  var echo = new EchoClass({
    broadcaster: 'reverb',
    key: cfg.key,
    wsHost: cfg.host,
    wsPort: cfg.port,
    wssPort: cfg.port,
    forceTLS: cfg.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    auth: { headers: { 'X-CSRF-TOKEN': cfg.csrf } }
  });
  // Livewire and other Laravel code expect window.Echo to be the connected instance.
  window.Echo = echo;

  var TYPING_TTL = 4000;   // hide a name this long after their last keystroke
  var SEND_EVERY = 1500;   // send at most one "typing" signal per this many ms

  function setup(box) {
    var entryId = box.getAttribute('data-live-comments');
    var members = {};      // id -> name, from the presence channel (not from the whisper)
    var typing = {};       // id -> timeout handle
    var lastSent = 0;

    function render() {
      var line = box.querySelector('.typing');
      if (!line) return;
      var names = Object.keys(typing).map(function (id) { return members[id]; }).filter(Boolean);
      var text = '';
      if (names.length === 1) text = line.dataset.typingOne.replace(':name', names[0]);
      else if (names.length === 2) text = line.dataset.typingTwo.replace(':a', names[0]).replace(':b', names[1]);
      else if (names.length > 2) text = line.dataset.typingMany;
      line.textContent = text;
    }

    function stop(id) {
      clearTimeout(typing[id]);
      delete typing[id];
      render();
    }

    var channel = echo.join('comments.' + entryId)
      .here(function (list) { list.forEach(function (m) { members[m.id] = m.name; }); })
      .joining(function (m) { members[m.id] = m.name; })
      .leaving(function (m) { stop(m.id); delete members[m.id]; })
      .listenForWhisper('typing', function (e) {
        clearTimeout(typing[e.id]);
        typing[e.id] = setTimeout(function () { stop(e.id); }, TYPING_TTL);
        render();
      })
      .listenForWhisper('stopped', function (e) { stop(e.id); })
      .listenForWhisper('posted', function (e) {
        stop(e.id);
        var el = box.closest('[wire\\:id]');
        if (el && window.Livewire) window.Livewire.find(el.getAttribute('wire:id')).$refresh();
      });

    box.addEventListener('input', function (e) {
      if (!e.target.matches('textarea')) return;
      var now = Date.now();
      if (e.target.value.trim() === '') { channel.whisper('stopped', { id: cfg.userId }); lastSent = 0; return; }
      if (now - lastSent < SEND_EVERY) return;
      lastSent = now;
      channel.whisper('typing', { id: cfg.userId });
    });

    // Sent by the Comments component once a comment is saved.
    window.addEventListener('comment-posted', function (e) {
      if (!e.detail || e.detail.entryId !== entryId) return;
      lastSent = 0;
      channel.whisper('posted', { id: cfg.userId });
    });
  }

  function init() {
    document.querySelectorAll('[data-live-comments]').forEach(function (box) {
      if (box.dataset.liveReady) return;
      box.dataset.liveReady = '1';
      setup(box);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
