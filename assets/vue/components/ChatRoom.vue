<template>
  <section class="chat-room card" aria-label="Chat">
    <header class="chat-head">
      <h1 class="chat-title">Chat</h1>
      <span v-if="disconnected" class="chat-offline" role="status">Reconnexion…</span>
      <div class="chat-online-wrap">
        <ul class="chat-online" aria-label="Membres en ligne">
          <li v-for="member in visibleOnline" :key="member.id" :title="member.id === me.id ? `${member.username} (vous)` : member.username">
            <Avatar :member="member" size="chat-avatar-sm" />
          </li>
          <li v-if="hiddenOnline > 0" class="chat-more" :title="`${hiddenOnline} autre(s) membre(s) en ligne`">+{{ hiddenOnline }}</li>
        </ul>
        <span class="chat-online-count">{{ onlineMembers.length }} en ligne</span>
      </div>
    </header>

    <div class="chat-stage">
      <div ref="scroller" class="chat-scroll" role="log" :aria-busy="loadingOlder" @scroll.passive="onScroll">
        <div ref="content" class="chat-content">
          <div v-if="hasOlder" class="chat-edge">
            <span v-if="loadingOlder">Chargement des messages précédents…</span>
            <button v-else type="button" class="chat-link" @click="loadOlder">Afficher les messages précédents</button>
          </div>
          <p v-else-if="history.length" class="chat-edge">Début de la conversation</p>
          <p v-if="!history.length" class="chat-edge">Aucun message pour l’instant. Écrivez le premier !</p>

          <template v-for="item in items" :key="item.key">
            <div v-if="item.type === 'day'" class="chat-day"><span>{{ item.label }}</span></div>
            <article v-else class="chat-group" :class="{ 'is-mine': item.mine }">
              <Avatar :member="item.author" size="chat-avatar" />
              <div class="chat-body">
                <header class="chat-meta">
                  <strong class="chat-name">{{ item.mine ? 'Vous' : item.name }}</strong>
                  <time :datetime="item.iso" :title="item.full">{{ item.time }}</time>
                </header>
                <p v-for="message in item.messages" :key="message.id" class="chat-bubble" :title="message.title"><template v-for="(part, index) in message.parts" :key="index"><a v-if="part.href" :href="part.href" target="_blank" rel="noopener noreferrer nofollow">{{ part.text }}</a><template v-else>{{ part.text }}</template></template></p>
              </div>
            </article>
          </template>
        </div>
      </div>

      <button v-if="unseen.size > 0" type="button" class="chat-pill" @click="scrollToBottom(true)">
        <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg>
        {{ unseen.size }} {{ unseen.size > 1 ? 'nouveaux messages' : 'nouveau message' }}
      </button>
    </div>

    <form class="chat-composer" @submit.prevent="send">
      <label class="sr-only" for="chat-input">Votre message</label>
      <textarea
        id="chat-input"
        ref="input"
        v-model="draft"
        class="input chat-input"
        rows="1"
        :maxlength="maxLength"
        :disabled="sending"
        placeholder="Écrire un message…"
        title="Entrée pour envoyer, Maj+Entrée pour un retour à la ligne"
        enterkeyhint="send"
        autocomplete="off"
        @keydown="onKeydown"
        @input="autosize"
      ></textarea>
      <button type="submit" class="btn btn-primary chat-send" :disabled="sending || !draft.trim()" aria-label="Envoyer">
        <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
        <span class="hidden sm:inline">Envoyer</span>
      </button>
    </form>
    <p v-if="error" class="chat-error field-error" role="alert">{{ error }}</p>
    <p v-else-if="remaining < 200" class="chat-counter" :class="{ 'text-danger': remaining < 0 }">{{ remaining }} caractères restants</p>
  </section>
</template>

<script setup>
import { computed, h, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

const props = defineProps({
  messages: { type: Array, default: () => [] },
  hasMore: { type: Boolean, default: false },
  me: { type: Object, required: true },
  online: { type: Array, default: () => [] },
  maxLength: { type: Number, default: 2000 },
  csrf: { type: String, default: '' },
  lastReadId: { type: Number, default: 0 },
  urls: { type: Object, required: true },
});

const TIME_ZONE = 'Europe/Paris';
const GROUP_GAP = 10 * 60 * 1000; // a pause longer than this starts a new group even for the same author
const BOTTOM_TOLERANCE = 48; // px from the bottom still counted as "at the bottom"
const MAX_AVATARS = 8;

// ---- Formatting (everything is displayed in Paris time, in French) ----
const dayKeyFormat = new Intl.DateTimeFormat('en-CA', { timeZone: TIME_ZONE, year: 'numeric', month: '2-digit', day: '2-digit' });
const timeFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: TIME_ZONE, hour: '2-digit', minute: '2-digit' });
const fullFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: TIME_ZONE, dateStyle: 'full', timeStyle: 'short' });
const dayFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: TIME_ZONE, weekday: 'long', day: 'numeric', month: 'long' });
const dayYearFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: TIME_ZONE, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

