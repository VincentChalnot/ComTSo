// The `youtube` block node of the rich editor. It serialises to the exact <iframe> the server sanitizer keeps
// (App\Content\ContentSanitizer), so a round trip is lossless.
import { Node } from '@tiptap/core';
import { Selection } from '@tiptap/pm/state';
import { youtubeEmbedUrl } from './youtube.js';

const IFRAME_ATTRIBUTES = {
  title: 'Vidéo YouTube',
  loading: 'lazy',
  allowfullscreen: '',
  allow: 'encrypted-media; picture-in-picture',
  referrerpolicy: 'strict-origin-when-cross-origin',
};

export const YouTube = Node.create({
  name: 'youtube',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return { src: { default: null } };
  },

  parseHTML() {
    return [
      {
        tag: 'iframe[src]',
        getAttrs: (element) => {
          const src = youtubeEmbedUrl(element.getAttribute('src'), { embedOnly: true });
          return src ? { src } : false;
        },
      },
    ];
  },

  renderHTML({ HTMLAttributes }) {
    return ['iframe', { src: HTMLAttributes.src, ...IFRAME_ATTRIBUTES }];
  },

  addCommands() {
    return {
      /**
       * Inserts a player: it replaces the empty paragraph the cursor is in, otherwise it goes below the current
       * block (or into `range`). The cursor ends up in the paragraph after the player, ready to keep typing.
       */
      insertYouTube:
        (src, range) =>
        ({ state, tr, dispatch }) => {
          const { $from, empty } = state.selection;
          const inEmptyParagraph = empty && $from.parent.type.name === 'paragraph' && $from.parent.content.size === 0;
          const from = range?.from ?? (inEmptyParagraph ? $from.before() : $from.after());
          const to = range?.to ?? (inEmptyParagraph ? $from.after() : from);

          if (dispatch) {
            const player = this.type.create({ src });
            tr.replaceWith(from, to, player);
            const after = from + player.nodeSize;
            if (!tr.doc.resolve(after).nodeAfter) tr.insert(after, state.schema.nodes.paragraph.create());
            tr.setSelection(Selection.near(tr.doc.resolve(after), 1)).scrollIntoView();
          }

          return true;
        },
    };
  },

  // In the editor the player sits in a wrapper so that clicks select the node instead of reaching the iframe.
  addNodeView() {
    return ({ node }) => {
      const dom = document.createElement('div');
      dom.className = 're-embed';
      dom.contentEditable = 'false';
      const iframe = document.createElement('iframe');
      iframe.setAttribute('src', node.attrs.src);
      for (const [name, value] of Object.entries(IFRAME_ATTRIBUTES)) iframe.setAttribute(name, value);
      iframe.tabIndex = -1;
      dom.append(iframe);

      return { dom };
    };
  },
});
