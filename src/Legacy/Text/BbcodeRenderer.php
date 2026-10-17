<?php

namespace App\Legacy\Text;

/**
 * Renders the BBCode flavours of phpBB 2 / phpBB 3 / the old PHP forum to the house HTML style:
 * `<p>` paragraphs separated by blank lines, `<br>` for single line breaks, `<blockquote><cite>`, plain
 * `<strong>/<em>/<u>/<del>`, smilies as `/smilies/` images and YouTube links as nocookie iframes.
 *
 * Input text is the *stored* form of those platforms: entity-encoded text, real newlines, optional
 * "uid" suffixes on tags (`[b:3bf54]…[/b:3bf54]`). Formatting that the new forum cannot represent
 * (colours, sizes, alignment) is dropped, unusable tags are removed rather than shown as junk.
 *
 * Options:
 *  - uid         known bbcode_uid (additional uids are discovered from closing tags)
 *  - bbcode      parse BBCode at all (false: everything is literal text)            [true]
 *  - smilies     convert plain text smiley codes (phpBB 2)                          [false]
 *  - autolink    turn bare http(s):// and www. addresses into links                 [false]
 *  - html        keep raw HTML tags found in the text (phpBB 2 "enable_html")       [false]
 *  - nl2br       newlines are line breaks (false: plain whitespace)                  [true]
 *  - paragraphs  wrap inline runs in <p> (false: the text already is block-level HTML)  [true]
 *  - tokens      list<string> pre-rendered inline HTML referenced as "\x01{n}\x02" in the text
 *  - attachment  callable(string $filename, string $index): ?string resolving [attachment] tags
 */
final class BbcodeRenderer
{
    private const BLOCK_TAGS = ['quote', 'code', 'list', 'youtube'];
    private const KNOWN_TAGS = 'b|i|u|s|strike|del|quote|code|list|\*|url|img|color|size|font|attachment|youtube|flash|email|center|left|right|align|spoiler';
    private const HTML_TAGS = 'a|b|i|u|s|em|strong|table|tbody|thead|tr|td|th|br|pre|ul|ol|li|span|div|p|font|center|h[1-6]|blockquote|strike|del|sup|sub|hr|img|cite|code';
    private const URL_PATTERN = '~(?<![\w/"\'=>@])((?:https?://|ftp://)[^\s<>"\x01\x02\[\]]+|www\.[^\s<>"\x01\x02\[\]]+\.[a-z]{2,}[^\s<>"\x01\x02\[\]]*)~iu';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @param array<string, mixed> $options */
    public function render(string $text, array $options = []): string
    {
        $this->options = $options + [
            'uid' => '', 'bbcode' => true, 'smilies' => false, 'autolink' => false, 'html' => false,
            'nl2br' => true, 'paragraphs' => true, 'tokens' => [], 'attachment' => null,
        ];
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (!$this->options['nl2br']) {
            $text = preg_replace('/\s*\n\s*/', ' ', $text) ?? $text;
        }

        $root = $this->options['bbcode']
            ? $this->parse($this->stripUids($text))
            : $this->plainTree($text);

        return $this->renderBlocks($root->children);
    }

    // ---------------------------------------------------------------- parsing

    private function plainTree(string $text): BbNode
    {
        $root = new BbNode('#root');
        $root->children[] = BbNode::text($text);

        return $root;
    }

    /** Removes the per-post "uid" suffix phpBB adds to every tag: `[b:uid]`, `[/*:m:uid]`, `[quote:uid="x"]`. */
    private function stripUids(string $text): string
    {
        $uids = [];
        if ('' !== $this->options['uid']) {
            $uids[] = preg_quote($this->options['uid'], '~');
        }
        // Closing tags never carry URLs: they reveal the uids in use (the old PHP forum lost the uid column).
        if (preg_match_all('~\[/(?:'.self::KNOWN_TAGS.')(?::[a-z0-9])?:([a-z0-9]{5,10})\]~i', $text, $m)) {
            foreach (array_unique($m[1]) as $uid) {
                $uids[] = preg_quote($uid, '~');
            }
        }
        if ($uids) {
            $alt = implode('|', array_unique($uids));
            $text = preg_replace('~(\[/?(?:'.self::KNOWN_TAGS.')[^\]\[]*?):(?:[a-z0-9]:)?(?:'.$alt.')(?=[\]="])~i', '$1', $text) ?? $text;
        }

        // phpBB 2 nesting counters: `[code:1]`, `[/code:1]`
        return preg_replace('~(\[/?(?:code|quote|list|b|i|u|url|img)):\d(?=\])~i', '$1', $text) ?? $text;
    }

