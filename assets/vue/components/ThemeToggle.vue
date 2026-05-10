<template>
  <div class="theme-toggle">
    <button @click="toggleTheme" class="button is-small">
      <span v-if="currentTheme === 'light'">🌙 Dark Mode</span>
      <span v-else>☀️ Light Mode</span>
    </button>
  </div>
</template>

<script>
export default {
  name: 'ThemeToggle',
  data() {
    return {
      currentTheme: document.documentElement.getAttribute('data-theme') || 'light'
    };
  },
  methods: {
    toggleTheme() {
      const newTheme = this.currentTheme === 'light' ? 'dark' : 'light';
      this.currentTheme = newTheme;
      document.documentElement.setAttribute('data-theme', newTheme);

      // Save to server via API call
      fetch(`/user/theme`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ theme: newTheme })
      });
    }
  }
};
</script>

<style scoped>
.theme-toggle {
  display: inline-block;
}
</style>
