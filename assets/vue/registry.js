// Vue is only used for rich interactive widgets. Templates mount them declaratively:
//   <div data-vue-component="ThemeToggle" data-props="{{ {...}|json_encode|e('html_attr') }}"></div>
//
// Every `assets/vue/components/<Name>.vue` is registered automatically under its file name:
// creating the file is enough, there is nothing to edit here.
const modules = import.meta.glob('./components/*.vue', { eager: true });

export default Object.fromEntries(
  Object.entries(modules).map(([path, module]) => [path.match(/\/([^/]+)\.vue$/)[1], module.default]),
);
