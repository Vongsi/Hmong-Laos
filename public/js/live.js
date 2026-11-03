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

  // Site-wide "online" presence: the live count in the header and on the Members page, and the
  // green dot on listed members who have the site open. The dots are CSS rules rather than classes
  // so they survive Livewire redrawing the list.
  var dots = document.createElement('style');
  document.head.appendChild(dots);
  var online = echo.join('online');
  function showOnline() {
    var members = online.subscription && online.subscription.members;
    if (!members) return;
    var selectors = [];
    members.each(function (m) { selectors.push('.member[data-member-key="' + m.id + '"]'); });
    dots.textContent = selectors.length
      ? selectors.map(function (s) { return s + ' .presence'; }).join(',') + '{display:block}' +
        selectors.map(function (s) { return s + ' .status-live'; }).join(',') + '{display:inline}' +
        selectors.map(function (s) { return s + ' .status-seen'; }).join(',') + '{display:none}'
      : '';
    document.querySelectorAll('[data-online-count]').forEach(function (el) { el.textContent = members.count; });
    document.querySelectorAll('[data-online-pill]').forEach(function (el) { el.hidden = false; });
  }
  online.here(showOnline).joining(showOnline).leaving(showOnline);

  // Private messages (App\Support\Messaging), on this member's own channel. A new message, or a change (read, edited,
  // deleted, group members), redraws the Messages page (which also updates the badges), or elsewhere just the unread
  // badges on My account.
  var chats = {};          // open conversation id -> { channel, line, hide, lastSent }
  function messagesPage() { return window.Livewire && document.querySelector('.chat'); }
  function refreshUnread() {
    fetch('/messages/unread', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (data) window.dispatchEvent(new CustomEvent('messages-unread', { detail: { count: data.count } }));
      })
      .catch(function () {});
  }
  echo.private('App.Models.User.' + cfg.userId)
    .listen('.message.sent', function (e) {
      var chat = chats[e.conversation];
      if (chat) { clearTimeout(chat.hide); chat.line.textContent = ''; }
      if (messagesPage()) window.Livewire.dispatch('message-received', { conversation: e.conversation });
      else refreshUnread();
    })
    .listen('.conversation.updated', function (e) {
      if (messagesPage()) window.Livewire.dispatch('conversation-updated', { conversation: e.conversation });
      else refreshUnread();
    });

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

  // "… is typing" in the open conversation on the Messages page, over its private channel (routes/channels.php).
  // The page lists the members' names by id (data-names); in a group, the latest one typing is shown.
  function setupChat(line) {
    var id = line.getAttribute('data-live-chat');
    var chat = { line: line, hide: null, lastSent: 0 };
    function nameOf(e) {
      try { return JSON.parse(line.dataset.names || '{}')[e && e.id] || ''; } catch (err) { return ''; }
    }
    chat.channel = echo.private('conversation.' + id)
      .listenForWhisper('typing', function (e) {
        var name = nameOf(e);
        if (!name) return;
        line.textContent = line.dataset.typing.replace(':name', name);
        clearTimeout(chat.hide);
        chat.hide = setTimeout(function () { line.textContent = ''; }, TYPING_TTL);
      })
      .listenForWhisper('stopped', function () { clearTimeout(chat.hide); line.textContent = ''; });
    chats[id] = chat;
  }
  function openChat() {
    for (var id in chats) if (chats[id].line.isConnected) return chats[id];
    return null;
  }
  document.addEventListener('input', function (e) {
    if (!e.target.matches || !e.target.matches('[data-chat-input]')) return;
    var chat = openChat();
    if (!chat) return;
    var now = Date.now();
    if (e.target.value.trim() === '') { chat.channel.whisper('stopped', { id: cfg.userId }); chat.lastSent = 0; return; }
    if (now - chat.lastSent < SEND_EVERY) return;
    chat.lastSent = now;
    chat.channel.whisper('typing', { id: cfg.userId });
  });
  window.addEventListener('chat-sent', function () {
    var chat = openChat();
    if (chat) { chat.lastSent = 0; chat.channel.whisper('stopped', { id: cfg.userId }); }
  });

  function init() {
    document.querySelectorAll('[data-live-comments]').forEach(function (box) {
      if (box.dataset.liveReady) return;
      box.dataset.liveReady = '1';
      setup(box);
    });
    // Conversations open and close as the member moves around the Messages page.
    for (var id in chats) {
      if (!chats[id].line.isConnected) { echo.leave('conversation.' + id); delete chats[id]; }
    }
    document.querySelectorAll('[data-live-chat]').forEach(function (line) {
      if (line.dataset.liveReady) return;
      line.dataset.liveReady = '1';
      setupChat(line);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
  // Livewire redraws (the Messages page) add and remove conversations: look again shortly after.
  var again;
  new MutationObserver(function () { clearTimeout(again); again = setTimeout(init, 150); })
    .observe(document.body, { childList: true, subtree: true });
})();
