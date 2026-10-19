# Features of the old forum (comtso.fr before 2026-10-19)

Observed on 2026-10-17 by browsing the live site (read-only, nothing posted or deleted). The screenshots taken at the time were removed from the repository (they showed private member information) and are kept privately by the owner.
The "New app" column says how each feature is handled in this rewrite (`✔` done, `≈` done differently, `—` deliberately dropped, `…` still to do).

| # | Legacy feature | Details observed | New app |
|---|---|---|---|
| 1 | **Navigation bar** | Home, *Les forums*, *Les gens*, *Chat* (opens a floating panel), search box, *Config*, profile link, logout. Bootstrap/bootswatch skin. | ✔ Tailwind navbar, chat has its own page + unread badge |
| 2 | **Home = "Activités récentes"** | "Affichage des nouveautés depuis le [date picker]". Default = since the member's previous visit (`?form[since]=YYYY-MM-DD`). One card per topic with activity: ☆ favourite toggle, title, the photos added since, the new messages (avatar left), "Voir le topic" button. Empty state "Aucun message à afficher". | ✔ same logic (`since` and legacy `form[since]` accepted), paginated by topic, capped per topic with "N plus anciens non affichés" |
| 3 | **"Accès rapide" sidebar** | On every page: quick links to topics (on home: the topics shown, on a forum: the topics of the forum). | ≈ favourites (starred topics, with a dot when they have news since the last visit) |
| 4 | **Forums list** (`/forums/`) | Six forums with creation/last modification dates. | ✔ |
| 5 | **Forum page** (`/forums/{forum}`) | Forum tabs, "Modifier le forum", "Ajouter un topic", paginated (8–10 pages) feed of topic cards (star, title, description, photos, latest messages, "Voir le topic"), most recently active first. | ✔ cards with latest 2 messages; forum editing not ported (6 static forums, edit via SQL if ever needed); … deleting empty forums, planned only as part of an admin panel |
| 6 | **Topic page** (`/forum/{forum}/{id}`) | Title, created by/at, last modification, description, photo thumbnails, **pagination (~20 messages/page)**, messages, inline "Nouveau commentaire…" form, "Modifier le topic", "Gérer les photos". Lands on the **last page** (oldest first order). | ✔ lands on the page of the newest message in either order |
| 7 | **Message card** | Avatar on the left, never too wide, **long posts collapsed with "Afficher le post / Masquer le post"**, date/time and actions revealed on **hover** (tap on mobile), own messages editable/deletable, eye icon = own page. | ✔ |
| 8 | **Message permalink** (`/comment/{id}`) | "Commentaire #id", created by/at, "Retour au topic". Anchors `#post-{id}` in topic pages. | ✔ both (permalink page + anchors, with the right page computed) |
| 9 | **Favourite topics** | ☆/★ toggle (`/forum/{forum}/{id}/star|unstar`, GET links). Never really exploited in the old UI. | ✔ POST + CSRF, dot of news, listed in the sidebar |
| 10 | **Photos** | Topic photo strip, photo page `/photos/{id}` (title, author, dates, image, "Télécharger (55.0 Ko)", description, date taken, size), edit page. `/photos/source/{id}?download=1`. | ✔ + lightbox (prev/next, keyboard, swipe, download); … quick rotation for the owner and admins |
| 11 | **Photo management per topic** (`…/manage_photos`) | "Ajouter des photos" (multi-upload with start/cancel), "Trier les photos" (drag & drop), click a photo for actions. | ✔ drag & drop upload, reorder, remove from topic, delete |
| 12 | **Rich text editor** | WYSIWYG toolbar: bold, italic, underline, strike, sub/superscript, clear formatting, lists, indent/outdent, quote, alignment, link/unlink, image, **YouTube**. Content stored as HTML. | ✔ Tiptap editor (+ smilies picker), stored as sanitized HTML, YouTube restricted to the nocookie embed |
| 13 | **Topic editing** | Title, forum (move), author, content. | ✔ title + description (+ move for admins); author change dropped |
| 14 | **Search** (`/recherche?q=`) | "Accès rapide aux résultats": *Commentaires, Photos, Chats, Membres, Topics, Citations*; results with highlighted term, "Voir le post". | ✔ the six sections as tabs (Messages, Sujets, Photos, Chat, Membres, Citations) with a result count each and paginated results |
| 15 | **Les gens** (`/gens`) | Grid of round avatars of the active members. | ✔ enabled members; disabled accounts deliberately not shown (the relevant former members were re-enabled) |
| 16 | **Profile** (`/gens/{user}`) | Name + (pseudo), avatar, quote/signature, email, website, activities, birthday (age), registered on, phone, address, "Modifier". | ✔ |
| 17 | **Private messages** | On somebody's profile: "Messages privés" = conversation thread with that person + quick reply box. On your own profile: **"Mes mémos"** (notes to self). | ✔ |
| 18 | **Config** (`/gens/{me}/config`) | *Bootstrap theme* (17 bootswatch themes) and **"Ordre des messages"** (plus récent en dernier / en premier). | ✔ message order kept; bootswatch themes dropped for light/dark (users of a dark bootswatch theme default to dark); change or remove your own avatar on this page |
| 19 | **Chat** | Floating panel: online users, history (355 messages), message box; "il y a 12 ans, samedi…" timestamps. Was reported broken. | ✔ full-page chat, Mercure live updates (private), unread badge |
| 20 | **Random quote card** | A quote of the `ctso_quote` table (348) in the sidebar of every page, with author. | ✔; … adding a quote from a hovered quote (who may add: undecided) |
| 21 | **Smilies** | `<img src="/images/smilies/icon_*.gif">` inside messages (~10 000 occurrences). | ✔ served from `/smilies/` (+ picker); modern UTF-8 emojis for new messages (second picker, stored as text) |
| 22 | **Legacy URLs** | `topic.php?id=N` (first PHP version) pasted in messages. | ✔ `/topic.php?id=N` redirects |
| 23 | **Logout / login** | FOSUserBundle form login, remember me. Accounts can be disabled (`enabled=0`). | ✔ disabled accounts cannot log in; passwords migrate transparently |

Not present in the old site (ideas for later): per-user notifications by mail, unread markers per topic, drafts, image paste in the editor; a user admin screen and a statistics page are planned (`TODO.md` wave 3).
The `…` markers above match the backlog in `TODO.md`.
