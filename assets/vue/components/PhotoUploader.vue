<template>
  <div class="photo-uploader">
    <div class="file is-boxed">
      <label class="file-label">
        <input
          class="file-input"
          type="file"
          multiple
          accept="image/*"
          @change="handleFileSelect"
          ref="fileInput"
        />
        <span class="file-cta">
          <span class="file-icon">
            <i class="fas fa-upload"></i>
          </span>
          <span class="file-label">Choose photos…</span>
        </span>
      </label>
    </div>

    <div v-if="files.length > 0" class="mt-4">
      <h3 class="subtitle">Selected Photos ({{ files.length }})</h3>
      <div class="photo-grid">
        <div v-for="(file, index) in files" :key="index" class="photo-preview">
          <img :src="file.preview" :alt="file.name" />
          <p class="is-size-7">{{ file.name }}</p>
          <button @click="removeFile(index)" class="button is-small is-danger">Remove</button>
        </div>
      </div>

      <div class="field mt-4">
        <div class="control">
          <button @click="uploadPhotos" class="button is-primary" :disabled="uploading">
            {{ uploading ? 'Uploading...' : 'Upload Photos' }}
          </button>
        </div>
      </div>

      <progress v-if="uploading" class="progress is-primary" :value="uploadProgress" max="100">
        {{ uploadProgress }}%
      </progress>
    </div>

    <div v-if="uploadedPhotos.length > 0" class="notification is-success mt-4">
      Successfully uploaded {{ uploadedPhotos.length }} photo(s)!
    </div>
  </div>
</template>

<script>
import axios from 'axios';

export default {
  name: 'PhotoUploader',
  props: {
    apiUrl: {
      type: String,
      required: true
    }
  },
  data() {
    return {
      files: [],
      uploading: false,
      uploadProgress: 0,
      uploadedPhotos: []
    };
  },
  methods: {
    handleFileSelect(event) {
      const selectedFiles = Array.from(event.target.files);
      selectedFiles.forEach(file => {
        const reader = new FileReader();
        reader.onload = (e) => {
          this.files.push({
            file: file,
            name: file.name,
            preview: e.target.result
          });
        };
        reader.readAsDataURL(file);
      });
    },
    removeFile(index) {
      this.files.splice(index, 1);
    },
    async uploadPhotos() {
      this.uploading = true;
      this.uploadProgress = 0;
      this.uploadedPhotos = [];

      for (let i = 0; i < this.files.length; i++) {
        const formData = new FormData();
        formData.append('file', this.files[i].file);

        try {
          const response = await axios.post(this.apiUrl, formData, {
            headers: {
              'Content-Type': 'multipart/form-data'
            }
          });
          this.uploadedPhotos.push(response.data);
        } catch (error) {
          console.error('Upload error:', error);
        }

        this.uploadProgress = Math.round(((i + 1) / this.files.length) * 100);
      }

      this.uploading = false;
      this.files = [];
      this.$refs.fileInput.value = '';
    }
  }
};
</script>

<style scoped>
.photo-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 1rem;
}

.photo-preview img {
  width: 100%;
  height: 150px;
  object-fit: cover;
  border-radius: 4px;
}
</style>
