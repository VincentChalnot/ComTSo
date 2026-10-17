// Design-system showcase runtime.
//
// Authoring a section: drop `design-system/sections/NN-name.html` (sorted by file name). Markup convention:
//
//   <section class="ds-section" id="boutons" data-title="Boutons">
//     <header class="ds-section-head"><p class="kicker">Composants</p><h2>Boutons</h2><p class="standfirst">…</p></header>
//     <div class="ds-demo" data-title="Variantes">           <- one demo block (title shown in its header)
//       <div class="ds-stage"> …real component markup… </div>   <- rendered AND shown as copy-paste HTML
//     </div>
//   </section>
//
// Every `.ds-demo` automatically gets: a light|dark side-by-side toggle (two theme islands), and an HTML panel built from the
// stage's own markup (so the documentation can never drift from what is rendered). Forced states use the `is-hover`,
// `is-pressed`, `is-focus`, `is-disabled` classes that every component supports.
import './showcase.scss';
import { createApp } from 'vue';
import registry from '../assets/vue/registry.js';
import { initThemeToggle } from '../assets/scripts/theme-toggle.js';
import { renderTokens } from './tokens.js';

const sections = import.meta.glob('./sections/*.html', { query: '?raw', import: 'default', eager: true });
const root = document.getElementById('ds-root');
const nav = document.getElementById('ds-nav');

// ---- theme: the header button uses the very same module the login page will (localStorage "comtso-theme") ----
initThemeToggle(document);

// ---- sections ------------------------------------------------------------------------------------------------------
Object.keys(sections)
  .sort()
  .forEach((path) => {
    const tpl = document.createElement('template');
    tpl.innerHTML = sections[path].trim();
    root.append(tpl.content);
  });

renderTokens(root);

root.querySelectorAll('.ds-section').forEach((section) => {
  const a = document.createElement('a');
  a.href = `#${section.id}`;
  a.textContent = section.dataset.title || section.id;
  nav.append(a);
});

// ---- demos: pair toggle + HTML panel ------------------------------------------------------------------------------
function dedent(code) {
  const lines = code.replace(/\t/g, '  ').split('\n');
  while (lines.length && !lines[0].trim()) lines.shift();
  while (lines.length && !lines[lines.length - 1].trim()) lines.pop();
  const indent = Math.min(...lines.filter((l) => l.trim()).map((l) => l.match(/^ */)[0].length));
  return lines.map((l) => l.slice(indent)).join('\n');
}

function pretty(stage) {
  const clone = stage.cloneNode(true);
  clone.querySelectorAll('[data-ds-ignore]').forEach((n) => n.remove());
  return dedent(clone.innerHTML).replace(/ data-v-[a-z0-9]+(="")?/g, '');
}

root.querySelectorAll('.ds-demo').forEach((demo) => {
  const stage = demo.querySelector('.ds-stage');
  if (!stage) return;
  const source = pretty(stage);
  const bar = document.createElement('div');
  bar.className = 'ds-demo-bar';
  bar.innerHTML = `<span class="ds-demo-title">${demo.dataset.title || ''}</span>
    <span class="ds-demo-tools">
      <button type="button" class="btn btn-ghost btn-sm" data-pair aria-pressed="false">Clair | Sombre</button>
      <button type="button" class="btn btn-ghost btn-sm" data-code aria-expanded="false">HTML</button>
      <button type="button" class="btn btn-ghost btn-sm" data-copy hidden>Copier</button>
    </span>`;
  demo.prepend(bar);

  const code = document.createElement('pre');
  code.className = 'ds-code';
  code.hidden = true;
  code.append(Object.assign(document.createElement('code'), { textContent: source }));
  demo.append(code);

  const pair = bar.querySelector('[data-pair]');
  let islands = null;
  pair.addEventListener('click', () => {
    const on = pair.getAttribute('aria-pressed') !== 'true';
    pair.setAttribute('aria-pressed', String(on));
    if (on) {
      stage.hidden = true;
      islands = document.createElement('div');
      islands.className = 'ds-pair';
      ['light', 'dark'].forEach((theme) => {
        const island = document.createElement('div');
        island.className = 'ds-island';
        island.dataset.theme = theme;
        island.innerHTML = `<p class="ds-island-label">${theme === 'light' ? 'Clair' : 'Sombre'}</p>${stage.innerHTML}`;
        islands.append(island);
      });
      stage.after(islands);
    } else {
      islands?.remove();
      stage.hidden = false;
    }
  });

  const codeBtn = bar.querySelector('[data-code]');
  const copyBtn = bar.querySelector('[data-copy]');
  codeBtn.addEventListener('click', () => {
    const open = code.hidden;
    code.hidden = !open;
    copyBtn.hidden = !open;
    codeBtn.setAttribute('aria-expanded', String(open));
  });
  copyBtn.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(source);
      copyBtn.textContent = 'Copié ✓';
    } catch (e) {
      copyBtn.textContent = 'Sélectionnez le code';
    }
    setTimeout(() => (copyBtn.textContent = 'Copier'), 1600);
  });
});

// Sections can include the anonymous-visitor toggle (`[data-theme-toggle]`) exactly as the login page will.
initThemeToggle(root);

// ---- generic hooks used by sections ------------------------------------------------------------------------------
// <input type="range" data-ds-var="--texture-ratio" data-ds-target="#some-id"> sets a CSS variable on a target (live slider).
root.addEventListener('input', (event) => {
  const el = event.target;
  if (!(el instanceof HTMLInputElement) || !el.dataset.dsVar) return;
  const targets = el.dataset.dsTarget ? root.querySelectorAll(el.dataset.dsTarget) : [el.closest('.ds-demo')];
  targets.forEach((t) => t.style.setProperty(el.dataset.dsVar, el.value));
  const out = el.parentElement.querySelector('output');
  if (out) out.textContent = Number(el.value).toFixed(2);
});

// Real Vue widgets with no backend dependency can be mounted exactly like the application does:
//   <div data-vue-component="RichEditor" data-props='{"name":"demo",…}'></div>
// (ChatRoom / PhotoManager / ThemeToggle talk to the server: replicate their DOM statically instead).
root.querySelectorAll('[data-vue-component]:not([data-vue-mounted])').forEach((el) => {
  const component = registry[el.dataset.vueComponent];
  if (!component) return;
  el.dataset.vueMounted = '1';
  createApp(component, JSON.parse(el.dataset.props || '{}')).mount(el);
});

// Collapsible demo of dark/light toggles for components that have their own JS (lightbox, collapsible posts…)
// are wired by their own section scripts: `window.dsReady(fn)` runs `fn` once the sections are in the DOM.
window.dsReady = (fn) => fn(root);
document.dispatchEvent(new CustomEvent('ds:ready'));
