<template>
  <div ref="root" class="rich-editor" :class="{ 'is-compact': compact, 'is-ready': !!editor }" :style="{ '--re-min-h': cssMinHeight }">
    <!-- Until the editor chunk is loaded (or if it fails to load) the field stays a usable plain textarea. -->
    <textarea
      v-if="!editor"
      ref="fallback"
      class="input"
      :id="id || undefined"
      :name="name"
      :placeholder="placeholder"
      :required="required || undefined"
      :rows="compact ? 3 : 8"
      :value="value"
    ></textarea>
    <input v-else type="hidden" :name="name" :value="html" />

    <div v-if="editor" ref="bar" class="rich-editor-bar">
      <div
        ref="toolbar"
        class="rich-editor-toolbar"
        role="toolbar"
        aria-label="Mise en forme du texte"
        aria-orientation="horizontal"
        @keydown="onToolbarKeydown"
        @focusin="onToolbarFocus"
      >
        <template v-for="(group, gi) in groups" :key="gi">
          <span v-if="gi > 0" class="rich-editor-sep" aria-hidden="true"></span>
          <button
            v-for="tool in group"
            :key="tool.id"
            type="button"
            class="rich-editor-btn"
            :class="{ 'is-active': isOn(tool), 'is-disabled': isOff(tool) }"
            :title="tool.label"
            :aria-label="tool.name"
            :aria-pressed="tool.toggle ? String(isOn(tool)) : undefined"
            :aria-disabled="isOff(tool) ? 'true' : undefined"
            :aria-expanded="tool.id === 'smilies' ? String(smiliesOpen) : undefined"
            :aria-haspopup="tool.id === 'smilies' ? 'true' : undefined"
            :data-idx="tool.idx"
            :tabindex="tool.idx === roving ? 0 : -1"
            @mousedown.prevent
            @click="trigger(tool)"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" v-html="ICONS[tool.icon]"></svg>
          </button>
        </template>
      </div>

      <div v-if="smiliesOpen" class="rich-editor-popover" role="group" aria-label="Smileys" @keydown.esc.stop.prevent="closeSmilies(true)">
        <button
          v-for="smiley in SMILIES"
          :key="smiley.file"
          type="button"
          class="rich-editor-smiley"
          :title="`${smiley.label} ${smiley.code}`"
          :aria-label="`${smiley.label} ${smiley.code}`"
          @mousedown.prevent
          @click="insertSmiley(smiley)"
        >
          <img :src="`/smilies/${smiley.file}`" :alt="smiley.code" width="20" height="20" />
        </button>
      </div>

      <div v-if="prompt" class="rich-editor-prompt">
        <label class="sr-only" :for="promptId">{{ PROMPTS[prompt.kind].label }}</label>
        <input
          :id="promptId"
          ref="promptInput"
          v-model="prompt.value"
          class="input"
          type="text"
          inputmode="url"
          autocomplete="off"
          spellcheck="false"
          :placeholder="PROMPTS[prompt.kind].placeholder"
          :aria-invalid="prompt.error ? 'true' : undefined"
          @keydown.enter.prevent="applyPrompt"
          @keydown.esc.prevent.stop="closePrompt"
        />
        <button type="button" class="btn btn-primary btn-sm" @click="applyPrompt">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" v-html="ICONS.check"></svg>
          {{ PROMPTS[prompt.kind].action }}
        </button>
        <button type="button" class="btn btn-sm" @click="closePrompt">Annuler</button>
        <p v-if="prompt.error" class="field-error w-full" role="alert">{{ prompt.error }}</p>
      </div>
    </div>

    <!-- The editable surface must exist before the editor is created: hidden until then. -->
    <div v-show="editor" ref="surface" class="rich-editor-surface"></div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { ICONS } from '../editor/icons.js';
import { SMILIES } from '../editor/smilies.js';
import { youtubeEmbedUrl } from '../editor/youtube.js';

