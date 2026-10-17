# Markup of the new components

Skeletons exactly as rendered by the templates (Twig/Vue sources in brackets). Class names of the shared contract
(`.hero*`, `.thread-preview*`, `.post-photos`, `.post-photo`, `.attach*`, `.reply*`, `.kicker`, `.standfirst`, `.byline`, `.rule`, `.badge*`,
`.avatar-stack`, `.btn*`) are styled by the design system; section "Classes outside the contract" lists the extra hooks.
No component carries inline styles; the few Tailwind utilities left in templates are layout glue and can be replaced.

## Hero — picture of the week (`templates/home/_hero.html.twig`, top of the home page)

```html
<section class="hero" aria-labelledby="hero-title">
  <a class="hero-media" href="/photos/{id}" aria-label="Agrandir la photo : {title}" data-lightbox="hero" data-src="/photos/{id}/hero"
     data-full="…" data-download="…" data-page="/photos/{id}" data-caption="{title}"> <!-- opens the lightbox; page link without JS -->
    <img class="hero-image" src="/photos/{id}/hero" alt="{title}" width="…" height="…" fetchpriority="high" decoding="async">
  </a>
  <div class="hero-body">
    <p class="kicker">Photo de la semaine | Photo du mois | Photo à redécouvrir</p>
    <h2 class="hero-title" id="hero-title">{title or 'Photo sans titre'}</h2>
    <p class="byline">
      Par <a href="/gens/{user}">{author}</a> · <time datetime="Y-m-d">d/m/Y</time> · dans <a href="/comment/{id} or /forum/{f}/{t}">{topic title}</a>
    </p>
    <a class="btn btn-primary" href="/photos/{id}">Voir la photo</a>
  </div>
</section>
```
`width`/`height` are the dimensions of the 1600 px `hero` variant (aspect ratio known up front). Author and "dans …" parts are optional.

## Thread preview (`templates/forum/_thread_preview.html.twig`, list wrapper `<div class="space-y-6">` in `forum/show.html.twig`)

```html
<article class="thread-preview [has-cover] [is-new]" aria-labelledby="thread-title-{id}">
  <a class="thread-preview-cover" href="/forum/{f}/{t}" tabindex="-1" aria-hidden="true"><img src="/photos/{id}/thumb" alt="" width="320" height="320" loading="lazy"></a> <!-- only with a cover -->
  <div class="thread-preview-body">
    <p class="kicker">{forum} · <time datetime title="d/m/Y à H:i">il y a 3 h</time></p>
    <h2 class="thread-preview-title" id="thread-title-{id}"><a href="/forum/{f}/{t}">{title}</a></h2>
    <p class="standfirst thread-preview-excerpt">{plain text}</p>                               <!-- only if non-empty -->
    <blockquote class="thread-preview-last">                                                    <!-- only with a last reply -->
      <span class="sr-only">Dernier message de </span><span class="thread-preview-last-author">{user}</span>
      {plain excerpt | (photo)} <time datetime title>il y a 2 j</time>
    </blockquote>
    <footer class="thread-preview-meta">
      <form data-star-form …>…</form>                                                           <!-- topic/_star.html.twig -->
      <ul class="avatar-stack" aria-label="Participants">
        <li title="alice" aria-label="alice">{avatar, sm}</li> …
        <li class="avatar-stack-more" title="2 autres participants" aria-label="2 autres participants">+2</li>
      </ul>
      <span class="thread-preview-stat">12 messages</span>
      <span class="thread-preview-stat">340 vues</span>
      <span class="thread-preview-stat">4 photos</span>                                         <!-- only if > 0 -->
      <span class="badge badge-accent">2 nouveaux</span>                                        <!-- "1 nouveau"; only if is-new -->
      <a class="btn btn-sm btn-ghost" href="/forum/{f}/{t}">Lire le sujet</a>
      <button type="button" class="btn btn-sm thread-preview-toggle" aria-expanded="false" aria-controls="preview-more-{id}" data-preview-url="/forum/{f}/{t}/preview" hidden>Aperçu</button>
    </footer>
    <div class="thread-preview-more" id="preview-more-{id}" tabindex="-1" hidden></div>
  </div>
</article>
```
The toggle is rendered `hidden`; `assets/scripts/ui.js` (`initThreadPreviews`) reveals it, so there is no dead control without JavaScript.
When opened: `aria-expanded="true"`, label "Masquer l'aperçu", `.thread-preview-more` loses `hidden` and receives focus.
Content of `.thread-preview-more` (all generated):
- loading: `<p role="status">Chargement…</p>` (container has `aria-busy="true"`)
- error: `<p role="alert">Impossible de charger l'aperçu.</p><button type="button" class="btn btn-sm">Réessayer</button>`
- loaded: the fragment of `templates/topic/_preview.html.twig`: 5 `article.post` (see below, `hide_actions`) then `<p class="mt-3"><a class="btn btn-primary btn-sm" href="/forum/{f}/{t}">Lire le sujet</a></p>`
  (a topic without messages shows `<p class="text-sm text-muted">Aucun message dans ce sujet pour le moment.</p>` first).

