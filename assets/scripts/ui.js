// Vanilla UI behaviours: mobile nav, flash dismissal, collapsible messages, tap-to-reveal details,
// favourites toggle, confirmations, auto-submit, the photo lightbox and the inline thread previews.
// Everything is progressive enhancement: pages stay usable with JavaScript disabled.

const ready = (fn) => (document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', fn) : fn());

const ICON = {
  chevronDown: '<svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
  chevronUp: '<svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 15-6-6-6 6"/></svg>',
  left: '<svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>',
  right: '<svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>',
  close: '<svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>',
  download: '<svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>',
  heart: '<svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>',
  page: '<svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>',
};

/* ------------------------------------------------------------------ navigation */
function initNav() {
  const toggle = document.querySelector('[data-nav-toggle]');
  const menu = document.querySelector('[data-nav-menu]');
  const setOpen = (open) => {
    menu.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
  };
  if (toggle && menu) {
    toggle.addEventListener('click', () => setOpen(!menu.classList.contains('is-open')));
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && menu.classList.contains('is-open')) {
        setOpen(false);
        toggle.focus();
      }
    });
  }

  document.addEventListener('click', (event) => {
    const dismiss = event.target.closest('[data-dismiss]');
    if (dismiss) (dismiss.closest('.alert') ?? dismiss.parentElement).remove();
  });
}

/* ------------------------------------------------------------------ messages */
const COLLAPSED_MAX = 160; // px, keep in sync with .post-body.is-collapsed (`max-height: 9rem` = 144px + slack in components/_post.scss)

function collapse(body) {
  if (body.dataset.collapseReady) return;
  if (body.scrollHeight <= COLLAPSED_MAX * 1.15) return;

  body.dataset.collapseReady = '1';
  body.classList.add('is-collapsed');

  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'post-toggle';
  const label = (expanded) => `${expanded ? ICON.chevronUp : ICON.chevronDown}<span>${expanded ? 'Masquer le post' : 'Afficher le post'}</span>`;
  button.innerHTML = label(false);
  button.setAttribute('aria-expanded', 'false');
  button.addEventListener('click', (event) => {
    event.stopPropagation();
    const expanded = body.classList.toggle('is-collapsed') === false;
    button.innerHTML = label(expanded);
    button.setAttribute('aria-expanded', String(expanded));
  });
  body.after(button);

  // Posts that open on a permalink (#post-123) are shown in full.
  const post = body.closest('[data-post]');
  if (post && location.hash === `#${post.id}`) button.click();
}

function collapseMessages(root) {
  const bodies = root.querySelectorAll('[data-collapsible]');
  bodies.forEach(collapse);
  // Images inside messages change the height once loaded.
  bodies.forEach((body) => body.querySelectorAll('img').forEach((img) => img.addEventListener('load', () => collapse(body), { once: true })));
}

function initMessages() {
  collapseMessages(document);

  // Touch devices have no hover: tapping a message reveals its details and actions.
  document.addEventListener('click', (event) => {
    if (!window.matchMedia('(hover: none)').matches) return;
    if (event.target.closest('a, button, input, textarea, select, form, iframe')) return;
    const post = event.target.closest('[data-post]');
    document.querySelectorAll('[data-post].is-active').forEach((p) => p !== post && p.classList.remove('is-active'));
    post?.classList.toggle('is-active');
  });

  // Highlight the targeted message.
  const highlight = () => {
    document.querySelectorAll('[data-post].is-target').forEach((p) => p.classList.remove('is-target'));
    const target = location.hash.length > 1 ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null;
    target?.classList.add('is-target');
  };
  highlight();
  window.addEventListener('hashchange', highlight);
}