const props = defineProps({
  /** Form field name submitted with the surrounding form. */
  name: { type: String, required: true },
  /** Initial HTML. */
  value: { type: String, default: '' },
  /** id of the original field (label `for`, anchors). */
  id: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  /** CSS length, or a number of pixels. */
  minHeight: { type: [String, Number], default: null },
  /** Small variant: shorter surface, reduced toolbar (signatures, short messages). */
  compact: { type: Boolean, default: false },
  /** Accessible name of the editing area when the field has no <label>. */
  ariaLabel: { type: String, default: '' },
  required: { type: Boolean, default: false },
});

const PROMPTS = {
  link: { label: 'Adresse du lien', placeholder: 'https://exemple.fr', action: 'Insérer le lien' },
  image: { label: 'Adresse de l’image', placeholder: 'https://exemple.fr/image.jpg', action: 'Insérer l’image' },
  youtube: { label: 'Adresse de la vidéo YouTube', placeholder: 'https://www.youtube.com/watch?v=…', action: 'Insérer la vidéo' },
};

const root = ref(null);
const surface = ref(null);
const fallback = ref(null);
const bar = ref(null);
const toolbar = ref(null);
const promptInput = ref(null);
const editor = shallowRef(null);
/** Bumped on every editor transaction so that toolbar states are recomputed. */
const tick = ref(0);
const html = ref(props.value);
const smiliesOpen = ref(false);
const prompt = ref(null);
const roving = ref(0);
const promptId = `${props.id || 're'}-rich-editor-url-${Math.random().toString(36).slice(2, 8)}`;

const cssMinHeight = computed(() => {
  if (props.minHeight === null || props.minHeight === '') return props.compact ? '4.5rem' : '10rem';
  return typeof props.minHeight === 'number' ? `${props.minHeight}px` : props.minHeight;
});

let labelElement = null;
let creating = true;

const ed = () => editor.value;
const chain = () => ed().chain().focus();

const tool = (id, icon, name, run, options = {}) => ({
  id,
  icon,
  name,
  run,
  label: options.shortcut ? `${name} (${options.shortcut})` : name,
  ...options,
});
const markTool = (id, icon, name, shortcut, command) =>
  tool(id, icon, name, () => command(chain()).run(), { shortcut, toggle: true, active: () => ed().isActive(id) });
const alignTool = (alignment, icon, name) =>
  tool(`align-${alignment}`, icon, name, () => (alignment === 'left' ? chain().unsetTextAlign() : chain().setTextAlign(alignment)).run(), {
    toggle: true,
    full: true,
    active: () =>
      alignment === 'left'
        ? !['center', 'right', 'justify'].some((a) => ed().isActive({ textAlign: a }))
        : ed().isActive({ textAlign: alignment }),
  });

const definitions = [
  [
    markTool('bold', 'bold', 'Gras', 'Ctrl+B', (c) => c.toggleBold()),
    markTool('italic', 'italic', 'Italique', 'Ctrl+I', (c) => c.toggleItalic()),
    markTool('underline', 'underline', 'Souligné', 'Ctrl+U', (c) => c.toggleUnderline()),
    markTool('strike', 'strike', 'Barré', 'Ctrl+Maj+S', (c) => c.toggleStrike()),
    { ...markTool('subscript', 'subscript', 'Indice', 'Ctrl+,', (c) => c.toggleSubscript()), full: true },
    { ...markTool('superscript', 'superscript', 'Exposant', 'Ctrl+.', (c) => c.toggleSuperscript()), full: true },
    tool('clear', 'clear', 'Effacer la mise en forme', () => chain().unsetAllMarks().clearNodes().unsetTextAlign().run(), { full: true }),
  ],
  [
    { ...markTool('bulletList', 'bulletList', 'Liste à puces', 'Ctrl+Maj+8', (c) => c.toggleBulletList()), full: true },
    { ...markTool('orderedList', 'orderedList', 'Liste numérotée', 'Ctrl+Maj+7', (c) => c.toggleOrderedList()), full: true },
    { ...markTool('blockquote', 'quote', 'Citation', 'Ctrl+Maj+B', (c) => c.toggleBlockquote()), full: true },
  ],
  [
    alignTool('left', 'alignLeft', 'Aligner à gauche'),
    alignTool('center', 'alignCenter', 'Centrer'),
    alignTool('right', 'alignRight', 'Aligner à droite'),
    alignTool('justify', 'alignJustify', 'Justifier'),
  ],
  [
    tool('link', 'link', 'Insérer un lien', () => openPrompt('link'), { toggle: true, active: () => ed().isActive('link') }),
    tool('unlink', 'unlink', 'Supprimer le lien', () => chain().extendMarkRange('link').unsetLink().run(), { disabled: () => !ed().isActive('link') }),
    tool('image', 'image', 'Insérer une image (adresse)', () => openPrompt('image')),
    tool('youtube', 'youtube', 'Insérer une vidéo YouTube', () => openPrompt('youtube')),
    tool('smilies', 'smile', 'Smileys', () => toggleSmilies()),
  ],
  [
    tool('undo', 'undo', 'Annuler', () => chain().undo().run(), { shortcut: 'Ctrl+Z', disabled: () => !ed().can().undo() }),
    tool('redo', 'redo', 'Rétablir', () => chain().redo().run(), { shortcut: 'Ctrl+Y', disabled: () => !ed().can().redo() }),
  ],
];