    private function parse(string $text): BbNode
    {
        $root = new BbNode('#root');
        /** @var list<BbNode> $stack */
        $stack = [$root];
        $pos = 0;
        preg_match_all('~\[(/?)('.self::KNOWN_TAGS.')(=[^\]\n]*)?\]~i', $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);

        $add = static function (BbNode $parent, string $literal): void {
            if ('' !== $literal) {
                $parent->children[] = BbNode::text($literal);
            }
        };

        foreach ($matches as $m) {
            $start = $m[0][1];
            if ($start < $pos) {
                continue; // swallowed by a [code] block
            }
            $add($stack[array_key_last($stack)], substr($text, $pos, $start - $pos));
            $pos = $start + \strlen($m[0][0]);
            $closing = '/' === $m[1][0];
            $name = $this->canonicalTag($m[2][0]);
            $param = isset($m[3][0]) ? $this->cleanParam($m[3][0]) : null;

            if (!$closing) {
                if ('code' === $name) {
                    $end = preg_match('~\[/code\]~i', $text, $c, \PREG_OFFSET_CAPTURE, $pos) ? $c[0][1] : \strlen($text);
                    $node = new BbNode('code', null, substr($text, $pos, $end - $pos));
                    $stack[array_key_last($stack)]->children[] = $node;
                    $pos = isset($c[0]) ? $end + \strlen($c[0][0]) : $end;
                    continue;
                }
                if ('*' === $name) {
                    if ('*' === $stack[array_key_last($stack)]->name) {
                        array_pop($stack);
                    }
                    if ('list' !== $stack[array_key_last($stack)]->name) {
                        continue; // stray list item
                    }
                }
                $node = new BbNode($name, $param);
                $stack[array_key_last($stack)]->children[] = $node;
                $stack[] = $node;
                continue;
            }

            if ('*' === $name) {
                continue; // list items are closed implicitly
            }
            for ($i = \count($stack) - 1; $i > 0; --$i) {
                if ($stack[$i]->name === $name) {
                    array_splice($stack, $i);
                    break;
                }
            }
            // a closing tag without matching opener is dropped
        }
        $add($stack[array_key_last($stack)], substr($text, $pos));

        return $root;
    }

    private function canonicalTag(string $tag): string
    {
        $tag = strtolower($tag);

        return match ($tag) {
            'strike', 'del' => 's',
            default => $tag,
        };
    }

    private function cleanParam(string $raw): string
    {
        $param = trim(TextRepair::decodeEntities($raw));
        $param = ltrim($param, '= ');

        return trim($param, " \t\"'");
    }

    // ---------------------------------------------------------------- rendering

    /** @param list<BbNode> $nodes */
    private function renderBlocks(array $nodes, bool $top = true): string
    {
        $out = '';
        $inline = '';
        foreach ($nodes as $node) {
            if (!$node->isText() && \in_array($node->name, self::BLOCK_TAGS, true)) {
                $out .= $this->paragraphs($inline, $top);
                $inline = '';
                $out .= $this->renderBlockTag($node);
            } else {
                $inline .= $this->renderInlineNode($node);
            }
        }

        return $out.$this->paragraphs($inline, $top);
    }

    private function renderInlineNode(BbNode $node, bool $inLink = false): string
    {
        if ($node->isText()) {
            return $this->renderText((string) $node->text, $inLink);
        }
        if (\in_array($node->name, self::BLOCK_TAGS, true)) {
            return $this->renderBlockTag($node);
        }
        $inner = fn (): string => implode('', array_map(fn (BbNode $c): string => $this->renderInlineNode($c, $inLink), $node->children));

        return match ($node->name) {
            'b' => $this->wrap('strong', $inner()),
            'i' => $this->wrap('em', $inner()),
            'u' => $this->wrap('u', $inner()),
            's' => $this->wrap('del', $inner()),
            'url' => $this->renderUrl($node),
            'email' => $this->renderEmail($node),
            'img' => $this->renderImage($node),
            'attachment' => $this->renderAttachment($node),
            'flash', '*' => '',
            default => $inner(), // colour, size, alignment…: not representable, keep the content
        };
    }

    private function wrap(string $tag, string $html): string
    {
        return '' === trim($html) ? $html : "<$tag>$html</$tag>";
    }

