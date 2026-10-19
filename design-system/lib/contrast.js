// WCAG 2.x contrast helpers + the list of every text/background pair the design system ships.
// Pure ESM: used by the showcase (browser, values read from computed styles) and by `npm run contrast` (Node, values read from
// the compiled stylesheet), so the table in docs/design-system.md and the showcase can never disagree.

export function parseColor(value) {
  const v = value.trim().toLowerCase();
  let m = v.match(/^#([0-9a-f]{6})$/);
  if (m) return [0, 2, 4].map((i) => parseInt(m[1].slice(i, i + 2), 16));
  m = v.match(/^#([0-9a-f]{3})$/);
  if (m) return [...m[1]].map((c) => parseInt(c + c, 16));
  m = v.match(/^rgba?\(\s*([\d.]+)[ ,]+([\d.]+)[ ,]+([\d.]+)/);
  if (m) return [m[1], m[2], m[3]].map(Number);
  throw new Error(`Unsupported colour "${value}"`);
}

export const toHex = ([r, g, b]) => `#${[r, g, b].map((n) => Math.round(n).toString(16).padStart(2, '0')).join('')}`;

export function luminance([r, g, b]) {
  const lin = (c) => {
    const v = c / 255;
    return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
}

export function ratio(a, b) {
  const la = luminance(a);
  const lb = luminance(b);
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/** Colour of a surface seen through a paper texture at its worst grain pixel (see `textureWorstCase`). */
export function textured(bg, tile, alpha) {
  // multiply: result = bg × (1 − α(1 − t)); screen: result = 1 − (1 − bg)(1 − α·s)
  if (tile.mode === 'multiply') return bg.map((c) => c * (1 - alpha * (1 - tile.value)));
  return bg.map((c) => 255 - (255 - c) * (1 - alpha * tile.value));
}

// [foreground role, background role, minimum ratio, description]
// 4.5 = normal text (AA), 3 = UI component / large text (AA).
export const PAIRS = [
  ['fg', 'page', 4.5, 'Texte courant sur le canvas'],
  ['fg', 'surface', 4.5, 'Texte courant sur une carte'],
  ['fg', 'surface-2', 4.5, 'Texte sur surface en creux'],
  ['fg', 'surface-3', 4.5, 'Texte sur surface 3'],
  ['fg-soft', 'page', 4.5, 'Chapô / standfirst'],
  ['fg-soft', 'surface', 4.5, 'Chapô sur carte'],
  ['fg-soft', 'surface-2', 4.5, 'Texte secondaire sur surface en creux'],
  ['fg-soft', 'overlay', 4.5, 'Titre de catégorie du popover d’émojis (éditeur)'],
  ['muted', 'page', 4.5, 'Texte atténué (meta, placeholder) sur canvas'],
  ['muted', 'surface', 4.5, 'post-meta, placeholder, légendes'],
  ['muted', 'surface-2', 4.5, 'Texte atténué sur surface en creux'],
  ['muted', 'surface-3', 4.5, 'Texte atténué sur surface 3'],
  ['link', 'page', 4.5, 'Lien'],
  ['link', 'surface', 4.5, 'Lien sur carte'],
  ['link', 'surface-2', 4.5, 'Lien sur surface en creux'],
  ['link-hover', 'page', 4.5, 'Lien survolé'],
  ['link-hover', 'surface', 4.5, 'Lien survolé sur carte'],
  ['kicker', 'page', 4.5, 'Kicker (surtitre)'],
  ['kicker', 'surface', 4.5, 'Kicker sur carte'],
  ['kicker', 'surface-2', 4.5, 'Kicker sur surface en creux'],
  ['accent-strong', 'page', 4.5, 'Texte prune / onglet actif'],
  ['accent-strong', 'surface', 4.5, 'Texte prune sur carte'],
  ['accent-strong', 'surface-2', 4.5, 'Texte prune sur surface en creux'],
  ['accent-strong', 'accent-soft', 4.5, 'Texte prune sur teinte prune (nav active, badge)'],
  ['danger', 'page', 4.5, 'Erreur / texte danger'],
  ['danger', 'surface', 4.5, 'Erreur sur carte'],
  ['danger', 'surface-2', 4.5, 'Erreur sur surface en creux'],
  ['danger', 'danger-soft', 4.5, 'Texte danger sur teinte danger (alerte)'],
  ['ok', 'page', 4.5, 'Succès / texte ok'],
  ['ok', 'surface', 4.5, 'Succès sur carte'],
  ['ok', 'ok-soft', 4.5, 'Texte ok sur teinte ok (alerte)'],
  ['on-accent', 'accent', 4.5, 'Bouton primaire'],
  ['on-accent', 'accent-hover', 4.5, 'Bouton primaire (survol)'],
  ['on-accent', 'accent-active', 4.5, 'Bouton primaire (pressé)'],
  ['on-secondary', 'secondary', 4.5, 'Bouton secondaire'],
  ['on-secondary', 'secondary-hover', 4.5, 'Bouton secondaire (survol)'],
  ['on-secondary', 'secondary-active', 4.5, 'Bouton secondaire (pressé)'],
  ['on-highlight', 'highlight', 4.5, 'Bouton sable'],
  ['on-highlight', 'highlight-hover', 4.5, 'Bouton sable (survol)'],
  ['on-highlight', 'highlight-active', 4.5, 'Bouton sable (pressé)'],
  ['on-danger', 'danger-fill', 4.5, 'Bouton / badge danger plein'],
  ['on-danger', 'danger-fill-hover', 4.5, 'Bouton danger (survol)'],
  ['on-ok', 'ok-fill', 4.5, 'Badge ok plein'],
  ['on-ok', 'ok-fill-hover', 4.5, 'Badge ok (survol)'],
  ['on-btn', 'btn', 4.5, 'Bouton neutre'],
  ['on-btn', 'btn-hover', 4.5, 'Bouton neutre (survol)'],
  ['on-btn', 'btn-active', 4.5, 'Bouton neutre (pressé)'],
  ['danger', 'btn', 4.5, 'Bouton danger (papier, texte brique)'],
  ['fg', 'accent-soft', 4.5, 'Texte sur teinte prune'],
  ['fg', 'secondary-soft', 4.5, 'Texte sur teinte bleue'],
  ['fg', 'highlight-soft', 4.5, 'Texte sur teinte sable (cible de permalien)'],
  ['fg', 'danger-soft', 4.5, 'Texte sur teinte danger'],
  ['fg', 'ok-soft', 4.5, 'Texte sur teinte ok'],
  ['fg', 'mist-soft', 4.5, 'Texte sur teinte brume'],
  ['fg-soft', 'mist-soft', 4.5, 'Texte secondaire sur teinte brume'],
  ['fg', 'highlight-soft@0.62>surface', 4.5, 'Message ciblé par un permalien (.post.is-target)'],
  ['fg-soft', 'highlight-soft@0.62>surface', 4.5, 'Texte secondaire, message ciblé'],
  ['muted', 'highlight-soft@0.62>surface', 4.5, 'Méta de message ciblé'],
  ['link', 'highlight-soft@0.62>surface', 4.5, 'Lien dans un message ciblé'],
  ['danger', 'highlight-soft@0.62>surface', 4.5, 'Action supprimer dans un message ciblé'],
  ['accent-strong', 'highlight-soft@0.62>surface', 4.5, 'Texte prune dans un message ciblé'],
  ['on-accent', 'scrim@0.82>on-accent', 4.5, 'Légende de hero sur photo (voile 82 %, photo claire)'],
  ['fg', 'surface@0.92>scrim', 4.5, 'Titre de hero en surimpression, photo sombre'],
  ['fg', 'surface@0.92>on-accent', 4.5, 'Titre de hero en surimpression, photo claire'],
  ['fg-soft', 'surface@0.92>scrim', 4.5, 'Surtitre / signature de hero, photo sombre'],
  ['fg-soft', 'surface@0.92>on-accent', 4.5, 'Surtitre / signature de hero, photo claire'],
  ['line-strong', 'page', 3, 'Bordure de champ / contrôle (UI)'],
  ['line-strong', 'surface', 3, 'Bordure de champ sur carte (UI)'],
  ['line-strong', 'surface-2', 3, 'Bordure de champ sur surface en creux (UI)'],
  ['focus', 'page', 3, 'Anneau de focus (UI)'],
  ['focus', 'surface', 3, 'Anneau de focus sur carte (UI)'],
  ['focus', 'surface-2', 3, 'Anneau de focus sur surface en creux (UI)'],
  ['star', 'page', 3, 'Étoile / favori (UI)'],
  ['star', 'surface', 3, 'Étoile sur carte (UI)'],
];

/** A background is a role name, or "role@0.62>other" = `role` at 62 % opacity composited over `other`. */
export function backdrop(colors, spec) {
  const m = spec.match(/^([a-z0-9-]+)@([\d.]+)>([a-z0-9-]+)$/);
  if (!m) return colors[spec];
  const [, top, alpha, under] = m;
  return colors[top].map((c, i) => c * Number(alpha) + colors[under][i] * (1 - Number(alpha)));
}

/** Evaluate every pair for one theme. `colors` maps role -> [r, g, b]. */
export function evaluate(colors, pairs = PAIRS) {
  return pairs.map(([fg, bg, min, label]) => {
    const value = ratio(colors[fg], backdrop(colors, bg));
    return { fg, bg, min, label, ratio: value, pass: value >= min };
  });
}
