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
 * CurseForge Core API (api.curseforge.com/v1).
 *
 * Requires an API key an administrator requests from console.curseforge.com.
 * Without one `isAvailable()` is false, the registry drops this provider, and
 * no CurseForge UI is rendered at all — which is the whole auto-hide mechanism.
 *
 * Two quirks that are not optional to handle:
 *
 *  - `downloadUrl` is null for projects whose authors opted out of third-party
 *    distribution. No client can install those; we detect it and link out.
 *  - `modLoaderType` is meaningful only for the Forge family. Sending it on a
 *    Bukkit plugin search returns zero rows rather than an error, which is
 *    indistinguishable from "nothing matched".
 */
class CurseForgeProvider extends ApiClient implements ContentProvider
{
    public function key(): string
    {
        return 'curseforge';
    }

    public function label(): string
    {
        return 'CurseForge';
    }

    protected function baseUrl(): string
    {
        return (string) config('minecraft-manager.curseforge.base_url', 'https://api.curseforge.com/v1');
    }

    protected function headers(): array
    {
        return ['x-api-key' => (string) config('minecraft-manager.curseforge.api_key', '')];
    }

    public function isAvailable(): bool
    {
        return filled(config('minecraft-manager.curseforge.api_key'));
    }

    public function supports(ContentType $type): bool
    {
        return $this->classId($type) !== null;
    }

    private function gameId(): int
    {
        return (int) config('minecraft-manager.curseforge.game_id', 432);
    }

    /**
     * Class ids are not documented as stable constants, so prefer the ones the
     * API reports and fall back to config.
     */
    private function classId(ContentType $type): ?int
    {
        $discovered = $this->discoverClassIds();

        $slug = match ($type) {
            ContentType::Mod => 'mc-mods',
            ContentType::Plugin => 'bukkit-plugins',
            ContentType::Modpack => 'modpacks',
            ContentType::ResourcePack => 'texture-packs',
            ContentType::Datapack => null,
        };

        if ($slug !== null && isset($discovered[$slug])) {
            return $discovered[$slug];
        }

        return $type->curseForgeClassId();
    }

    /**
     * @return array<string, int> slug => id
     */
    private function discoverClassIds(): array
    {
        return $this->remember(
            'mcm:curseforge:classes:' . $this->gameId(),
            (int) config('minecraft-manager.cache.immutable', 86400),
            function (): ?array {
                $payload = $this->getJson('/categories', [
                    'gameId' => $this->gameId(),
                    'classesOnly' => 'true',
                ]);

                if (! is_array($payload)) {
                    return null;
                }

                $map = [];

                foreach ((array) ($payload['data'] ?? []) as $class) {
                    if (isset($class['slug'], $class['id'])) {
                        $map[(string) $class['slug']] = (int) $class['id'];
                    }
                }

                return $map ?: null;
            },
        ) ?? [];
    }

    public function search(SearchQuery $query): SearchResult
    {
        if (! $this->isAvailable()) {
            return SearchResult::empty(true, 'No CurseForge API key is configured.');
        }

        $classId = $this->classId($query->type);

        if ($classId === null) {
            return SearchResult::empty(false);
        }

        $params = [
            'gameId' => $this->gameId(),
            'classId' => $classId,
            'index' => ($query->page - 1) * $query->perPage,
            // CurseForge caps a page at 50 and index+pageSize at 10,000.
            'pageSize' => min($query->perPage, 50),
            'sortField' => filled($query->search) ? 1 : 2, // Featured : Popularity
            'sortOrder' => 'desc',
        ];

        if (($params['index'] + $params['pageSize']) > 10000) {
            return SearchResult::empty(true, 'CurseForge will not paginate beyond 10,000 results. Narrow the search.');
        }

        if (filled($query->search)) {
            $params['searchFilter'] = $query->search;
        }

        if (filled($query->gameVersion)) {
            $params['gameVersion'] = $query->gameVersion;
        }

        // Only for the Forge family — see the class docblock.
        if ($query->loader && ($loaderType = $query->loader->curseForgeLoaderType()) !== null) {
            $params['modLoaderType'] = $loaderType;
        }

        $payload = $this->remember(
            $query->cacheKey($this->key()),
            (int) config('minecraft-manager.cache.search', 900),
            fn () => $this->getJson('/mods/search', $params),
        );

        if ($payload === null) {
            return SearchResult::empty(true, match ($this->downReason()) {
                'rate-limited' => 'CurseForge is rate-limiting us. Try again shortly.',
                null => 'CurseForge rejected the request — check the API key in the plugin settings.',
                default => 'CurseForge is unreachable.',
            });
        }

        $items = array_map(fn (array $mod) => $this->mapProject($mod, $query->type), array_values((array) ($payload['data'] ?? [])));

        return new SearchResult(
            items: $items,
            total: (int) ($payload['pagination']['totalCount'] ?? count($items)),
            page: $query->page,
            perPage: $query->perPage,
        );
    }

    /**
     * @param array<string, mixed> $mod
     */
    private function mapProject(array $mod, ContentType $type): ContentProject
    {
        return new ContentProject(
            providerKey: $this->key(),
            id: (string) ($mod['id'] ?? ''),
            title: (string) ($mod['name'] ?? 'Untitled'),
            slug: $mod['slug'] ?? null,
            summary: $mod['summary'] ?? null,
            author: $mod['authors'][0]['name'] ?? null,
            iconUrl: $mod['logo']['thumbnailUrl'] ?? null,
            downloads: isset($mod['downloadCount']) ? (int) $mod['downloadCount'] : null,
            updatedAt: $mod['dateModified'] ?? null,
            url: $mod['links']['websiteUrl'] ?? null,
            type: $type,
            // The project-level opt-out. Defaults true when absent.
            distributionAllowed: (bool) ($mod['allowModDistribution'] ?? true),
        );
    }

