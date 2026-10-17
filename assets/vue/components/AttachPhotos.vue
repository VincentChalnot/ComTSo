<template>
  <div>
    <div
      ref="zone"
      class="attach-zone"
      :class="{ 'is-dragover': dragover }"
      tabindex="0"
      role="group"
      aria-label="Ajouter des photos : glissez-déposez, collez ou choisissez des fichiers"
      @dragenter.prevent="onDragEnter"
      @dragover.prevent="dragover = true"
      @dragleave="onDragLeave"
      @drop.prevent="onDrop"
    >
      <input
        ref="input"
        class="attach-input"
        type="file"
        multiple
        :id="id"
        :name="name"
        :accept="accept"
        :aria-describedby="`${id}-hint`"
        @change="onPick"
      />
      <p :id="`${id}-hint`" class="attach-hint">
        Glissez-déposez des photos ici, collez-les depuis le presse-papiers ou choisissez des fichiers
        — {{ capacity }} photo{{ capacity > 1 ? 's' : '' }} possible{{ capacity > 1 ? 's' : '' }},
        {{ formatSize(maxSize) }} maximum chacune.
      </p>
    </div>

    <ul v-if="items.length" class="attach-tray">
      <li v-for="item in items" :key="item.key" class="attach-item">
        <img :src="item.url" alt="" width="80" height="80" />
        <span class="attach-item-name" :title="item.file.name">{{ item.file.name }}</span>
        <span class="attach-item-size">{{ formatSize(item.file.size) }}</span>
        <button type="button" class="attach-item-remove" :aria-label="`Retirer ${item.file.name}`" @click="remove(item)">
          <span aria-hidden="true">×</span>
        </button>
      </li>
    </ul>

    <p v-if="items.length" class="attach-count" aria-live="polite">
      {{ items.length }} photo{{ items.length > 1 ? 's' : '' }} sur {{ capacity }} possible{{ capacity > 1 ? 's' : '' }}
    </p>
    <p v-if="errors.length" class="attach-error" role="alert">
      <span v-for="(error, index) in errors" :key="index" class="block">{{ error }}</span>
    </p>
  </div>
</template>

<script setup>
// Enhances a plain multiple file input: drag & drop, clipboard paste, instant thumbnails, per-file removal.
// The real <input type="file"> stays the single source of truth for the form submit: its FileList is rewritten
// (DataTransfer) after every change, so a normal submit (no fetch) carries exactly the files shown in the tray.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
  name: { type: String, required: true },
  id: { type: String, required: true },
  maxFiles: { type: Number, default: 10 },
  maxSize: { type: Number, default: 25 * 1024 * 1024 },
  accept: { type: String, default: 'image/jpeg,image/png,image/gif,image/webp' },
  existing: { type: Number, default: 0 },
});

const zone = ref(null);
const input = ref(null);
const items = ref([]);
const errors = ref([]);
const dragover = ref(false);
const removedElsewhere = ref(0);

const allowedTypes = computed(() => props.accept.split(',').map((type) => type.trim()));
// Photos already attached to the message (edit form) use up room, unless the member ticks "remove" on some of them.
const capacity = computed(() => Math.max(0, props.maxFiles - props.existing + removedElsewhere.value));

let counter = 0;
let form = null;
let pasteCount = 0;

const formatSize = (bytes) => {
  if (bytes >= 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(bytes >= 10 * 1024 * 1024 ? 0 : 1).replace('.', ',')} Mo`;
  return `${Math.max(1, Math.round(bytes / 1024))} Ko`;
};

// Keep the real input's FileList equal to the tray.
const sync = () => {
  const transfer = new DataTransfer();
  items.value.forEach((item) => transfer.items.add(item.file));
  input.value.files = transfer.files;
};

const add = (files) => {
  const problems = [];
  for (const file of files) {
    if (!allowedTypes.value.includes(file.type)) {
      problems.push(`« ${file.name} » n'est pas une image acceptée (JPEG, PNG, GIF ou WebP).`);
    } else if (file.size > props.maxSize) {
      problems.push(`« ${file.name} » est trop lourde (${formatSize(file.size)}) : ${formatSize(props.maxSize)} au maximum.`);
    } else if (items.value.some((item) => item.file.name === file.name && item.file.size === file.size && item.file.lastModified === file.lastModified)) {
      continue; // same file picked twice
    } else if (items.value.length >= capacity.value) {
      problems.push(`Pas plus de ${capacity.value} photo${capacity.value > 1 ? 's' : ''} par message : « ${file.name} » n'a pas été ajoutée.`);
    } else {
      items.value.push({ key: ++counter, file, url: URL.createObjectURL(file) });
    }
  }
  errors.value = problems;
  sync();
};

const remove = (item) => {
  URL.revokeObjectURL(item.url);
  items.value = items.value.filter((candidate) => candidate !== item);
  errors.value = [];
  sync();
  // The tray is gone from under the keyboard focus: give it back to the zone.
  zone.value?.focus();
};

const onPick = () => {
  // The browser replaced the input's files by the new selection: merge it with the tray.
  const picked = [...input.value.files];
  const current = items.value.map((item) => item.file);
  const fresh = picked.filter((file) => !current.includes(file));
  sync();
  add(fresh);
};

let depth = 0;
const onDragEnter = () => {
  depth += 1;
  dragover.value = true;
};
const onDragLeave = () => {
  depth = Math.max(0, depth - 1);
  if (depth === 0) dragover.value = false;
};
const onDrop = (event) => {
  depth = 0;
  dragover.value = false;
  add([...(event.dataTransfer?.files ?? [])]);
};

// Pasting an image (screenshot, copied picture) anywhere in the form attaches it; plain text pastes are left alone.
const onPaste = (event) => {
  const images = [...(event.clipboardData?.files ?? [])].filter((file) => file.type.startsWith('image/'));
  if (!images.length) return;
  event.preventDefault();
  event.stopPropagation();
  add(images.map((file) => {
    if (file.name && file.name !== 'image.png') return file;
    pasteCount += 1;
    const extension = file.type.split('/')[1] || 'png';
    return new File([file], `photo-collee-${pasteCount}.${extension}`, { type: file.type, lastModified: Date.now() });
  }));
};

const countRemoved = () => {
  removedElsewhere.value = form ? form.querySelectorAll('input[name$="[remove_photos][]"]:checked').length : 0;
};

onMounted(() => {
  form = zone.value.closest('form');
  form?.addEventListener('paste', onPaste, true);
  form?.addEventListener('change', countRemoved);
  form?.addEventListener('reset', () => {
    items.value.forEach((item) => URL.revokeObjectURL(item.url));
    items.value = [];
    errors.value = [];
  });
  countRemoved();
});

onBeforeUnmount(() => {
  items.value.forEach((item) => URL.revokeObjectURL(item.url));
  form?.removeEventListener('paste', onPaste, true);
  form?.removeEventListener('change', countRemoved);
});
</script>
