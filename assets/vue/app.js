import { createApp } from 'vue';
import PhotoUploader from './components/PhotoUploader.vue';
import PhotoGallery from './components/PhotoGallery.vue';
import ChatWidget from './components/ChatWidget.vue';
import ThemeToggle from './components/ThemeToggle.vue';

// Initialize Vue components on elements with data-vue attribute
document.addEventListener('DOMContentLoaded', () => {
    const vueElements = document.querySelectorAll('[data-vue]');

    vueElements.forEach((element) => {
        const app = createApp({});

        // Register all components globally
        app.component('PhotoUploader', PhotoUploader);
        app.component('PhotoGallery', PhotoGallery);
        app.component('ChatWidget', ChatWidget);
        app.component('ThemeToggle', ThemeToggle);

        app.mount(element);
    });
});
