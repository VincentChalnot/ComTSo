import '../styles/app.scss';
import { createApp } from 'vue';
import registry from '../vue/registry.js';
import './ui.js';
import './chat-badge.js';
import { initThemeToggle } from './theme-toggle.js';

function mountVueWidgets(root = document) {
  root.querySelectorAll('[data-vue-component]:not([data-vue-mounted])').forEach((el) => {
    const component = registry[el.dataset.vueComponent];
    if (!component) {
      console.warn(`Unknown Vue component "${el.dataset.vueComponent}"`);
      return;
    }
    el.dataset.vueMounted = '1';
    createApp(component, JSON.parse(el.dataset.props || '{}')).mount(el);
  });
}

mountVueWidgets();
initThemeToggle();
window.comtso = { ...(window.comtso || {}), mountVueWidgets };
