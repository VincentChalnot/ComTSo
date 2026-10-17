// Renders the live colour documentation: brand palette, derived scales, semantic roles in both themes and the WCAG table.
// Everything is read from the compiled CSS custom properties (so it can never drift from what ships).
import { PAIRS, evaluate, parseColor, toHex, backdrop } from './lib/contrast.js';

const GROUPS = [
  ['Surfaces', ['page', 'surface', 'surface-2', 'surface-3', 'overlay']],
  ['Filets', ['line', 'line-soft', 'line-strong', 'rule']],
  ['Texte', ['fg', 'fg-soft', 'muted']],
  ['Prune — action principale', ['accent', 'accent-hover', 'accent-active', 'accent-strong', 'accent-soft', 'on-accent']],
  ['Bleu — liens, info, action secondaire', ['secondary', 'secondary-hover', 'secondary-active', 'secondary-soft', 'on-secondary', 'link', 'link-hover']],
  ['Sable — surbrillance', ['highlight', 'highlight-hover', 'highlight-active', 'highlight-soft', 'on-highlight', 'kicker', 'star']],
  ['Brume — filets, surfaces secondaires', ['mist', 'mist-soft', 'mist-strong']],
  ['Bouton neutre', ['btn', 'btn-hover', 'btn-active', 'on-btn']],
  ['Statuts', ['danger', 'danger-fill', 'danger-fill-hover', 'danger-soft', 'on-danger', 'ok', 'ok-fill', 'ok-fill-hover', 'ok-soft', 'on-ok']],
  ['Divers', ['focus', 'selection', 'shadow', 'scrim']],
];
const BRAND = [
  ['plum', 'Prune', 'Action principale, marque', '#950952'],
  ['mist', 'Brume', 'Filets, surfaces secondaires, avatars', '#95b2b8'],
  ['sand', 'Sable', 'Surbrillance : étoiles, surtitres, focus (sombre)', '#f0a868'],
  ['blue', 'Bleu profond', 'Liens, info, action secondaire', '#00487c'],
  ['ink', 'Encre', 'Texte en clair, canvas du thème sombre', '#2b2118'],
];
const STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

const probes = {};
function probe(theme) {
  if (!probes[theme]) {
    const el = document.createElement('div');
    el.dataset.theme = theme;
    el.hidden = false;
    el.style.cssText = 'position:absolute;width:0;height:0;overflow:hidden;visibility:hidden';
    document.body.append(el);
    probes[theme] = el;
  }
  return probes[theme];
}
const cssVar = (theme, name) => getComputedStyle(probe(theme)).getPropertyValue(name).trim();
const rootVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

function luminanceClass(hex) {
  const [r, g, b] = parseColor(hex);
  return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.58 ? 'is-light' : 'is-dark';
}

function brand(host) {
  host.innerHTML = BRAND.map(([key, name, role, hex]) => {
    const value = rootVar(`--color-${key}-${key === 'plum' ? 700 : key === 'mist' ? 400 : key === 'sand' ? 300 : key === 'blue' ? 700 : 900}`) || hex;
    return `<div class="ds-brand-chip ${luminanceClass(value)}" style="background-color:${value}">
      <span class="ds-brand-name">${name}</span>
      <span class="ds-brand-hex">${value}</span>
      <span class="ds-brand-role">${role}</span>
    </div>`;
  }).join('');
}

function scales(host) {
  const rows = ['plum', 'mist', 'sand', 'blue', 'ink', 'paper']
    .map((name) => {
      const steps = (name === 'paper' ? [50, 100, 200, 300, 400] : STEPS)
        .map((step) => {
          const value = rootVar(`--color-${name}-${step}`);
          return `<li class="ds-step ${luminanceClass(value)}" style="background-color:${value}"><b>${step}</b><span>${value}</span></li>`;
        })
        .join('');
      return `<div><p class="ds-label">${name}</p><ul class="ds-steps">${steps}</ul></div>`;
    })
    .join('');
  host.innerHTML = rows;
}

function roles(host) {
  const rows = GROUPS.map(([title, names]) => {
    const body = names
      .map((role) => {
        const cells = ['light', 'dark']
          .map((theme) => {
            const value = cssVar(theme, `--c-${role}`);
            return `<td><span class="ds-role-chip ${luminanceClass(value)}" style="background-color:${value}"></span><code>${value}</code></td>`;
          })
          .join('');
        return `<tr><th scope="row"><code>${role}</code></th>${cells}</tr>`;
      })
      .join('');
    return `<tbody><tr class="ds-group"><th colspan="3">${title}</th></tr>${body}</tbody>`;
  }).join('');
  host.innerHTML = `<table class="ds-table"><thead><tr><th>Rôle <small>(var --c-…, classe Tailwind bg-/text-/border-…)</small></th><th>Clair</th><th>Sombre</th></tr></thead>${rows}</table>`;
}

function contrast(host) {
  const out = [];
  for (const theme of ['light', 'dark']) {
    const colors = {};
    new Set(PAIRS.flatMap(([fg, bg]) => [fg, ...bg.split(/[@>]/).filter((x) => /^[a-z]/.test(x) && !/^[\d.]+$/.test(x))])).forEach((role) => {
      const v = cssVar(theme, `--c-${role}`);
      if (v) colors[role] = parseColor(v);
    });
    const results = evaluate(colors);
    const failures = results.filter((r) => !r.pass).length;
    const rows = results
      .map(
        (r) => `<tr class="${r.pass ? '' : 'is-fail'}">
          <td><span class="ds-sample" style="color:${toHex(colors[r.fg])};background-color:${toHex(backdrop(colors, r.bg))}">Aa</span></td>
          <td><code>${r.fg}</code> / <code>${r.bg}</code></td><td>${r.label}</td>
          <td class="num">${r.ratio.toFixed(2)}</td><td class="num">${r.min}</td><td>${r.pass ? 'AA ✓' : 'ÉCHEC'}</td></tr>`,
      )
      .join('');
    out.push(`<h3 class="ds-label" style="margin-top:1.5rem">Thème ${theme === 'light' ? 'clair' : 'sombre'} — ${failures ? `${failures} échec(s)` : 'toutes les paires passent WCAG AA'}</h3>
      <div class="ds-table-wrap"><table class="ds-table ds-table-compact"><thead><tr><th></th><th>Texte / fond</th><th>Usage</th><th>Ratio</th><th>Min.</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`);
  }
  host.innerHTML = out.join('');
}

export function renderTokens(root) {
  const run = (selector, fn) => root.querySelectorAll(selector).forEach(fn);
  run('[data-ds-brand]', brand);
  run('[data-ds-scales]', scales);
  run('[data-ds-roles]', roles);
  run('[data-ds-contrast]', contrast);
}
