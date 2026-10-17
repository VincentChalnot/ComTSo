// Theme toggle for ANONYMOUS visitors (login page): flips <html data-theme> and remembers the choice in localStorage.
// Members use the Vue `ThemeToggle` instead (it stores the preference server side through the `user_theme` route).
//
// Wiring (see docs/design-system.md):
//  1. base.html.twig, in <head> for anonymous visitors, BEFORE the stylesheet (no flash of the wrong theme):
//       <script>(function(){var t=null;try{t=localStorage.getItem('comtso-theme')}catch(e){}
//         document.documentElement.dataset.theme=t||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light')})();</script>
//  2. markup: <button type="button" class="btn-icon theme-toggle theme-toggle-floating" data-theme-toggle aria-label="Changer de thème">
//               <svg data-when="light">moon</svg><svg data-when="dark">sun</svg></button>
//  3. assets/scripts/main.js:  import { initThemeToggle } from './theme-toggle.js'; initThemeToggle();
export const THEME_STORAGE_KEY = 'comtso-theme';

export function applyTheme(theme, persist = true) {
  document.documentElement.dataset.theme = theme;
  if (persist) {
    try {
      localStorage.setItem(THEME_STORAGE_KEY, theme);
    } catch (error) {
      // private mode / storage disabled: the choice simply lasts for the page
    }
  }
  document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.setAttribute('aria-pressed', String(theme === 'dark'));
    button.title = theme === 'dark' ? 'Thème clair' : 'Thème sombre';
  });
}

export function initThemeToggle(root = document) {
  root.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    if (button.dataset.themeToggleReady) return;
    button.dataset.themeToggleReady = '1';
    applyTheme(document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light', false);
    button.addEventListener('click', () => {
      applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
    });
  });
}
