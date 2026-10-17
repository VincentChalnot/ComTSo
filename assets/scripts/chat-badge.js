// Live unread-messages badge of the navigation, loaded on every page (owned by the chat feature).
//
// It is also the ONLY Mercure connection of the page: the chat page (ChatRoom.vue) listens to the events below instead
// of opening a second EventSource.
//   document 'chat:message'  detail {message, seen}  a message of another member (or ours from another tab) arrived;
//                                                    a listener that displays it sets detail.seen = true
//   document 'chat:status'   detail {connected}      the real-time connection came up / went down
//   document 'chat:read'     detail {lastReadId, latestId, unread}   fired BY the chat page after it marked the chat read
//
// The forum is private: the hub only talks to members holding the subscriber cookie that GET /chat/state sets, and every
// chat update is private. Hub downtime or reconnections are handled silently (back-off, then resync from /chat/state).

const STATE_URL = '/chat/state';
const MAX_BACKOFF = 60_000;

const badge = document.querySelector('[data-chat-badge]');
if (badge) {
  start(badge);
}

function start(badge) {
  const onChatPage = document.querySelector('[data-vue-component="ChatRoom"]') !== null;
  const baseTitle = document.title;

  // What the server last told us. `lastReadId` and `latestId` only grow: they order the snapshots we get.
  const chat = { userId: null, lastReadId: 0, latestId: 0, unread: Number(badge.dataset.count) || 0 };
  let source = null;
  let retryTimer = null;
  let failures = 0;
  let connected = false;

  const emit = (name, detail) => document.dispatchEvent(new CustomEvent(name, { detail }));

  function render() {
    const label = chat.unread > 99 ? '99+' : String(chat.unread);
    badge.dataset.count = String(chat.unread);
    badge.textContent = label;
    badge.hidden = badge.classList.toggle('hidden', chat.unread === 0 || onChatPage);
    document.title = chat.unread > 0 ? `(${label}) ${baseTitle}` : baseTitle;
  }

  /** Applies a server snapshot unless a more recent one was already applied. */
  function apply(snapshot) {
    const newer = snapshot.lastReadId > chat.lastReadId
      || (snapshot.lastReadId === chat.lastReadId && snapshot.latestId >= chat.latestId);
    if (!newer) {
      return;
    }
    chat.lastReadId = snapshot.lastReadId;
    chat.latestId = Math.max(chat.latestId, snapshot.latestId);
    chat.unread = snapshot.unread;
    render();
  }

  function setConnected(value) {
    if (connected !== value) {
      connected = value;
      emit('chat:status', { connected });
    }
  }

  async function fetchState() {
    const response = await fetch(STATE_URL, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!response.ok || !(response.headers.get('Content-Type') || '').includes('json')) {
      throw new Error(`state ${response.status}`); // session expired (redirect to the login form) or server error
    }
    const state = await response.json();
    chat.userId = state.userId;
    apply(state);

    return state;
  }

  function onUpdate(event) {
    let data;
    try {
      data = JSON.parse(event.data);
    } catch {
      return;
    }

    if (data.type === 'read') {
      apply(data);
    } else if (data.type === 'message' && data.message) {
      const message = data.message;
      const own = message.author && message.author.id === chat.userId;
      const detail = { message, seen: false };
      emit('chat:message', detail);
      if (message.id > chat.latestId) {
        chat.latestId = message.id;
        if (!own && !detail.seen) {
          chat.unread += 1;
        }
        render();
      }
    }
  }

  function schedule() {
    clearTimeout(retryTimer);
    const delay = Math.min(MAX_BACKOFF, 2000 * 2 ** failures) * (0.75 + Math.random() * 0.5);
    failures += 1;
    retryTimer = setTimeout(connect, delay);
  }

  function disconnect() {
    if (source) {
      source.onopen = source.onmessage = source.onerror = null;
      source.close();
      source = null;
    }
    setConnected(false);
  }

  /** (Re)connects: /chat/state refreshes the subscriber cookie, resynchronises the count, then the stream opens. */
  async function connect() {
    clearTimeout(retryTimer);
    disconnect();
    let state;
    try {
      state = await fetchState();
    } catch {
      schedule();
      return;
    }

    const url = new URL(state.hubUrl, location.origin);
    state.topics.forEach((topic) => url.searchParams.append('topic', topic));
    source = new EventSource(url, { withCredentials: true });
    source.onopen = () => {
      failures = 0;
      setConnected(true);
      // Whatever was published between the snapshot above and the subscription is not replayed: resync.
      fetchState().catch(() => {});
    };
    source.onmessage = onUpdate;
    source.onerror = () => {
      // Our own back-off instead of the browser's fixed 3 s retry: also renews an expired subscriber cookie.
      disconnect();
      schedule();
    };
  }

  // The chat page marked the chat as read: update the count immediately, without waiting for the hub round-trip.
  document.addEventListener('chat:read', (event) => apply(event.detail));
  // Mobile browsers kill background connections; come back to life as soon as the tab is visible again.
  const wake = () => {
    if (!connected && document.visibilityState === 'visible') {
      connect();
    }
  };
  document.addEventListener('visibilitychange', wake);
  window.addEventListener('online', wake);
  // A closed tab must not leave a half-open connection behind.
  window.addEventListener('pagehide', disconnect);
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
      connect(); // restored from the back/forward cache
    }
  });

  render();
  connect();
}