    private function renderBlockTag(BbNode $node): string
    {
        switch ($node->name) {
            case 'quote':
                $content = $this->renderBlocks($node->children, false);
                if ('' === trim(strip_tags($content, '<img><iframe>'))) {
                    return '';
                }
                $cite = null !== $node->param && '' !== $node->param
                    ? '<cite>'.$this->escape($node->param).' a écrit :</cite>'
                    : '';

                return '<blockquote>'.$cite.$content.'</blockquote>';

            case 'code':
                $code = trim(TextRepair::decodeEntities((string) $node->text), "\n");
                if ('' === trim($code)) {
                    return '';
                }

                return '<pre><code>'.$this->escape($code).'</code></pre>';

            case 'list':
                $items = '';
                foreach ($node->children as $child) {
                    if ('*' === $child->name) {
                        $item = trim($this->paragraphsInline(implode('', array_map(fn (BbNode $c): string => $this->renderInlineNode($c), $child->children))));
                        if ('' !== $item) {
                            $items .= '<li>'.$item.'</li>';
                        }
                    }
                }
                if ('' === $items) {
                    return '';
                }
                $ordered = null !== $node->param && 1 === preg_match('/^(1|a|i)$/i', $node->param);

                return $ordered ? "<ol>$items</ol>" : "<ul>$items</ul>";

            case 'youtube':
                $id = $this->youtubeId(trim(TextRepair::decodeEntities($node->flatten())));

                return null !== $id ? self::youtubeEmbed($id) : '';
        }

        return '';
    }

    private function renderUrl(BbNode $node): string
    {
        $label = $node->children
            ? implode('', array_map(fn (BbNode $c): string => $this->renderInlineNode($c, true), $node->children))
            : '';
        $target = null !== $node->param && '' !== $node->param
            ? $node->param
            : trim(TextRepair::decodeEntities($node->flatten()));
        $href = self::safeUrl($target);
        if (null === $href) {
            return $label;
        }
        if ('' === trim(strip_tags($label, '<img>'))) {
            $label = $this->escape($target);
        }

        return '<a href="'.$this->escapeAttr($href).'">'.$label.'</a>';
    }

    private function renderEmail(BbNode $node): string
    {
        $address = null !== $node->param && '' !== $node->param ? $node->param : trim(TextRepair::decodeEntities($node->flatten()));
        if (!preg_match('/^[^\s@<>"]+@[^\s@<>"]+$/', $address)) {
            return $this->escape($node->flatten());
        }

        return '<a href="mailto:'.$this->escapeAttr($address).'">'.$this->escape($address).'</a>';
    }

    private function renderImage(BbNode $node): string
    {
        $src = null !== $node->param && '' !== $node->param && str_starts_with(strtolower($node->param), 'http')
            ? $node->param
            : trim(TextRepair::decodeEntities($node->flatten()));
        $src = self::safeUrl($src);
        if (null === $src || !preg_match('~^https?://~i', $src)) {
            return '';
        }

        return '<img src="'.$this->escapeAttr($src).'" alt="">';
    }

    private function renderAttachment(BbNode $node): string
    {
        $resolver = $this->options['attachment'];
        $filename = trim(TextRepair::decodeEntities($node->flatten()));
        $filename = trim(TextRepair::decodeEntities(preg_replace('~<!--.*?-->~s', '', $filename) ?? $filename));
        if ('' === $filename) {
            return '';
        }
        if (\is_callable($resolver)) {
            return (string) $resolver($filename, (string) $node->param);
        }

        // The file is not served by the new forum: keep a visible trace of what was attached.
        return '<em>[pièce jointe : '.$this->escape($filename).']</em>';
    }

    // ---------------------------------------------------------------- text

