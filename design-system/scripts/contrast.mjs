// `npm run contrast`  — compiles the application stylesheet's themes and checks every text/background pair of
// design-system/lib/contrast.js against WCAG AA in BOTH themes (also against the worst grain pixel of the paper textures).
// Prints a markdown table (pasted into docs/design-system.md) and exits 1 on any failure.
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as sass from 'sass';
import { PAIRS, backdrop, evaluate, parseColor, ratio, textured, toHex } from '../lib/contrast.js';

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '../..');
const css = sass.compile(resolve(repo, 'assets/styles/themes/_probe.scss'), { loadPaths: [resolve(repo, 'node_modules')] }).css;

function themeColors(selector) {
  const block = css.match(new RegExp(`${selector.replace(/[[\]"]/g, '\\$&')}\\s*\\{([^}]*)\\}`));
  if (!block) throw new Error(`theme block ${selector} not found`);
  const colors = {};
  for (const [, role, value] of block[1].matchAll(/--c-([a-z0-9-]+):\s*([^;]+);/g)) colors[role] = parseColor(value);
  return colors;
}

const themes = { light: themeColors('[data-theme=light]'), dark: themeColors('[data-theme=dark]') };
const stats = JSON.parse(readFileSync(resolve(repo, 'assets/images/textures/stats.json'), 'utf8'));
let failures = 0;
const out = [];

for (const [name, colors] of Object.entries(themes)) {
  out.push(`### Thème ${name === 'light' ? 'clair' : 'sombre'}\n`);
  out.push('| Texte | Fond | Ratio | Minimum | Usage | |');
  out.push('|---|---|---:|---:|---|---|');
  for (const r of evaluate(colors, PAIRS)) {
    if (!r.pass) failures += 1;
    out.push(`| \`${r.fg}\` ${toHex(colors[r.fg])} | \`${r.bg}\` ${toHex(backdrop(colors, r.bg))} | ${r.ratio.toFixed(2)} | ${r.min} | ${r.label} | ${r.pass ? 'OK' : '**ÉCHEC**'} |`);
  }
  out.push('');
}

// Worst case under the texture: text roles on the page/surface colours seen through the darkest (multiply, 5th percentile) or
// brightest (screen, 95th percentile) grain pixel at the strongest opacity the canvas / cards use.
out.push('### Fond sous texture (grain défavorable : 5e centile le plus sombre en clair, 95e le plus clair en sombre)\n');
out.push('| Thème | Texte | Fond texturé | Ratio | Minimum | |');
out.push('|---|---|---|---:|---:|---|');
const worst = {
  light: { tile: { mode: 'multiply', value: stats.paperMultiply.p05 }, alpha: { page: stats.canvasAlpha.light, surface: stats.cardAlpha.light } },
  dark: { tile: { mode: 'screen', value: stats.paperScreen.p95 }, alpha: { page: stats.canvasAlpha.dark, surface: stats.cardAlpha.dark } },
};
for (const [name, colors] of Object.entries(themes)) {
  for (const bgRole of ['page', 'surface']) {
    const bg = textured(colors[bgRole], worst[name].tile, worst[name].alpha[bgRole]);
    for (const fgRole of ['fg', 'fg-soft', 'muted', 'link', 'kicker']) {
      const value = ratio(colors[fgRole], bg);
      const pass = value >= 4.5;
      if (!pass) failures += 1;
      out.push(`| ${name} | \`${fgRole}\` | \`${bgRole}\` ${toHex(bg)} | ${value.toFixed(2)} | 4.5 | ${pass ? 'OK' : '**ÉCHEC**'} |`);
    }
  }
}

console.log(out.join('\n'));
console.log(failures ? `\n${failures} pair(s) below WCAG AA` : '\nAll pairs pass WCAG AA in both themes.');
process.exit(failures ? 1 : 0);
