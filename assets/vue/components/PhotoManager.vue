<template>
  <div class="photo-manager">
    <p class="photo-manager-hint">Glissez-déposez les photos pour les réordonner. Les nouvelles photos sont ajoutées à la fin.</p>

    <ul v-if="items.length" class="photo-manager-grid" aria-label="Photos du sujet" @dragover.prevent>
      <li
        v-for="(item, index) in items"
        :key="item.link"
        class="photo-tile"
        :class="{ 'is-dragging': dragIndex === index }"
        draggable="true"
        @dragstart="dragIndex = index"
        @dragend="dragIndex = null"
        @dragenter.prevent="moveTo(index)"
        @drop.prevent="saveOrder"
      >
        <a :href="item.page"><img :src="item.thumb" :alt="item.title" draggable="false" /></a>
        <p class="photo-tile-name" :title="item.title">{{ item.title }}</p>
        <div class="photo-tile-actions">
          <button type="button" class="btn btn-sm" @click="remove(item, false)">Retirer</button>
          <button type="button" class="btn btn-sm btn-danger" title="Supprimer définitivement la photo" @click="remove(item, true)">Supprimer</button>
        </div>
      </li>
    </ul>
    <div v-else class="empty empty-sm mb-6">
      <p class="empty-text">Aucune photo dans ce sujet.</p>
    </div>

    <section
      class="photo-dropzone"
      :class="{ 'is-dragover': hover }"
      aria-labelledby="photo-dropzone-title"
      @dragover.prevent="hover = true"
      @dragleave="hover = false"
      @drop.prevent="onDrop"
    >
      <h2 id="photo-dropzone-title">Ajouter des photos</h2>
      <label class="btn btn-primary">
        Choisir des fichiers…
        <input ref="file" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple class="sr-only" @change="onPick" />
      </label>
      <p class="field-help">ou déposez-les ici — JPEG, PNG, GIF, WebP, 10 Mo maximum chacune.</p>

      <ul v-if="uploads.length" class="photo-uploads" aria-live="polite">
        <li v-for="(u, i) in uploads" :key="i">
          <span class="photo-upload-name">{{ u.name }}</span>
          <span class="photo-upload-state" :class="{ 'is-error': u.state === 'error', 'is-done': u.state === 'done' }">{{ u.message }}</span>
        </li>
      </ul>
    </section>

    <p class="mt-8"><a :href="backUrl" class="btn btn-ghost">Retour au sujet</a></p>
  </div>
</template>

<script>
// Keep in sync with App\Service\PhotoUploader::MAX_SIZE
const MAX_SIZE = 10 * 1024 * 1024;

export default {
  name: 'PhotoManager',
  props: {
    uploadUrl: { type: String, required: true },
    orderUrl: { type: String, required: true },
    removeUrl: { type: String, required: true },
    token: { type: String, required: true },
    photos: { type: Array, default: () => [] },
    backUrl: { type: String, required: true },
  },
  data() {
    return { items: [...this.photos], uploads: [], dragIndex: null, hover: false };
  },
  methods: {
    moveTo(index) {
      if (this.dragIndex === null || this.dragIndex === index) return;
      const [moved] = this.items.splice(this.dragIndex, 1);
      this.items.splice(index, 0, moved);
      this.dragIndex = index;
    },
    async saveOrder() {
      await fetch(this.orderUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ _token: this.token, links: this.items.map((i) => i.link) }),
      });
    },
    async remove(item, deleteFile) {
      const message = deleteFile ? 'Supprimer définitivement cette photo ?' : 'Retirer cette photo du sujet ?';
      if (!window.confirm(message)) return;
      const response = await fetch(this.removeUrl.replace(/\/0\/remove$/, `/${item.link}/remove`), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ _token: this.token, delete_file: deleteFile }),
      });
      if (response.ok) this.items = this.items.filter((i) => i.link !== item.link);
    },
    onPick(event) {
      this.enqueue([...event.target.files]);
      event.target.value = '';
    },
    onDrop(event) {
      this.hover = false;
      this.enqueue([...event.dataTransfer.files].filter((f) => f.type.startsWith('image/')));
    },
    async enqueue(files) {
      for (const file of files) {
        const entry = { name: file.name, state: 'sending', message: 'Envoi…' };
        this.uploads.push(entry);
        if (file.size > MAX_SIZE) {
          Object.assign(entry, { state: 'error', message: 'Trop lourde : 10 Mo au maximum.' });
          continue;
        }
        const body = new FormData();
        body.append('file', file);
        body.append('_token', this.token);
        try {
          const response = await fetch(this.uploadUrl, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
          const data = await response.json();
          if (!response.ok) throw new Error(data.error || 'Échec de l\'envoi');
          this.items.push({ link: data.link, id: data.id, title: file.name, thumb: data.thumb, page: `/photos/${data.id}` });
          Object.assign(entry, { state: 'done', message: 'OK' });
        } catch (e) {
          Object.assign(entry, { state: 'error', message: e.message });
        }
      }
    },
  },
};
</script>
