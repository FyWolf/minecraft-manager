<?php

namespace FyWolf\MinecraftManager\Integrations\Content;

use FyWolf\MinecraftManager\Enums\ContentType;
use FyWolf\MinecraftManager\Integrations\ApiClient;
use FyWolf\MinecraftManager\Integrations\Content\Data\ContentDependency;
use FyWolf\MinecraftManager\Integrations\Content\Data\ContentFile;
use FyWolf\MinecraftManager\Integrations\Content\Data\ContentProject;
use FyWolf\MinecraftManager\Integrations\Content\Data\ContentVersion;
use FyWolf\MinecraftManager\Integrations\Content\Data\SearchQuery;
use FyWolf\MinecraftManager\Integrations\Content\Data\SearchResult;

/**
 * Modrinth (api.modrinth.com/v2).
 *
 * No credentials required.
 */
class ModrinthProvider extends ApiClient implements ContentProvider
{
    public function key(): string
    {
        return 'modrinth';
    }

    public function label(): string
    {
        return 'Modrinth';
    }

    protected function baseUrl(): string
    {
        return (string) config('minecraft-manager.modrinth.base_url', 'https://api.modrinth.com/v2');
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function supports(ContentType $type): bool
    {
        return true;
    }

    public function search(SearchQuery $query): SearchResult
    {
        $facets = $this->facets($query);

        // A loader-specific search with no loader resolved would return the
        // whole of Modrinth, which is worse than saying we cannot filter.
        if ($facets === null) {
            return SearchResult::empty(true, 'Could not work out this server\'s mod loader.');
        }

        $params = [
            'offset' => ($query->page - 1) * $query->perPage,
            'limit' => $query->perPage,
            'facets' => json_encode($facets, JSON_THROW_ON_ERROR),
            'index' => filled($query->search) ? 'relevance' : 'downloads',
        ];

        if (filled($query->search)) {
            $params['query'] = $query->search;
        }

        $payload = $this->remember(
            $query->cacheKey($this->key()),
            (int) config('minecraft-manager.cache.search', 900),
            fn () => $this->getJson('/search', $params),
        );

        if ($payload === null) {
            return SearchResult::empty(true, $this->downReason() === 'rate-limited'
                ? 'Modrinth is rate-limiting us. Try again shortly.'
                : 'Modrinth is unreachable.');
        }

        $items = array_map(
            fn (array $hit) => new ContentProject(
                providerKey: $this->key(),
                id: (string) ($hit['project_id'] ?? $hit['slug'] ?? ''),
                title: (string) ($hit['title'] ?? 'Untitled'),
                slug: $hit['slug'] ?? null,
                summary: $hit['description'] ?? null,
                author: $hit['author'] ?? null,
                iconUrl: $hit['icon_url'] ?: null,
                downloads: isset($hit['downloads']) ? (int) $hit['downloads'] : null,
                updatedAt: $hit['date_modified'] ?? null,
                url: isset($hit['slug']) ? $this->projectUrl($hit['slug'], $query->type) : null,
                type: ContentType::tryFrom((string) ($hit['project_type'] ?? '')) ?? $query->type,
            ),
            array_values((array) ($payload['hits'] ?? [])),
        );

        return new SearchResult(
            items: $items,
            total: (int) ($payload['total_hits'] ?? count($items)),
            page: $query->page,
            perPage: $query->perPage,
        );
    }

    /**
     * Build Modrinth's facet structure.
     *
     * Facets are an AND of ORs: the outer array is ANDed, each inner array is
     * ORed. The whole loader family therefore belongs in ONE inner array —
     * `[["categories:paper","categories:spigot","categories:bukkit"]]` means
     * "paper OR spigot OR bukkit", whereas three separate inner arrays would
     * demand a plugin be tagged with all three and match almost nothing.
     *
     * Sending only the primary loader value (which minecraft-modrinth does)
     * silently drops every Spigot-only plugin — a large share of the Bukkit
     * ecosystem.
     *
     * @return array<int, array<int, string>>|null
     */
    private function facets(SearchQuery $query): ?array
    {
        $facets = [['project_type:' . $query->type->modrinthProjectType()]];

        // Modpacks and resource packs are not loader-specific.
        if (in_array($query->type, [ContentType::Mod, ContentType::Plugin], true)) {
            if (! $query->loader) {
                return null;
            }

            $categories = $query->loader->modrinthCategories();

            if ($categories === []) {
                // Vanilla has no loader, so there are no mods to find. The page
                // should not have rendered at all; treat it as unfilterable.
                return null;
            }

            $facets[] = array_map(fn (string $category) => "categories:$category", $categories);
        }

        if (filled($query->gameVersion)) {
            $facets[] = ['versions:' . $query->gameVersion];
        }

        return $facets;
    }

    public function versions(string $projectId, SearchQuery $context, int $limit = 20): array
    {
        $params = [];

        if (filled($context->gameVersion)) {
            $params['game_versions'] = json_encode([$context->gameVersion], JSON_THROW_ON_ERROR);
        }

        if ($context->loader && in_array($context->type, [ContentType::Mod, ContentType::Plugin], true)) {
            $loaders = $context->loader->modrinthCategories();

            if ($loaders !== []) {
                $params['loaders'] = json_encode($loaders, JSON_THROW_ON_ERROR);
            }
        }

        $key = 'mcm:modrinth:versions:' . md5($projectId . json_encode($params));

        $payload = $this->remember(
            $key,
            (int) config('minecraft-manager.cache.versions', 1800),
            fn () => $this->getJson("/project/$projectId/version", $params),
        );

        if (! is_array($payload)) {
            return [];
        }

        $versions = array_map(fn (array $raw) => $this->mapVersion($raw), array_values($payload));

        return array_slice($versions, 0, $limit);
    }

    public function version(string $projectId, string $versionId): ?ContentVersion
    {
        $payload = $this->remember(
            'mcm:modrinth:version:' . $versionId,
            (int) config('minecraft-manager.cache.immutable', 86400),
            fn () => $this->getJson("/version/$versionId"),
        );

        return is_array($payload) ? $this->mapVersion($payload) : null;
    }

    public function latestVersionFor(string $projectId, SearchQuery $context): ?ContentVersion
    {
        return $this->versions($projectId, $context, 1)[0] ?? null;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function mapVersion(array $raw): ContentVersion
    {
        $files = array_map(
            fn (array $file) => new ContentFile(
                url: $file['url'] ?? null,
                filename: (string) ($file['filename'] ?? 'unknown.jar'),
                size: isset($file['size']) ? (int) $file['size'] : null,
                hashes: (array) ($file['hashes'] ?? []),
                primary: (bool) ($file['primary'] ?? false),
                // Modrinth has no distribution opt-out; everything listed is
                // fetchable.
                distributionAllowed: true,
            ),
            array_values((array) ($raw['files'] ?? [])),
        );

        // Modrinth does not guarantee a file is flagged primary; if none is,
        // treat the first as primary so the install action is never disabled by
        // a metadata quirk.
        if ($files !== [] && ! array_filter($files, fn (ContentFile $f) => $f->primary)) {
            $first = $files[0];
            $files[0] = new ContentFile(
                url: $first->url,
                filename: $first->filename,
                size: $first->size,
                hashes: $first->hashes,
                primary: true,
                distributionAllowed: $first->distributionAllowed,
                browserUrl: $first->browserUrl,
            );
        }

        return new ContentVersion(
            providerKey: $this->key(),
            id: (string) ($raw['id'] ?? ''),
            projectId: (string) ($raw['project_id'] ?? ''),
            name: (string) ($raw['name'] ?? $raw['version_number'] ?? 'version'),
            versionNumber: $raw['version_number'] ?? null,
            gameVersions: array_values((array) ($raw['game_versions'] ?? [])),
            loaders: array_values((array) ($raw['loaders'] ?? [])),
            channel: (string) ($raw['version_type'] ?? 'release'),
            changelog: $raw['changelog'] ?? null,
            publishedAt: $raw['date_published'] ?? null,
            downloads: isset($raw['downloads']) ? (int) $raw['downloads'] : null,
            files: $files,
            dependencies: array_map(
                fn (array $dependency) => new ContentDependency(
                    projectId: $dependency['project_id'] ?? null,
                    versionId: $dependency['version_id'] ?? null,
                    type: (string) ($dependency['dependency_type'] ?? 'optional'),
                ),
                array_values((array) ($raw['dependencies'] ?? [])),
            ),
            featured: (bool) ($raw['featured'] ?? false),
        );
    }

    public function projectUrl(string $idOrSlug, ContentType $type): string
    {
        $segment = match ($type) {
            ContentType::Plugin => 'plugin',
            ContentType::Modpack => 'modpack',
            ContentType::ResourcePack => 'resourcepack',
            ContentType::Datapack => 'datapack',
            default => 'mod',
        };

        return "https://modrinth.com/$segment/$idOrSlug";
    }
}
