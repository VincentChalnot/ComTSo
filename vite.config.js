import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/postcss';
import { resolve } from 'path';

// Stylesheet pipeline (see docs/design-system.md): Vite compiles Sass first (assets/styles/app.scss), then PostCSS runs
// @tailwindcss/postcss on the result, which resolves @import / @source / @theme / @apply / @custom-variant. Tailwind's own
// Vite plugin only handles `.css` files, hence PostCSS instead of `@tailwindcss/vite`.
export default defineConfig({
  plugins: [vue()],
  css: { postcss: { plugins: [tailwindcss()] } },
  root: './',
  base: '/assets/',
  // public/ is the Symfony web root and also holds outDir: never copy it into the build output
  publicDir: false,
  build: {
    outDir: 'public/assets',
    // Keep the previous bundle in place while a watch rebuild runs (stable file names, no 404 flicker).
    emptyOutDir: false,
    manifest: true,
    rolldownOptions: {
      input: {
        main: resolve(import.meta.dirname, 'assets/scripts/main.js'),
      },
      output: {
        entryFileNames: `[name].js`,
        chunkFileNames: `[name].js`,
        // main.css keeps a stable name (referenced by base.html.twig); fonts and textures are fingerprinted
        assetFileNames: (asset) => (/\.css$/.test(asset.names?.[0] ?? asset.name ?? '') ? '[name].[ext]' : '[name]-[hash].[ext]'),
      },
    },
  },
  resolve: {
    alias: [
      // paper textures (tracked in assets/images/textures) and self-hosted fonts, referenced from SCSS url()
      { find: /^@textures\//, replacement: resolve(import.meta.dirname, 'assets/images/textures') + '/' },
      { find: /^@fontsource-variable\//, replacement: resolve(import.meta.dirname, 'node_modules/@fontsource-variable') + '/' },
      { find: '@', replacement: resolve(import.meta.dirname, 'assets') },
    ],
  },
});