## Message and its photos (`templates/_post/message.html.twig`)

```html
<article class="post" id="post-{id}" data-post>
  <a href="/gens/{user}" class="shrink-0 self-start">{avatar}</a>
  <div class="min-w-0 flex-1">
    <p class="post-author">{author}</p>
    <div class="post-body" data-collapsible>{sanitized HTML}</div>                              <!-- omitted for a photo-only message -->
    <ul class="post-photos" aria-label="Photos du message">                                     <!-- only with photos -->
      <li class="post-photo">
        <a href="/photos/{id}" class="group/photo relative block size-24 sm:size-32 … " data-lightbox="post-{commentId}" data-src="/photos/{id}/medium" data-full data-download data-page data-caption>
          <img src="/photos/{id}/thumb" alt="{title or filename}" loading="lazy" class="size-full object-cover …">
        </a>
      </li>
    </ul>
    <div class="post-meta">…<a href="/comment/{id}">{icon} Lien</a> … <form data-confirm="…"><button type="submit" class="is-danger">…</button></form></div>
  </div>
</article>
```
The thumbnail link is the existing macro `templates/_ui/photo.html.twig` (`ph.thumb(photo, group, size)`); the lightbox group is the message (`post-{commentId}`).

## Reply form (`templates/topic/show.html.twig`)

The `<form>` wraps the section (so editor, widget and submit button belong to the same form):

```html
<form name="comment" method="post" action="/forum/{f}/{t}/comment" enctype="multipart/form-data">
  <section class="reply mt-8" aria-labelledby="reply">
    <span class="reply-avatar">{avatar of the member}</span>
    <div class="reply-body">
      <h2 id="reply" class="card-title mb-2">Répondre</h2>
      <div class="field mb-5"> <!-- form_row content: rich text editor (RichEditor) --> </div>
      <div class="field mb-5"><label class="label" for="comment_photos">Ajouter des photos</label> {attach widget} </div>
    </div>
    <div class="reply-actions"><button type="submit" class="btn btn-primary">{icon} Envoyer</button></div>
  </section>
  <input type="hidden" name="comment[_token]" …>
</form>
```

## Attach widget (`templates/form/photo_upload.html.twig` + `assets/vue/components/AttachPhotos.vue`)

Used by the reply form, the message edit page (`comment[photos][]`) and the new-topic form (`topic[photos][]`).
Server-rendered (no JavaScript — a working native multiple file input):

```html
<div class="attach" data-vue-component="AttachPhotos" data-props='{"name":"comment[photos][]","id":"comment_photos","maxFiles":10,"maxSize":26214400,"accept":"image/jpeg,image/png,image/gif,image/webp","existing":0}'>
  <div class="attach-zone">
    <input type="file" id="comment_photos" name="comment[photos][]" multiple class="attach-input" accept="image/jpeg,image/png,image/gif,image/webp">
    <p class="attach-hint">Jusqu'à 10 photos (JPEG, PNG, GIF ou WebP, 25 Mo maximum chacune).</p>
  </div>
</div>
```

After Vue mounted (same root, children replaced):

```html
<div class="attach" data-vue-component="AttachPhotos" data-vue-mounted="1">
  <div>
    <div class="attach-zone [is-dragover]" tabindex="0" role="group" aria-label="Ajouter des photos : glissez-déposez, collez ou choisissez des fichiers">
      <input class="attach-input" type="file" multiple id name accept aria-describedby="{id}-hint">
      <p class="attach-hint" id="{id}-hint">Glissez-déposez des photos ici, collez-les … — 10 photos possibles, 25,0 Mo maximum chacune.</p>
    </div>
    <ul class="attach-tray">                                                     <!-- only with files -->
      <li class="attach-item">
        <img src="blob:…" alt="" width="80" height="80">
        <span class="attach-item-name" title="{file name}">{file name}</span>
        <span class="attach-item-size">1,2 Mo</span>
        <button type="button" class="attach-item-remove" aria-label="Retirer {file name}"><span aria-hidden="true">×</span></button>
      </li>
    </ul>
    <p class="attach-count" aria-live="polite">2 photos sur 10 possibles</p>      <!-- only with files -->
    <p class="attach-error" role="alert"><span class="block">message</span>…</p>   <!-- only when a file was refused -->
  </div>
</div>
```
Server-side field errors (too many / too heavy / not an image) render through the form theme as `<ul class="grid gap-1"><li class="field-error">…</li></ul>` after the widget.

### Edit page: photos already attached (`templates/comment/edit.html.twig`)

