<template>
  <div class="photo-gallery">
    <div class="photo-grid">
      <div
        v-for="photo in photos"
        :key="photo.id"
        class="photo-item"
        @click="openLightbox(photo)"
      >
        <img :src="photo.thumbnail_url" :alt="photo.title || 'Photo'" />
        <div class="photo-overlay">
          <p class="photo-title">{{ photo.title }}</p>
          <p class="photo-meta is-size-7">
            <span v-if="photo.author">by {{ photo.author }}</span>
            <span v-if="photo.taken_at"> • {{ formatDate(photo.taken_at) }}</span>
          </p>
        </div>
      </div>
    </div>

    <div v-if="lightboxPhoto" class="lightbox" @click="closeLightbox">
      <div class="lightbox-content" @click.stop>
        <button class="delete is-large lightbox-close" @click="closeLightbox"></button>

        <img :src="lightboxPhoto.url" :alt="lightboxPhoto.title || 'Photo'" />

        <div class="lightbox-info">
          <h3 class="title is-4">{{ lightboxPhoto.title }}</h3>
          <p v-if="lightboxPhoto.description">{{ lightboxPhoto.description }}</p>

          <div v-if="lightboxPhoto.exif" class="exif-data mt-4">
            <h4 class="subtitle is-6">EXIF Data</h4>
            <table class="table is-narrow is-fullwidth">
              <tbody>
                <tr v-if="lightboxPhoto.exif.Make">
                  <td><strong>Camera</strong></td>
                  <td>{{ lightboxPhoto.exif.Make }} {{ lightboxPhoto.exif.Model }}</td>
                </tr>
                <tr v-if="lightboxPhoto.exif.ExposureTime">
                  <td><strong>Exposure</strong></td>
                  <td>{{ lightboxPhoto.exif.ExposureTime }}</td>
                </tr>
                <tr v-if="lightboxPhoto.exif.FNumber">
                  <td><strong>Aperture</strong></td>
                  <td>f/{{ lightboxPhoto.exif.FNumber }}</td>
                </tr>
                <tr v-if="lightboxPhoto.exif.ISOSpeedRatings">
                  <td><strong>ISO</strong></td>
                  <td>{{ lightboxPhoto.exif.ISOSpeedRatings }}</td>
                </tr>
                <tr v-if="lightboxPhoto.exif.FocalLength">
                  <td><strong>Focal Length</strong></td>
                  <td>{{ lightboxPhoto.exif.FocalLength }}mm</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <button
          v-if="currentPhotoIndex > 0"
          class="lightbox-nav lightbox-prev"
          @click="previousPhoto"
        >
          ‹
        </button>
        <button
          v-if="currentPhotoIndex < photos.length - 1"
          class="lightbox-nav lightbox-next"
          @click="nextPhoto"
        >
          ›
        </button>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'PhotoGallery',
  props: {
    photos: {
      type: Array,
      required: true,
      default: () => []
    }
  },
  data() {
    return {
      lightboxPhoto: null,
      currentPhotoIndex: -1
    };
  },
  methods: {
    openLightbox(photo) {
      this.currentPhotoIndex = this.photos.findIndex(p => p.id === photo.id);
      this.lightboxPhoto = photo;
      document.body.style.overflow = 'hidden';
    },
    closeLightbox() {
      this.lightboxPhoto = null;
      this.currentPhotoIndex = -1;
      document.body.style.overflow = '';
    },
    previousPhoto() {
      if (this.currentPhotoIndex > 0) {
        this.currentPhotoIndex--;
        this.lightboxPhoto = this.photos[this.currentPhotoIndex];
      }
    },
    nextPhoto() {
      if (this.currentPhotoIndex < this.photos.length - 1) {
        this.currentPhotoIndex++;
        this.lightboxPhoto = this.photos[this.currentPhotoIndex];
      }
    },
    formatDate(dateString) {
      if (!dateString) return '';
      const date = new Date(dateString);
      return date.toLocaleDateString();
    }
  },
  mounted() {
    // Add keyboard navigation for lightbox
    document.addEventListener('keydown', (e) => {
      if (!this.lightboxPhoto) return;

      if (e.key === 'Escape') {
        this.closeLightbox();
      } else if (e.key === 'ArrowLeft') {
        this.previousPhoto();
      } else if (e.key === 'ArrowRight') {
        this.nextPhoto();
      }
    });
  }
};
</script>

<style scoped>
.photo-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 1rem;
}

.photo-item {
  position: relative;
  cursor: pointer;
  overflow: hidden;
  border-radius: 4px;
  transition: transform 0.2s;
}

.photo-item:hover {
  transform: scale(1.05);
}

.photo-item img {
  width: 100%;
  height: 200px;
  object-fit: cover;
  display: block;
}

.photo-overlay {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  background: linear-gradient(to top, rgba(0,0,0,0.7), transparent);
  color: white;
  padding: 0.5rem;
  opacity: 0;
  transition: opacity 0.2s;
}

.photo-item:hover .photo-overlay {
  opacity: 1;
}

.photo-title {
  font-weight: bold;
  margin-bottom: 0.25rem;
}

.lightbox {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.9);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.lightbox-content {
  position: relative;
  max-width: 90vw;
  max-height: 90vh;
  display: flex;
  gap: 2rem;
}

.lightbox-content img {
  max-width: 60vw;
  max-height: 90vh;
  object-fit: contain;
}

.lightbox-info {
  background: white;
  padding: 1.5rem;
  border-radius: 4px;
  max-width: 400px;
  overflow-y: auto;
  max-height: 90vh;
}

.lightbox-close {
  position: absolute;
  top: 1rem;
  right: 1rem;
  z-index: 1001;
}

.lightbox-nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  background: rgba(255, 255, 255, 0.8);
  border: none;
  font-size: 3rem;
  width: 60px;
  height: 60px;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.2s;
}

.lightbox-nav:hover {
  background: rgba(255, 255, 255, 1);
}

.lightbox-prev {
  left: 1rem;
}

.lightbox-next {
  right: 1rem;
}

.exif-data .table {
  font-size: 0.875rem;
}
</style>
