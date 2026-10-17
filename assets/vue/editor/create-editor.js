// Tiptap editor factory of RichEditor.vue. Kept in its own module so that Vite code-splits Tiptap/ProseMirror
// into a chunk that is only downloaded on pages that actually contain an editor (see the dynamic import there).
import { Editor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import TextAlign from '@tiptap/extension-text-align';
import { Placeholder } from '@tiptap/extensions';
import { YouTube } from './youtube-node.js';
import { youtubeEmbedUrl } from './youtube.js';

const ALLOWED_LINK = /^(?:https?:|mailto:|\/(?!\/)|#)/i;
const IMAGE_URL = /^https?:\/\/\S+\.(?:png|jpe?g|gif|webp|avif|svg)(?:\?\S*)?$/i;
// Elements that make no sense in a message and may come along when pasting a web page.
const FOREIGN_ELEMENTS = 'script, style, meta, link, title, svg, object, embed, form, input, button, select, textarea';

/** Reduces HTML coming from another page/app (Word, a web page...) to its structure: no styles, classes or ids. */
function cleanPastedHtml(html) {
  // Content copied inside the editor itself already is clean and keeps its alignment.
  if (html.includes('data-pm-slice')) return html;

  const doc = new DOMParser().parseFromString(html, 'text/html');
  doc.querySelectorAll(FOREIGN_ELEMENTS).forEach((node) => node.remove());
  doc.querySelectorAll('[style], [class], [id]').forEach((node) => {
    node.removeAttribute('style');
    node.removeAttribute('class');
    node.removeAttribute('id');
  });
  doc.querySelectorAll('img').forEach((img) => {
    // data:/blob:/file: sources and page-relative paths cannot work once posted.
    if (!/^(?:https?:)?\/\//i.test(img.getAttribute('src') || '') && !(img.getAttribute('src') || '').startsWith('/')) img.remove();
  });

  return doc.body.innerHTML;
}

/**
 * @param {object} options
 * @param {HTMLElement} options.element Element the editable surface is mounted into.
 * @param {string} options.content Initial HTML.
 * @param {string} options.placeholder
 * @param {string} options.ariaLabel Accessible name of the editable area.
 * @param {string} options.id `id` of the editable area (the original field id, so that anchors keep working).
 * @param {() => void} options.onSubmit Ctrl/Cmd+Enter.
 */
export function createEditor({ element, content, placeholder, ariaLabel, id, onSubmit }) {
  let editor = null;

  /** A lone YouTube/image URL pasted into an empty paragraph becomes the player/image (other URLs become links). */
  const handlePaste = (view, event) => {
    const text = event.clipboardData?.getData('text/plain')?.trim() ?? '';
    if (!text || /\s/.test(text)) return false;

    const { selection } = view.state;
    const { $from } = selection;
    const inEmptyParagraph = selection.empty && $from.parent.type.name === 'paragraph' && $from.parent.content.size === 0;
    if (!inEmptyParagraph) return false;

    const video = youtubeEmbedUrl(text);
    if (video) {
      editor.chain().focus().insertYouTube(video).run();
      return true;
    }
    if (IMAGE_URL.test(text)) {
      editor.chain().focus().insertContent({ type: 'image', attrs: { src: text, alt: '' } }).run();
      return true;
    }

    return false;
  };

  editor = new Editor({
    element,
    content,
    extensions: [
      StarterKit.configure({
        // Legacy posts contain h1-h4 (no toolbar button, but editing such a post must not flatten them).
        heading: { levels: [1, 2, 3, 4] },
        link: {
          openOnClick: false,
          autolink: true,
          linkOnPaste: true,
          defaultProtocol: 'https',
          isAllowedUri: (url) => ALLOWED_LINK.test(url),
          HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
        },
      }),
      // Inline: smilies live inside paragraphs.
      Image.configure({ inline: true, allowBase64: false }),
      Subscript,
      Superscript,
      TextAlign.configure({ types: ['heading', 'paragraph'], alignments: ['left', 'center', 'right', 'justify'] }),
      YouTube,
      Placeholder.configure({ placeholder }),
    ],
    editorProps: {
      attributes: {
        class: 'post-body',
        ...(id ? { id } : {}),
        role: 'textbox',
        'aria-multiline': 'true',
        'aria-label': ariaLabel,
      },
      transformPastedHTML: cleanPastedHtml,
      handlePaste,
      handleKeyDown: (view, event) => {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
          event.preventDefault();
          onSubmit();

          return true;
        }

        return false;
      },
    },
  });

  return editor;
}