/** Visible groups with a flat index per button (roving tabindex). */
const groups = computed(() => {
  let idx = 0;
  return definitions
    .map((group) => group.filter((t) => !props.compact || !t.full))
    .filter((group) => group.length > 0)
    .map((group) => group.map((t) => ({ ...t, idx: idx++ })));
});

const isOn = (t) => {
  void tick.value;
  return !!t.active && !!editor.value && t.active();
};
const isOff = (t) => {
  void tick.value;
  return !!t.disabled && !!editor.value && t.disabled();
};

function trigger(t) {
  if (isOff(t)) return;
  t.run();
}

// ---- toolbar keyboard navigation (roving tabindex) ----
function onToolbarFocus(event) {
  const idx = Number(event.target?.dataset?.idx);
  if (!Number.isNaN(idx)) roving.value = idx;
}

function onToolbarKeydown(event) {
  const buttons = [...toolbar.value.querySelectorAll('button')];
  const current = buttons.indexOf(document.activeElement);
  if (current === -1) return;

  let next = null;
  if (event.key === 'ArrowRight') next = (current + 1) % buttons.length;
  else if (event.key === 'ArrowLeft') next = (current - 1 + buttons.length) % buttons.length;
  else if (event.key === 'Home') next = 0;
  else if (event.key === 'End') next = buttons.length - 1;
  else if (event.key === 'Escape') {
    event.preventDefault();
    ed().commands.focus();
    return;
  }
  if (next !== null) {
    event.preventDefault();
    buttons[next].focus();
  }
}

// ---- smilies ----
function toggleSmilies() {
  prompt.value = null;
  smiliesOpen.value = !smiliesOpen.value;
}

function closeSmilies(refocus = false) {
  smiliesOpen.value = false;
  if (refocus) ed().commands.focus();
}

function insertSmiley(smiley) {
  chain()
    .insertContent([
      { type: 'image', attrs: { src: `/smilies/${smiley.file}`, alt: smiley.code } },
      { type: 'text', text: ' ' },
    ])
    .run();
  smiliesOpen.value = false;
}