    public function versions(string $projectId, SearchQuery $context, int $limit = 20): array
    {
        $params = ['pageSize' => min($limit, 50)];

        if (filled($context->gameVersion)) {
            $params['gameVersion'] = $context->gameVersion;
        }

        if ($context->loader && ($loaderType = $context->loader->curseForgeLoaderType()) !== null) {
            $params['modLoaderType'] = $loaderType;
        }

        $key = 'mcm:curseforge:files:' . md5($projectId . json_encode($params));

        $payload = $this->remember(
            $key,
            (int) config('minecraft-manager.cache.versions', 1800),
            fn () => $this->getJson("/mods/$projectId/files", $params),
        );

        if (! is_array($payload)) {
            return [];
        }

        $versions = array_map(
            fn (array $file) => $this->mapVersion($file, $projectId),
            array_values((array) ($payload['data'] ?? [])),
        );

        return array_slice($versions, 0, $limit);
    }

    public function version(string $projectId, string $versionId): ?ContentVersion
    {
        $payload = $this->remember(
            "mcm:curseforge:file:$projectId:$versionId",
            (int) config('minecraft-manager.cache.immutable', 86400),
            fn () => $this->getJson("/mods/$projectId/files/$versionId"),
        );

        $data = $payload['data'] ?? null;

        return is_array($data) ? $this->mapVersion($data, $projectId) : null;
    }

    public function latestVersionFor(string $projectId, SearchQuery $context): ?ContentVersion
    {
        return $this->versions($projectId, $context, 1)[0] ?? null;
    }

    /**
     * Resolve many {projectId, fileId} pairs in one call.
     *
     * A modpack manifest lists hundreds of these; resolving them individually
     * would be hundreds of sequential round-trips against a per-key quota.
     *
     * @param array<int, int> $fileIds
     *
     * @return array<int, ContentVersion> keyed by file id
     */
    public function resolveFiles(array $fileIds): array
    {
        if ($fileIds === []) {
            return [];
        }

        $resolved = [];

        // The endpoint accepts a bounded list; chunk conservatively.
        foreach (array_chunk(array_values(array_unique($fileIds)), 50) as $chunk) {
            $payload = $this->postJson('/mods/files', ['fileIds' => array_map('intval', $chunk)]);

            foreach ((array) ($payload['data'] ?? []) as $file) {
                if (! is_array($file) || ! isset($file['id'])) {
                    continue;
                }

                $resolved[(int) $file['id']] = $this->mapVersion($file, (string) ($file['modId'] ?? ''));
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function mapVersion(array $file, string $projectId): ContentVersion
    {
        $url = $file['downloadUrl'] ?? null;

        // Null downloadUrl is the author's third-party-distribution opt-out.
        // It is a normal, expected state — not an error — and the only correct
        // response is to say so and link the user to the project page.
        $allowed = filled($url);

        $gameVersions = [];
        $loaders = [];

        foreach ((array) ($file['gameVersions'] ?? []) as $value) {
            $value = (string) $value;

            // CurseForge mixes Minecraft versions and loader names into one
            // list; a leading digit is the only reliable separator.
            if (preg_match('/^\d/', $value)) {
                $gameVersions[] = $value;
            } else {
                $loaders[] = strtolower($value);
            }
        }

        return new ContentVersion(
            providerKey: $this->key(),
            id: (string) ($file['id'] ?? ''),
            projectId: $projectId,
            name: (string) ($file['displayName'] ?? $file['fileName'] ?? 'file'),
            versionNumber: $file['fileName'] ?? null,
            gameVersions: $gameVersions,
            loaders: $loaders,
            channel: match ((int) ($file['releaseType'] ?? 1)) {
                2 => 'beta',
                3 => 'alpha',
                default => 'release',
            },
            changelog: null, // A separate endpoint; not worth a call per row.
            publishedAt: $file['fileDate'] ?? null,
            downloads: isset($file['downloadCount']) ? (int) $file['downloadCount'] : null,
            files: [new ContentFile(
                url: $url,
                filename: (string) ($file['fileName'] ?? 'unknown.jar'),
                size: isset($file['fileLength']) ? (int) $file['fileLength'] : null,
                hashes: collect((array) ($file['hashes'] ?? []))
                    ->mapWithKeys(fn ($hash) => [(string) ($hash['algo'] ?? '?') => (string) ($hash['value'] ?? '')])
                    ->all(),
                primary: true,
                distributionAllowed: $allowed,
                browserUrl: $projectId ? "https://www.curseforge.com/minecraft/mc-mods/$projectId" : null,
            )],
            dependencies: array_values(array_filter(array_map(
                fn (array $dependency) => isset($dependency['modId'])
                    ? new ContentDependency(
                        projectId: (string) $dependency['modId'],
                        versionId: null,
                        // relationType 3 = RequiredDependency
                        type: ((int) ($dependency['relationType'] ?? 0)) === 3
                            ? ContentDependency::REQUIRED
                            : ContentDependency::OPTIONAL,
                    )
                    : null,
                array_values((array) ($file['dependencies'] ?? [])),
            ))),
        );
    }

    public function projectUrl(string $idOrSlug, ContentType $type): string
    {
        $segment = match ($type) {
            ContentType::Plugin => 'bukkit-plugins',
            ContentType::Modpack => 'modpacks',
            ContentType::ResourcePack => 'texture-packs',
            default => 'mc-mods',
        };

        return "https://www.curseforge.com/minecraft/$segment/$idOrSlug";
    }
}
