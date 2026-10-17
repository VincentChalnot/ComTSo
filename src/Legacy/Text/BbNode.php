<?php

namespace App\Legacy\Text;

/** Node of the BBCode tree built by {@see BbcodeRenderer}: either raw text or a tag with children. */
final class BbNode
{
    /** @var list<BbNode> */
    public array $children = [];

    public function __construct(
        public readonly string $name,
        public readonly ?string $param = null,
        public readonly ?string $text = null,
    ) {
    }

    public static function text(string $text): self
    {
        return new self('#text', null, $text);
    }

    public function isText(): bool
    {
        return '#text' === $this->name;
    }

    /** Raw (still entity-encoded) text of the whole subtree, without markup. */
    public function flatten(): string
    {
        if ($this->isText() || 'code' === $this->name) {
            return (string) $this->text;
        }

        return implode('', array_map(static fn (self $c): string => $c->flatten(), $this->children));
    }
}
