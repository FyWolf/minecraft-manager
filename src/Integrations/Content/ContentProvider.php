<?php

namespace FyWolf\MinecraftManager\Integrations\Content;

use FyWolf\MinecraftManager\Enums\ContentType;
use FyWolf\MinecraftManager\Integrations\Content\Data\ContentVersion;
use FyWolf\MinecraftManager\Integrations\Content\Data\SearchQuery;
use FyWolf\MinecraftManager\Integrations\Content\Data\SearchResult;

/**
 * A source of installable Minecraft content.
 *
 * Every asymmetry between providers lives behind this interface, never at a
 * call site. Modrinth wants AND-of-OR facet arrays; CurseForge wants numeric
 * class ids and refuses to return anything if you send it a loader type for a
 * Bukkit plugin search. A page should be able to ask for "plugins for Paper on
 * 1.21.4" and not know any of that.
 */
interface ContentProvider
{
    public function key(): string;

    public function label(): string;

    /**
     * Whether this provider can be used at all right now.
     *
     * CurseForge returns false without an API key, which is the entire
     * auto-hide mechanism: the registry filters it out, so there is no tab, no
     * empty page and no error message to explain.
     */
    public function isAvailable(): bool;

    public function supports(ContentType $type): bool;

    public function search(SearchQuery $query): SearchResult;

    /**
     * Versions of a project, newest first, already filtered to the server's
     * game version and loader.
     *
     * @return array<int, ContentVersion>
     */
    public function versions(string $projectId, SearchQuery $context, int $limit = 20): array;

    public function version(string $projectId, string $versionId): ?ContentVersion;

    /**
     * The best version of a project for the given context, used to resolve a
     * dependency without making the user pick.
     */
    public function latestVersionFor(string $projectId, SearchQuery $context): ?ContentVersion;

    public function projectUrl(string $idOrSlug, ContentType $type): string;
}
