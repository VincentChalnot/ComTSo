// Dev-only Vite server for the design-system showcase (never part of the production image).
//   docker compose up -d design-system     ->  http://localhost:5174/
// It compiles the SAME Sass tree as the application (assets/styles/_index.scss) through the same Sass -> Tailwind 4 pipeline.
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/postcss';
import { resolve } from 'path';

const repo = resolve(__dirname, '..');

export default defineConfig({
  root: __dirname,
  // /images (paper textures), /smilies and the favicon are served exactly like the application serves them
  publicDir: resolve(repo, 'public'),
  cacheDir: resolve(repo, 'node_modules/.vite-design-system'),
  plugins: [vue()],
  css: { postcss: { plugins: [tailwindcss()] } },
  resolve: {
    alias: [
      { find: /^@textures\//, replacement: resolve(repo, 'assets/images/textures') + '/' },
      { find: '@', replacement: resolve(repo, 'assets') },
      { find: /^@fontsource-variable\//, replacement: resolve(repo, 'node_modules/@fontsource-variable') + '/' },
    ],
  },
  server: {
    host: true,
    port: Number(process.env.DESIGN_SYSTEM_INTERNAL_PORT || 5174),
    strictPort: true,
    allowedHosts: true,
    fs: { allow: [repo] },
  },
});