/* ------------------------------------------------------------------ forms */
function initForms() {
  document.addEventListener('submit', (event) => {
    const message = event.target.closest?.('form[data-confirm]')?.dataset.confirm;
    if (message && !window.confirm(message)) event.preventDefault();
  });

  document.querySelectorAll('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form?.submit()));

  document.addEventListener('submit', async (event) => {
    const form = event.target.closest?.('form[data-star-form]');
    if (!form || event.defaultPrevented) return;
    event.preventDefault();
    const button = form.querySelector('button');
    const starring = button.getAttribute('aria-pressed') !== 'true';
    button.disabled = true;
    try {
      const response = await fetch(form.getAttribute('action'), {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      });
      if (!response.ok) throw new Error(String(response.status));
      button.setAttribute('aria-pressed', String(starring));
      button.title = starring ? 'Retirer des favoris' : 'Ajouter aux favoris';
      form.setAttribute('action', starring ? form.dataset.unstarUrl : form.dataset.starUrl);
      // Keep every star of the same topic on the page in sync.
      document.querySelectorAll(`form[data-star-form][data-star-url="${form.dataset.starUrl}"]`).forEach((other) => {
        if (other === form) return;
        other.querySelector('button').setAttribute('aria-pressed', String(starring));
        other.setAttribute('action', starring ? other.dataset.unstarUrl : other.dataset.starUrl);
      });
    } catch {
      form.submit();
    } finally {
      button.disabled = false;
    }
  });
}

/* ------------------------------------------------------------------ lightbox */
// Like state of the photos seen in the lightbox: photo id -> { liked, count, token } (token = CSRF token of the toggle).
const likeStates = new Map();

async function loadLikeState(photoId) {
  if (!likeStates.has(photoId)) {
    const response = await fetch(`/photos/${photoId}/like`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    if (!response.ok || response.redirected) throw new Error(String(response.status));
    likeStates.set(photoId, await response.json());
  }
  return likeStates.get(photoId);
}

async function toggleLike(photoId) {
  const state = await loadLikeState(photoId);
  const body = new FormData();
  body.append('_token', state.token);
  const response = await fetch(`/photos/${photoId}/like`, { method: 'POST', body, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
  if (!response.ok || response.redirected) throw new Error(String(response.status));
  const result = await response.json();
  Object.assign(state, result);
  return state;
}

class Lightbox {
  constructor(items, index) {
    this.items = items;
    this.index = index;
    this.build();
    this.show(index);
    document.body.classList.add('overflow-hidden');
    this.previouslyFocused = document.activeElement;
    this.root.focus();
  }

  build() {
    const root = document.createElement('div');
    root.className = 'lightbox';
    root.tabIndex = -1;
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', 'Visionneuse de photos');
    root.innerHTML = `
      <div class="lightbox-bar">
        <span data-lb-counter class="lightbox-counter"></span>
        <div class="lightbox-actions">
          <button type="button" data-lb-like class="lightbox-btn lightbox-like" aria-pressed="false" aria-label="J\u2019aime" title="J\u2019aime" hidden>${ICON.heart}<span data-lb-like-count class="lightbox-like-count"></span></button>
          <a data-lb-page class="lightbox-btn" title="Page de la photo" aria-label="Page de la photo">${ICON.page}</a>
          <a data-lb-download class="lightbox-btn" title="Télécharger" aria-label="Télécharger" download>${ICON.download}</a>
          <button type="button" data-lb-close class="lightbox-btn" title="Fermer (Échap)" aria-label="Fermer">${ICON.close}</button>
        </div>
      </div>
      <div data-lb-backdrop class="lightbox-stage">
        <button type="button" data-lb-prev class="lightbox-btn lightbox-nav is-prev" title="Précédente (←)" aria-label="Photo précédente">${ICON.left}</button>
        <img data-lb-image class="lightbox-image" alt="" draggable="false">
        <div data-lb-spinner class="lightbox-spinner" role="status" aria-label="Chargement"></div>
        <button type="button" data-lb-next class="lightbox-btn lightbox-nav is-next" title="Suivante (→)" aria-label="Photo suivante">${ICON.right}</button>
      </div>
      <p data-lb-caption class="lightbox-caption"></p>`;
    document.body.append(root);
    this.root = root;
    this.img = root.querySelector('[data-lb-image]');
    this.spinner = root.querySelector('[data-lb-spinner]');

    root.querySelector('[data-lb-close]').addEventListener('click', () => this.close());
    root.querySelector('[data-lb-like]').addEventListener('click', () => this.like());
    root.querySelector('[data-lb-prev]').addEventListener('click', () => this.go(-1));
    root.querySelector('[data-lb-next]').addEventListener('click', () => this.go(1));
    // Clicking the dark backdrop (not the image or a button) closes the viewer.
    root.addEventListener('click', (e) => {
      if (e.target === root || e.target.hasAttribute('data-lb-backdrop')) this.close();
    });
    root.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') this.close();
      else if (e.key === 'ArrowLeft') this.go(-1);
      else if (e.key === 'ArrowRight') this.go(1);
      else if (e.key === 'Tab') this.trapFocus(e);
    });

    // Swipe on touch screens.
    let startX = null;
    root.addEventListener('touchstart', (e) => { startX = e.touches[0].clientX; }, { passive: true });
    root.addEventListener('touchend', (e) => {
      if (startX === null) return;
      const dx = e.changedTouches[0].clientX - startX;
      startX = null;
      if (Math.abs(dx) > 50) this.go(dx > 0 ? -1 : 1);
    });
  }

  trapFocus(e) {
    const focusable = [...this.root.querySelectorAll('a[href], button')].filter((el) => el.offsetParent !== null);
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  go(delta) {
    if (this.items.length < 2) return;
    this.show((this.index + delta + this.items.length) % this.items.length);
  }

  show(index) {
    this.index = index;
    const item = this.items[index];
    this.spinner.hidden = false;
    this.root.classList.add('is-loading');
    const loader = new Image();
    loader.onload = loader.onerror = () => {
      if (this.items[this.index] !== item) return;
      this.img.src = item.src;
      this.img.alt = item.caption;
      this.root.classList.remove('is-loading');
      this.spinner.hidden = true;
    };
    loader.src = item.src;

    this.root.querySelector('[data-lb-counter]').textContent = this.items.length > 1 ? `${index + 1} / ${this.items.length}` : '';
    this.root.querySelector('[data-lb-caption]').textContent = item.caption;
    this.root.querySelector('[data-lb-page]').href = item.page;
    const download = this.root.querySelector('[data-lb-download]');
    download.href = item.download || item.full;
    this.root.querySelectorAll('[data-lb-prev], [data-lb-next]').forEach((b) => { b.hidden = this.items.length < 2; });
    this.showLike(item);

    // Preload neighbours for instant navigation.
    [index - 1, index + 1].forEach((i) => {
      const neighbour = this.items[(i + this.items.length) % this.items.length];
      if (neighbour && neighbour !== item) new Image().src = neighbour.src;
    });
  }

  /** The heart: hidden for photos without id, filled from the server state (fetched once per photo). */
  showLike(item) {
    const button = this.root.querySelector('[data-lb-like]');
    button.hidden = true;
    if (!item.photoId) return;
    loadLikeState(item.photoId)
      .then((state) => {
        if (this.items[this.index] !== item || !this.root.isConnected) return;
        this.renderLike(button, state);
        button.hidden = false;
      })
      .catch(() => {});
  }

  renderLike(button, state) {
    button.classList.toggle('is-liked', state.liked);
    button.setAttribute('aria-pressed', String(state.liked));
    const label = state.liked ? 'Je n\u2019aime plus' : 'J\u2019aime';
    button.setAttribute('aria-label', label);
    button.title = label;
    button.querySelector('[data-lb-like-count]').textContent = state.count > 0 ? String(state.count) : '';
  }

  async like() {
    const item = this.items[this.index];
    const button = this.root.querySelector('[data-lb-like]');
    if (!item.photoId || button.disabled) return;
    button.disabled = true;
    try {
      const state = await toggleLike(item.photoId);
      if (this.root.isConnected && this.items[this.index] === item) this.renderLike(button, state);
    } catch {
      // Token expired or network error: forget the cached state so the next try starts from the server.
      likeStates.delete(item.photoId);
    } finally {
      button.disabled = false;
    }
  }

  close() {
    this.root.remove();
    document.body.classList.remove('overflow-hidden');
    this.previouslyFocused?.focus?.();
  }
}

function initLightbox() {
  document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
    const link = event.target.closest('a[data-lightbox]');
    if (!link) return;

    event.preventDefault();
    const group = [...document.querySelectorAll(`a[data-lightbox="${CSS.escape(link.dataset.lightbox)}"]`)];
    const items = group.map((a) => ({
      src: a.dataset.src || a.href,
      full: a.dataset.full || a.dataset.src || a.href,
      download: a.dataset.download,
      page: a.dataset.page || a.href,
      caption: a.dataset.caption || '',
      photoId: a.dataset.photoId ? Number(a.dataset.photoId) : null,
    }));
    new Lightbox(items, Math.max(0, group.indexOf(link)));
  });
}

/* ------------------------------------------------------------------ thread previews */
// "Aperçu" of a topic in a listing: loads the latest messages (server-rendered fragment) on first open.
// The buttons are rendered `hidden` (no dead control without JavaScript) and revealed here.
function initThreadPreviews() {
  const state = new WeakMap(); // toggle button -> { open, loaded, loading }
  const setOpen = (button, panel, open) => {
    state.get(button).open = open;
    panel.hidden = !open;
    button.setAttribute('aria-expanded', String(open));
    button.textContent = open ? 'Masquer l\u2019aperçu' : 'Aperçu';
  };

  const showStatus = (panel, message, retry) => {
    panel.replaceChildren();
    const text = document.createElement('p');
    text.textContent = message;
    if (retry) {
      text.setAttribute('role', 'alert');
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn-sm';
      button.textContent = 'Réessayer';
      button.addEventListener('click', retry);
      panel.append(text, button);
    } else {
      text.setAttribute('role', 'status');
      panel.append(text);
    }
  };

  const load = async (button, panel) => {
    const info = state.get(button);
    info.loading = true;
    panel.setAttribute('aria-busy', 'true');
    showStatus(panel, 'Chargement…');
    try {
      const response = await fetch(button.dataset.previewUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
      // A redirect means the session ended (login page) or the topic moved: never inject a whole page.
      if (!response.ok || response.redirected) throw new Error(String(response.status));
      const html = await response.text();
      panel.innerHTML = html; // trusted: sanitized server-side fragment
      info.loaded = true;
      collapseMessages(panel);
      if (info.open) panel.focus();
    } catch {
      showStatus(panel, 'Impossible de charger l\u2019aperçu.', () => load(button, panel));
      panel.querySelector('button')?.focus();
    } finally {
      info.loading = false;
      panel.removeAttribute('aria-busy');
    }
  };

  document.querySelectorAll('.thread-preview-toggle').forEach((button) => {
    state.set(button, { open: false, loaded: false, loading: false });
    button.hidden = false;
  });

  document.addEventListener('click', (event) => {
    const button = event.target.closest('.thread-preview-toggle');
    const panel = button && document.getElementById(button.getAttribute('aria-controls'));
    if (!panel || !state.has(button)) return;

    const info = state.get(button);
    if (info.open) {
      setOpen(button, panel, false);
      return;
    }
    setOpen(button, panel, true);
    if (info.loaded) {
      collapseMessages(panel); // messages measured while hidden need another pass
      panel.focus();
    } else if (!info.loading) {
      load(button, panel);
    }
  });
}

ready(() => {
  initNav();
  initMessages();
  initForms();
  initLightbox();
  initThreadPreviews();
});
