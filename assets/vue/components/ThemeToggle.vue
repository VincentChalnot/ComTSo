<template>
  <button type="button" class="btn-icon" :title="theme === 'dark' ? 'Thème clair' : 'Thème sombre'" @click="toggle">
    <svg v-if="theme === 'dark'" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
    <svg v-else viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
  </button>
</template>

<script>
export default {
  name: 'ThemeToggle',
  props: { url: { type: String, required: true }, csrf: { type: String, default: '' } },
  data() {
    return { theme: document.documentElement.dataset.theme || 'light' };
  },
  methods: {
    async toggle() {
      this.theme = this.theme === 'dark' ? 'light' : 'dark';
      document.documentElement.dataset.theme = this.theme;
      await fetch(this.url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ theme: this.theme }),
      });
    },
  },
};
</script>
