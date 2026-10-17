// YouTube URL normalisation of the rich editor (mirror of App\Content\YouTube on the server). Dependency free:
// it is used by the main bundle while the Tiptap node (youtube-node.js) lives in the lazy editor chunk.

const ID = /^[A-Za-z0-9_-]{11}$/;
const EMBED_HOSTS = new Set(['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com']);
const MAX_START = 359999;

function seconds(value) {
  if (!value) return 0;
  if (/^\d{1,6}$/.test(value)) return Math.min(Number(value), MAX_START);
  const m = /^(?:(\d{1,3})h)?(?:(\d{1,4})m)?(?:(\d{1,6})s)?$/.exec(value);
  return m ? Math.min(3600 * Number(m[1] || 0) + 60 * Number(m[2] || 0) + Number(m[3] || 0), MAX_START) : 0;
}

function embed(id, start) {
  // `/embed/videoseries?list=…` (playlist player) has an 11-character "id" too: it is not a video.
  if (id === 'videoseries') return null;
  return `https://www.youtube-nocookie.com/embed/${id}${start > 0 ? `?start=${start}` : ''}`;
}

/**
 * Canonical nocookie embed URL of a YouTube link (watch, youtu.be, shorts, live or embed URL), or null.
 * With `embedOnly`, only `/embed/<id>` URLs are accepted (what an <iframe src> may contain).
 */
export function youtubeEmbedUrl(input, { embedOnly = false } = {}) {
  const raw = String(input ?? '').trim();
  if (!raw || raw.length > 300 || /[\s\\]/.test(raw)) return null;

  let url;
  try {
    url = new URL(raw.startsWith('//') ? `https:${raw}` : raw);
  } catch {
    return null;
  }
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.port) return null;

  const host = url.hostname.toLowerCase();
  const params = url.searchParams;

  if (EMBED_HOSTS.has(host)) {
    const m = /^\/embed\/([A-Za-z0-9_-]{11})\/?$/.exec(url.pathname);
    if (m) return embed(m[1], seconds(params.get('start')));
    if (embedOnly) return null;
    if (url.pathname === '/watch') {
      const v = params.get('v');
      return v && ID.test(v) ? embed(v, seconds(params.get('start') || params.get('t'))) : null;
    }
    const s = /^\/(?:shorts|live)\/([A-Za-z0-9_-]{11})\/?$/.exec(url.pathname);
    return s ? embed(s[1], seconds(params.get('start') || params.get('t'))) : null;
  }
  if (!embedOnly && host === 'youtu.be') {
    const m = /^\/([A-Za-z0-9_-]{11})\/?$/.exec(url.pathname);
    return m ? embed(m[1], seconds(params.get('start') || params.get('t'))) : null;
  }

  return null;
}
