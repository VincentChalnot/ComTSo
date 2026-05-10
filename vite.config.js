import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

export default defineConfig({
  plugins: [vue()],
  root: './',
  base: '/assets/',
  build: {
    outDir: 'public/assets',
    manifest: true,
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'assets/scripts/main.js'),
      },
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    hmr: {
      host: 'localhost',
      protocol: 'ws',
    },
    watch: {
      usePolling: true,
    },
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, 'assets'),
      vue: 'vue/dist/vue.esm-bundler.js',
    },
  },
});
