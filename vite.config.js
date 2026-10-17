import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'path';

export default defineConfig({
  plugins: [vue(), tailwindcss()],
  root: './',
  base: '/assets/',
  // public/ is the Symfony web root and also holds outDir: never copy it into the build output
  publicDir: false,
  build: {
    outDir: 'public/assets',
    // Keep the previous bundle in place while a watch rebuild runs (stable file names, no 404 flicker).
    emptyOutDir: false,
    manifest: true,
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'assets/scripts/main.js'),
      },
      output: {
        entryFileNames: `[name].js`,
        chunkFileNames: `[name].js`,
        assetFileNames: `[name].[ext]`,
      },
    },
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, 'assets'),
    },
  },
});
