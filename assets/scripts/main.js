import { createApp } from 'vue';
import '../styles/main.scss';

// Import Vue components
import PhotoUploader from '../vue/components/PhotoUploader.vue';
import PhotoGallery from '../vue/components/PhotoGallery.vue';
import ChatWidget from '../vue/components/ChatWidget.vue';
import ThemeToggle from '../vue/components/ThemeToggle.vue';

// Initialize Vue app
const app = createApp({});

// Register components globally
app.component('PhotoUploader', PhotoUploader);
app.component('PhotoGallery', PhotoGallery);
app.component('ChatWidget', ChatWidget);
app.component('ThemeToggle', ThemeToggle);

// Mount the app to elements with the data-vue attribute
document.addEventListener('DOMContentLoaded', () => {
  const vueElements = document.querySelectorAll('[data-vue]');
  vueElements.forEach((element) => {
    const componentApp = createApp({});
    componentApp.component('PhotoUploader', PhotoUploader);
    componentApp.component('PhotoGallery', PhotoGallery);
    componentApp.component('ChatWidget', ChatWidget);
    componentApp.component('ThemeToggle', ThemeToggle);
    componentApp.mount(element);
  });
});