// ---- link / image / YouTube prompt ----
const DOMAIN_LIKE = /^(?:[a-z0-9-]+\.)+[a-z]{2,}(?::\d+)?(?:[/?#]\S*)?$/i;

/** http(s) or site-relative URL, or null. `mail` also accepts mailto:/e-mail addresses (links only). */
function normaliseUrl(raw, { mail = false } = {}) {
  if (/[\s\\]/.test(raw)) return null;
  if (/^https?:\/\//i.test(raw)) {
    try {
      new URL(raw);
      return raw;
    } catch {
      return null;
    }
  }
  if (raw.startsWith('/') && !raw.startsWith('//')) return raw;
  if (mail && raw.startsWith('#')) return raw;
  if (mail && /^mailto:[^\s@]+@[^\s@]+$/i.test(raw)) return raw;
  if (mail && /^[^\s@/]+@[^\s@/]+\.[a-z]{2,}$/i.test(raw)) return `mailto:${raw}`;
  if (DOMAIN_LIKE.test(raw)) return `https://${raw}`;

  return null;
}

function openPrompt(kind) {
  smiliesOpen.value = false;
  prompt.value = { kind, value: kind === 'link' ? ed().getAttributes('link').href || '' : '', error: '' };
  nextTick(() => promptInput.value?.focus());
}

function closePrompt() {
  prompt.value = null;
  ed().commands.focus();
}

function applyPrompt() {
  const { kind, value } = prompt.value;
  const raw = value.trim();

  if (kind === 'link') {
    if (raw === '') {
      if (ed().isActive('link')) chain().extendMarkRange('link').unsetLink().run();
      prompt.value = null;
      return;
    }
    const href = normaliseUrl(raw, { mail: true });
    if (!href) return fail('Adresse non valide : utilisez http://, https:// ou une adresse e-mail.');
    if (ed().isActive('link')) chain().extendMarkRange('link').setLink({ href }).run();
    else if (ed().state.selection.empty) chain().insertContent({ type: 'text', text: raw, marks: [{ type: 'link', attrs: { href } }] }).run();
    else chain().setLink({ href }).run();
  } else if (kind === 'image') {
    const src = raw && normaliseUrl(raw);
    if (!src) return fail('Adresse non valide : l’image doit commencer par http:// ou https://.');
    chain().insertContent({ type: 'image', attrs: { src, alt: '' } }).run();
  } else {
    const src = youtubeEmbedUrl(raw);
    if (!src) return fail('Lien YouTube non reconnu (exemple : https://www.youtube.com/watch?v=…).');
    chain().insertYouTube(src).run();
  }

  prompt.value = null;
}

function fail(message) {
  prompt.value.error = message;
}

// ---- lifecycle ----
const escapeHtml = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const plainToHtml = (text) =>
  text
    .split(/\n{2,}/)
    .map((paragraph) => `<p>${escapeHtml(paragraph).replace(/\n/g, '<br>')}</p>`)
    .join('');

function sync() {
  // The trailing empty paragraph kept after a player (to keep typing below it) is not content.
  html.value = ed().isEmpty ? '' : ed().getHTML().replace(/(?:<p><\/p>)+$/, '');
}

function submitForm() {
  root.value?.closest('form')?.requestSubmit();
}

function focusEditor() {
  ed()?.commands.focus();
}

function onDocumentPointerDown(event) {
  if (smiliesOpen.value && bar.value && !bar.value.contains(event.target)) smiliesOpen.value = false;
}

onMounted(async () => {
  document.addEventListener('pointerdown', onDocumentPointerDown);

  labelElement = props.id ? document.querySelector(`label[for="${CSS.escape(props.id)}"]`) : null;
  const labelText = labelElement?.textContent?.trim();

  try {
    const { createEditor } = await import('../editor/create-editor.js');
    if (!creating) return; // unmounted while loading

    // Whatever was typed into the fallback textarea while the editor was loading is kept.
    const typed = fallback.value ? fallback.value.value : props.value;
    const created = createEditor({
      element: surface.value,
      content: typed === props.value ? props.value : plainToHtml(typed),
      placeholder: props.placeholder,
      ariaLabel: props.ariaLabel || labelText || props.placeholder || 'Contenu du message',
      id: props.id,
      onSubmit: submitForm,
    });
    created.on('update', sync);
    created.on('transaction', () => {
      tick.value++;
    });
    editor.value = created;
    sync();
    labelElement?.addEventListener('click', focusEditor);
  } catch (error) {
    // The plain textarea stays in place and keeps working.
    console.error('RichEditor: unable to start the editor', error);
  }
});

onBeforeUnmount(() => {
  creating = false;
  document.removeEventListener('pointerdown', onDocumentPointerDown);
  labelElement?.removeEventListener('click', focusEditor);
  editor.value?.destroy();
});
</script>
