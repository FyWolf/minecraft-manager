<?php

namespace FyWolf\MinecraftManager\Integrations\Content\Data;

readonly class SearchResult
{
    /**
     * @param array<int, ContentProject> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page = 1,
        public int $perPage = 20,
        /**
         * True when this result is not what was asked for — the API was
         * unreachable, rate-limited, or a filter had to be relaxed.
         *
         * Exists because the existing plugin swallows its exception and returns
         * an empty hit list, so a provider outage renders as "no results
         * found". That is the single most misleading behaviour it has: users
         * conclude the mod does not exist. A degraded flag lets the table say
         * what actually happened.
         */
        public bool $degraded = false,
        public ?string $message = null,
    ) {}

    public static function empty(bool $degraded = false, ?string $message = null): self
    {
        return new self([], 0, 1, 20, $degraded, $message);
    }
}