const dayKey = (date) => dayKeyFormat.format(date); // "2026-10-17"
const dayNumber = (key) => { const [y, m, d] = key.split('-').map(Number); return Date.UTC(y, m - 1, d) / 86_400_000; };

function dayLabel(key, todayKey) {
  const diff = dayNumber(todayKey) - dayNumber(key);
  if (diff === 0) return 'Aujourd’hui';
  if (diff === 1) return 'Hier';
  const label = (key.slice(0, 4) === todayKey.slice(0, 4) ? dayFormat : dayYearFormat).format(new Date(`${key}T12:00:00Z`));
  return label.charAt(0).toUpperCase() + label.slice(1);
}

// ---- Plain text -> safe fragments. Messages are NEVER rendered as HTML: only text nodes and <a> built by Vue. ----
const URL_PATTERN = /https?:\/\/[^\s<>"'`]+/gi;
const linkCache = new Map();

function trimUrl(url) {
  let end = url.length;
  while (end > 0) {
    const last = url[end - 1];
    const head = url.slice(0, end);
    const count = (char) => head.split(char).length - 1;
    if ('.,;:!?*_~»…'.includes(last)
      || (last === ')' && count(')') > count('('))
      || (last === ']' && count(']') > count('['))) {
      end -= 1;
    } else {
      break;
    }
  }
  return url.slice(0, end);
}

function linkify(text) {
  const parts = [];
  let cursor = 0;
  for (const match of text.matchAll(URL_PATTERN)) {
    const url = trimUrl(match[0]);
    let href = null;
    try {
      const parsed = new URL(url);
      if (parsed.protocol === 'http:' || parsed.protocol === 'https:') href = parsed.href;
    } catch { /* not a URL */ }
    if (!href) continue;
    if (match.index > cursor) parts.push({ text: text.slice(cursor, match.index) });
    parts.push({ text: url, href });
    cursor = match.index + url.length;
  }
  if (cursor < text.length) parts.push({ text: text.slice(cursor) });
  return parts;
}

function partsOf(message) {
  let parts = linkCache.get(message.id);
  if (!parts) {
    parts = linkify(message.content || '');
    linkCache.set(message.id, parts);
  }
  return parts;
}

// ---- Avatar (photo, or coloured initial like templates/_ui/avatar.html.twig) ----
const Avatar = (avatarProps) => {
  const member = avatarProps.member;
  if (member && member.avatarUrl) {
    return h('img', { src: member.avatarUrl, alt: member.username, width: 96, height: 96, loading: 'lazy', class: ['chat-avatar-img', avatarProps.size] });
  }
  return h('span', {
    class: ['chat-avatar-img', 'chat-avatar-initial', avatarProps.size],
    'aria-hidden': member ? undefined : 'true',
    style: member ? { backgroundColor: `hsl(${member.hue} 42% 42%)` } : undefined,
  }, member ? member.username.slice(0, 1).toUpperCase() : '?');
};
Avatar.props = ['member', 'size'];

// ---- State ----
const history = ref([...props.messages]); // oldest first
const ids = new Set(history.value.map((m) => m.id));
const hasOlder = ref(props.hasMore);
const loadingOlder = ref(false);
const unseen = reactive(new Set()); // ids of messages of others that arrived while the reader was scrolled up (pill)
const seenIds = new Set(); // ids shown to a visible reader at the bottom, or written by this member
const draft = ref('');
const sending = ref(false);
const error = ref('');
const disconnected = ref(false);
const onlineMembers = ref(sortMembers([...props.online]));
const now = ref(Date.now());

const scroller = ref(null);
const content = ref(null);
const input = ref(null);

let stick = true; // the reader is at the bottom: follow new messages
let lastPostedRead = props.lastReadId;
let readTimer = null;
let catchingUp = false;
let everConnected = false;
let resizeObserver = null;
let onlineTimer = null;
let clockTimer = null;

const remaining = computed(() => props.maxLength - Array.from(draft.value).length);
const visibleOnline = computed(() => onlineMembers.value.slice(0, MAX_AVATARS));
const hiddenOnline = computed(() => Math.max(0, onlineMembers.value.length - MAX_AVATARS));

const items = computed(() => {
  const todayKey = dayKey(new Date(now.value));
  const out = [];
  let currentDay = null;
  let group = null;

  for (const message of history.value) {
    const date = new Date(message.createdAt);
    const valid = !Number.isNaN(date.getTime());
    const day = valid ? dayKey(date) : 'unknown';
    if (day !== currentDay) {
      currentDay = day;
      group = null;
      if (valid) out.push({ type: 'day', key: `day-${day}`, label: dayLabel(day, todayKey) });
    }

    const authorId = message.author ? message.author.id : null;
    if (!group || group.authorId !== authorId || date - group.lastAt > GROUP_GAP) {
      group = {
        type: 'group',
        key: `group-${message.id}`,
        authorId,
        author: message.author,
        name: message.author ? message.author.username : 'Ancien membre',
        mine: authorId !== null && authorId === props.me.id,
        iso: message.createdAt,
        time: valid ? timeFormat.format(date) : '',
        full: valid ? fullFormat.format(date) : '',
        lastAt: date,
        messages: [],
      };
      out.push(group);
    }
    group.lastAt = date;
    group.messages.push({ id: message.id, parts: partsOf(message), title: valid ? fullFormat.format(date) : '' });
  }
  return out;
});

function sortMembers(list) {
  return list.sort((a, b) => a.username.localeCompare(b.username, 'fr', { sensitivity: 'base' }));
}

const newestId = () => (history.value.length ? history.value[history.value.length - 1].id : 0);
const isVisible = () => document.visibilityState === 'visible';

// ---- HTTP ----
async function request(url, body) {
  const response = await fetch(url, {
    method: body === undefined ? 'GET' : 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(body === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-Token': props.csrf }),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const isJson = (response.headers.get('Content-Type') || '').includes('json');
  const data = isJson ? await response.json() : null;
  if (!response.ok || data === null) {
    const failure = new Error(data && data.error ? data.error : 'Votre session a expiré : rechargez la page.');
    failure.status = response.status;
    throw failure;
  }
  return data;
}

// ---- Scrolling ----
const atBottom = () => {
  const el = scroller.value;
  return el.scrollHeight - el.scrollTop - el.clientHeight < BOTTOM_TOLERANCE;
};

function scrollToBottom(markAsRead = false) {
  const el = scroller.value;
  if (!el) return;
  el.scrollTop = el.scrollHeight;
  stick = true;
  unseen.clear();
  if (markAsRead) markRead(true);
}

let scrollFrame = 0;
function onScroll() {
  if (scrollFrame) return;
  scrollFrame = requestAnimationFrame(() => {
    scrollFrame = 0;
    if (!scroller.value) return;
    stick = atBottom();
    if (stick) {
      unseen.clear();
      markRead();
    }
    if (scroller.value.scrollTop < 160) loadOlder();
  });
}

async function loadOlder() {
  if (loadingOlder.value || !hasOlder.value || !history.value.length) return;
  loadingOlder.value = true;
  try {
    const data = await request(`${props.urls.messages}?before=${history.value[0].id}`);
    const el = scroller.value;
    const previousHeight = el.scrollHeight;
    const previousTop = el.scrollTop;
    const fresh = data.messages.filter((m) => !ids.has(m.id));
    fresh.forEach((m) => ids.add(m.id));
    history.value = [...fresh, ...history.value];
    hasOlder.value = data.hasMore;
    await nextTick();
    // Keep the reader on the message they were looking at.
    el.scrollTop = previousTop + (el.scrollHeight - previousHeight);
    stick = atBottom();
  } catch {
    // Network hiccup: the button stays available and scrolling up again retries.
  } finally {
    loadingOlder.value = false;
  }
  await nextTick();
  fillViewport();
}

/** Short history (or tall screen): keep loading until the list can scroll. */
function fillViewport() {
  const el = scroller.value;
  if (el && hasOlder.value && !loadingOlder.value && el.scrollHeight <= el.clientHeight + 1) loadOlder();
}

// ---- Messages ----
/** Adds a message (history is sorted by id, no duplicates). Returns false when it was already displayed. */
function insert(message) {
  if (ids.has(message.id)) return false;
  ids.add(message.id);
  const list = history.value;
  let index = list.length;
  while (index > 0 && list[index - 1].id > message.id) index -= 1;
  list.splice(index, 0, message);
  return true;
}

function noteOnline(author) {
  if (author && !onlineMembers.value.some((m) => m.id === author.id)) {
    onlineMembers.value = sortMembers([...onlineMembers.value, author]);
  }
}

/** Displays a message that just arrived (live or catch-up) and keeps the reader's position sensible. */
async function receive(message) {
  if (!insert(message)) return;
  noteOnline(message.author);
  const mine = message.author && message.author.id === props.me.id;
  const readerSees = stick && isVisible();
  if (readerSees || mine) seenIds.add(message.id);
  if (stick) {
    // Stay at the bottom, even in a background tab: nothing to catch up on when the reader comes back.
    await nextTick();
    scrollToBottom();
    if (readerSees) markRead();
  } else if (!mine) {
    unseen.add(message.id);
  }
}

function onLiveMessage(event) {
  const { message } = event.detail;
  receive(message);
  // Tells the nav badge whether the reader saw it (the badge only counts what was not seen).
  event.detail.seen = seenIds.has(message.id);
}

async function catchUp() {
  if (catchingUp) return;
  catchingUp = true;
  try {
    let more = true;
    while (more) {
      const data = await request(`${props.urls.messages}?after=${newestId()}`);
      for (const message of data.messages) await receive(message);
      more = data.hasMore && data.messages.length > 0;
    }
  } catch {
    // Retried at the next reconnection / when the tab becomes visible again.
  } finally {
    catchingUp = false;
  }
}

function onStatus(event) {
  if (event.detail.connected) {
    everConnected = true;
    disconnected.value = false;
    catchUp(); // also covers the gap between the page render and the subscription
  } else if (everConnected) {
    disconnected.value = true;
  }
}

// ---- Read marker ----
function markRead(immediate = false) {
  clearTimeout(readTimer);
  const post = async () => {
    const id = newestId();
    if (!id || id <= lastPostedRead || !isVisible() || !stick) return;
    lastPostedRead = id;
    try {
      const data = await request(props.urls.read, { lastId: id });
      document.dispatchEvent(new CustomEvent('chat:read', { detail: data }));
    } catch {
      lastPostedRead = Math.min(lastPostedRead, id - 1); // try again next time
    }
  };
  if (immediate) post();
  else readTimer = setTimeout(post, 250);
}

function onVisibilityChange() {
  if (!isVisible()) return;
  catchUp();
  refreshOnline();
  if (scroller.value) {
    stick = atBottom();
    if (stick) {
      unseen.clear();
      markRead(true);
    }
  }
}

// ---- Online members ----
async function refreshOnline() {
  try {
    onlineMembers.value = sortMembers((await request(props.urls.online)).members);
  } catch {
    // Keep the previous list.
  }
}

// ---- Composer ----
function autosize() {
  const el = input.value;
  if (!el) return;
  el.style.height = 'auto';
  el.style.height = `${Math.min(el.scrollHeight + 2, 160)}px`;
}

function onKeydown(event) {
  if (event.key === 'Enter' && !event.shiftKey && !event.ctrlKey && !event.altKey && !event.metaKey && !event.isComposing) {
    event.preventDefault();
    send();
  }
}

async function send() {
  const text = draft.value.trim();
  if (sending.value || text === '') return;
  if (Array.from(text).length > props.maxLength) {
    error.value = `Le message est trop long (${props.maxLength} caractères maximum).`;
    return;
  }

  sending.value = true;
  error.value = '';
  try {
    const message = await request(props.urls.send, { content: text });
    draft.value = '';
    seenIds.add(message.id);
    if (insert(message)) {
      await nextTick();
    }
    scrollToBottom(true);
  } catch (failure) {
    error.value = failure.message || 'Le message n’a pas pu être envoyé.';
  } finally {
    sending.value = false;
    await nextTick();
    autosize();
    input.value?.focus();
  }
}

// ---- Lifecycle ----
onMounted(() => {
  scrollToBottom();
  autosize();
  if (!window.matchMedia('(pointer: coarse)').matches) input.value?.focus(); // no soft keyboard popping up on phones

  document.addEventListener('chat:message', onLiveMessage);
  document.addEventListener('chat:status', onStatus);
  document.addEventListener('visibilitychange', onVisibilityChange);

  // Keep the bottom in view when the viewport, the composer or late-loading content change heights.
  resizeObserver = new ResizeObserver(() => {
    if (stick) scrollToBottom();
  });
  resizeObserver.observe(scroller.value);
  resizeObserver.observe(content.value);

  onlineTimer = setInterval(() => { if (isVisible()) refreshOnline(); }, 60_000);
  clockTimer = setInterval(() => { now.value = Date.now(); }, 60_000);

  markRead(true); // opening the page marks everything up to the newest message as read
  fillViewport();
});

onBeforeUnmount(() => {
  document.removeEventListener('chat:message', onLiveMessage);
  document.removeEventListener('chat:status', onStatus);
  document.removeEventListener('visibilitychange', onVisibilityChange);
  resizeObserver?.disconnect();
  clearInterval(onlineTimer);
  clearInterval(clockTimer);
  clearTimeout(readTimer);
  cancelAnimationFrame(scrollFrame);
});
</script>