    private function renderText(string $raw, bool $inLink = false): string
    {
        $html = '';
        // Pre-rendered tokens ("\x01n\x02") are copied verbatim, everything around them is text.
        foreach (preg_split('/\x01(\d+)\x02/', $raw, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [] as $i => $part) {
            if (1 === $i % 2) {
                $html .= $this->options['tokens'][(int) $part] ?? '';
                continue;
            }
            $html .= $this->options['html'] ? $this->renderHtmlish($part, $inLink) : $this->renderPlain(TextRepair::decodeEntities($part), $inLink);
        }

        return $html;
    }

    /** Text that may contain raw HTML tags (phpBB 2 allowed them): tags are kept, the rest is plain text. */
    private function renderHtmlish(string $raw, bool $inLink): string
    {
        $html = '';
        foreach (preg_split('~(</?(?:'.self::HTML_TAGS.')\b[^>]*>)~i', $raw, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [] as $i => $part) {
            $html .= 1 === $i % 2 ? $part : $this->renderPlain(TextRepair::decodeEntities($part), $inLink);
        }

        return $html;
    }

    private function renderPlain(string $text, bool $inLink): string
    {
        if ('' === $text) {
            return '';
        }
        $segments = $this->options['autolink'] && !$inLink
            ? (preg_split(self::URL_PATTERN, $text, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$text])
            : [$text];
        $html = '';
        foreach ($segments as $i => $segment) {
            if ('' === $segment) {
                continue;
            }
            if ($this->options['autolink'] && !$inLink && 1 === $i % 2) {
                $html .= $this->autolink($segment);
                continue;
            }
            if ($this->options['smilies']) {
                foreach (SmileyMap::split($segment) as [$kind, $value]) {
                    $html .= 'smiley' === $kind ? $value : $this->escape($value);
                }
            } else {
                $html .= $this->escape($segment);
            }
        }

        return $html;
    }

    private function autolink(string $url): string
    {
        $trail = '';
        while ('' !== $url && (1 === preg_match('/[.,;:!?\'"]$/', $url) || (str_ends_with($url, ')') && !str_contains($url, '(')))) {
            $trail = substr($url, -1).$trail;
            $url = substr($url, 0, -1);
        }
        $href = self::safeUrl($url);
        if (null === $href) {
            return $this->escape($url.$trail);
        }

        return '<a href="'.$this->escapeAttr($href).'">'.$this->escape($url).'</a>'.$this->escape($trail);
    }

    /**
     * Splits an inline run on blank lines into paragraphs; single newlines become <br>.
     * A line made of a lone YouTube link becomes an embedded player.
     */
    private function paragraphs(string $inline, bool $top = true): string
    {
        if ($top && !$this->options['paragraphs']) {
            return trim($inline);
        }
        $inline = trim(preg_replace('/[ \t\x{00A0}]*\n[ \t\x{00A0}]*/u', "\n", $inline) ?? $inline, " \t\n");
        if ('' === $inline) {
            return '';
        }
        $html = '';
        foreach (preg_split('/\n{2,}/', $inline) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ('' === $paragraph) {
                continue;
            }
            $buffer = [];
            foreach (explode("\n", $paragraph) as $line) {
                $id = $this->embeddableYoutubeLine($line);
                if (null === $id) {
                    $buffer[] = $line;
                    continue;
                }
                $html .= $this->flushLines($buffer).self::youtubeEmbed($id);
                $buffer = [];
            }
            $html .= $this->flushLines($buffer);
        }

        return $html;
    }

    /** @param list<string> $lines */
    private function flushLines(array $lines): string
    {
        $lines = array_filter(array_map('trim', $lines), static fn (string $l): bool => '' !== $l);

        return $lines ? '<p>'.implode('<br>', $lines).'</p>' : '';
    }

    /** Inline-only variant for list items: keeps single line breaks. */
    private function paragraphsInline(string $inline): string
    {
        return str_replace("\n", '<br>', trim($inline));
    }

    private function embeddableYoutubeLine(string $line): ?string
    {
        if (!preg_match('~^<a href="([^"]+)">(.+?)</a>$~s', trim($line), $m)) {
            return null;
        }
        $href = html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5);
        $label = html_entity_decode(strip_tags($m[2]), \ENT_QUOTES | \ENT_HTML5);
        if (rtrim($label, '/') !== rtrim($href, '/') && 1 !== preg_match('~^(https?://)?(www\.)?(youtube\.com|youtu\.be)~i', $label)) {
            return null; // custom link text: keep it as a link
        }

        return $this->youtubeId($href);
    }

    // ---------------------------------------------------------------- helpers

    public static function youtubeEmbed(string $id): string
    {
        return '<div class="youtube-embed"><iframe src="https://www.youtube-nocookie.com/embed/'.$id
            .'" width="560" height="315" frameborder="0" allowfullscreen></iframe></div>';
    }

    public function youtubeId(string $url): ?string
    {
        if (preg_match('~^(?:https?://)?(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:[^#]*&)?v=|embed/|v/)|youtu\.be/|youtube-nocookie\.com/embed/)([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~i', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Only http(s), mailto and ftp links are kept; "www.x" gets a scheme; anything else is dropped. */
    public static function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ('' === $url || preg_match('/[\s<>"]/', $url)) {
            return null;
        }
        if (preg_match('~^www\.~i', $url)) {
            return 'http://'.$url;
        }
        if (preg_match('~^(https?|ftp|mailto):~i', $url)) {
            return $url;
        }

        return null;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_NOQUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttr(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
