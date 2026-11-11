<?php

declare(strict_types=1);

namespace Yesly;

/**
 * One page of a list. Iterating it yields every item from this page on,
 * fetching later pages as needed; `$data` holds this page only.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class Page implements \IteratorAggregate
{
    public readonly string $url;
    public readonly bool $hasMore;
    /** @var list<array<string, mixed>> */
    public readonly array $data;

    /**
     * @param array<string, mixed> $raw
     * @param \Closure(string): Page $fetchAfter
     */
    public function __construct(array $raw, private readonly \Closure $fetchAfter)
    {
        $this->url = \is_string($raw['url'] ?? null) ? $raw['url'] : '';
        $this->hasMore = (bool) ($raw['has_more'] ?? false);
        /** @var list<array<string, mixed>> $data */
        $data = \is_array($raw['data'] ?? null) ? array_values($raw['data']) : [];
        $this->data = $data;
    }

    public function hasNextPage(): bool
    {
        return $this->hasMore && $this->data !== [];
    }

    /** The page after this one. Check `hasNextPage()` first. */
    public function nextPage(): self
    {
        if (!$this->hasNextPage()) {
            throw new \LogicException('There is no next page.');
        }
        // Lists that come whole, in one page (a conversation's turns), have items without an id.
        $id = $this->data[\count($this->data) - 1]['id'] ?? null;
        if (!\is_string($id)) {
            throw new \LogicException('There is no next page.');
        }

        return ($this->fetchAfter)($id);
    }

    public function getIterator(): \Generator
    {
        $page = $this;
        while (true) {
            foreach ($page->data as $item) {
                yield $item;
            }
            if (!$page->hasNextPage()) {
                return;
            }
            $page = $page->nextPage();
        }
    }
}