```html
<fieldset class="mb-6">
  <legend>Photos jointes</legend>
  <ul class="attach-tray">
    <li class="attach-item">
      <img src="/photos/{id}/thumb" alt="" width="80" height="80" loading="lazy">
      <span class="attach-item-name">{title or filename}</span>
      <span class="attach-item-remove"><input type="checkbox" id="comment_remove_photos_0" name="comment[remove_photos][]" value="{linkId}"> <label for="comment_remove_photos_0">Retirer</label></span>
    </li>
  </ul>
</fieldset>
{attach widget}
```
Here `.attach-item-remove` wraps a *checkbox + label* (a native "remove" toggle) rather than the button of the Vue tray.

## Photo page: where the photo is used (`templates/photo/show.html.twig`)

```html
<h2 class="card-title mb-2 mt-6">Cette photo apparaît dans</h2>
<ul class="space-y-1 text-sm">
  <li><a href="/forum/{f}/{t}">{topic}</a> <span class="text-muted">· sujet · {forum}</span></li>
  <li><a href="/comment/{id}">Message de {author}</a> <span class="text-muted">du d/m/Y · dans <a href="/forum/{f}/{t}" class="text-muted">{topic}</a> · {forum}</span></li>
</ul>
```

## Classes outside the contract

New hooks (need styling):
- `.avatar-stack-more` — the "+N" `<li>` at the end of `.avatar-stack`.
- `.attach-item-name`, `.attach-item-size` — file name / size inside `.attach-item`.
- State classes: `.has-cover`, `.is-new` (on `.thread-preview`), `.is-dragover` (on `.attach-zone`), all in the contract.
- `[hidden]` is relied upon on `.thread-preview-toggle` and `.thread-preview-more`: make sure `display` rules do not override the `hidden` attribute.
- `.attach-item-remove` is used on a `<button>` (Vue tray) and on a `<span>` holding a checkbox + label (edit page).
- `.thread-preview-meta` holds a form (the star toggle `form[data-star-form]`), the avatar stack, stats, badge, link and button.

Already in `assets/styles/app.css`, reused as is: `.btn`, `.btn-primary`, `.btn-sm`, `.btn-ghost`, `.card-title`, `.label`, `.field-error`, `.input`, `.post`, `.post-body`, `.post-meta`, `.post-toggle`, `.lightbox`, `.lightbox-btn`.
Tailwind utilities left in the new markup (layout glue): `sr-only`, `space-y-6`, `mt-3`, `mt-5`, `mb-2`, `mb-4`, `mt-6`, `block`, `size-4`, `size-8`, `size-24 sm:size-32` (thumb size passed to the photo macro), `text-sm`, `text-muted`, `text-xs`, `space-y-1`, `mt-1`, `space-y-0.5`.

## Home digest, forum index, topic header (`templates/_digest/topic_card.html.twig`, `forum/index.html.twig`, `topic/show.html.twig`)

```html
<div class="digest-list">                                          <!-- home -->
  <section class="digest" aria-labelledby="topic-{id}">
    <header class="digest-head">
      <p class="kicker"><span><a href="/forums/{f}">{forum}</a> · 26 messages · nouveau sujet</span></p>
      <div class="digest-title-row"><h3 id="topic-{id}" class="digest-title"><a href>{title}</a></h3> {star form} </div>
    </header>
    <div class="post-body is-lead" data-collapsible>…</div>        <!-- only for a topic created since the date -->
    <ul class="post-photos [is-wide]">…</ul>                          <!-- is-wide above 6 photos (auto-fill squares) -->
    <p class="digest-note">N messages plus anciens non affichés — <a>voir le sujet</a></p>
    <div class="digest-posts"> article.post … </div>
    <footer class="digest-foot"><a class="btn btn-primary">Voir le sujet</a></footer>
  </section>
</div>

<ol class="toc">                                                       <!-- forum index -->
  <li class="toc-entry"><span class="toc-index" aria-hidden="true">01</span>
    <div class="toc-body"><h2 class="toc-title"><a href>{forum}</a></h2><div class="toc-desc">{rich text}</div></div>
    <p class="toc-meta"><span><strong>77</strong> sujets</span><span>dernière modification le …</span></p></li>
</ol>

<nav class="tabs" aria-label="Forums"><a href [aria-current="page"]>…</a></nav>   <!-- forum/_tabs -->
<header class="page-header">                                          <!-- topic -->
  <p class="kicker"><a>{forum}</a></p><h1 class="page-title">{title}</h1>
  <p class="byline"><span>Par <a>…</a></span><time>…</time><span>12 messages</span><span>197 vues</span></p>
  <div class="page-actions">{star form} <a class="btn btn-sm">Modifier</a> <a class="btn btn-sm">Photos</a></div>
</header>
```
Form theme (`form/theme.html.twig`): rows are `<div class="field mb-5">label + widget + .field-help + ul.grid > li.field-error</div>`; a lone checkbox/radio row is `.field > label.check > input + span`; expanded choices are `.check-group > .check > input + label`.
