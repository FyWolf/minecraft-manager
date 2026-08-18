<?php

namespace FyWolf\MinecraftManager\Integrations\Versions;

use FyWolf\MinecraftManager\Integrations\ApiClient;
use FyWolf\MinecraftManager\Support\ForgeVersions;

/**
 * Minecraft Forge's published build list.
 *
 * Two documents, and both are needed:
 *
 *  - `maven-metadata.xml` is the only place every build exists — 5041 of them
 *    across 77 Minecraft versions, in the `1.15.2-31.2.4` form the egg wants.
 *  - `promotions_slim.json` says which build is *recommended* and which is
 *    *latest* per Minecraft version. That is two entries per version, so it
 *    cannot replace the index — but without it a list of 149 builds for 1.15.2
 *    gives no clue which one a person should pick.
 *
 * The parsing lives in {@see ForgeVersions} so it can be tested on a fixture
 * without the framework; this class is the network and the cache around it.
 */
class ForgeProvider extends ApiClient implements LoaderVersionProvider
{
    public function key(): string
    {
        return 'forge';
    }

    public function label(): string
    {
        return 'Forge';
    }

    protected function baseUrl(): string
    {
        // Both documents are requested as absolute URLs; the base is only used
        // for the User-Agent plumbing in ApiClient.
        return 'https://maven.minecraftforge.net';
    }

    private function metadataUrl(): string
    {
        return (string) config(
            'minecraft-manager.versions.forge_metadata',
            'https://maven.minecraftforge.net/net/minecraftforge/forge/maven-metadata.xml',
        );
    }

    private function promotionsUrl(): string
    {
        return (string) config(
            'minecraft-manager.versions.forge_promotions',
            'https://files.minecraftforge.net/net/minecraftforge/forge/promotions_slim.json',
        );
    }

    /**
     * @return array<string, array<int, string>> minecraft version => builds, newest first
     */
    private function index(): array
    {
        return $this->remember(
            'mcm:forge:index',
            // 6h, matching Vanilla: Forge publishes a handful of builds a week
            // and the document is 200 KB, so re-fetching it per render would be
            // the most expensive thing on the page.
            (int) config('minecraft-manager.cache.forge_index', 21600),
            function (): ?array {
                $xml = $this->getText($this->metadataUrl());

                if ($xml === null) {
                    return null;
                }

                $parsed = ForgeVersions::parseMavenMetadata($xml);

                // A document that parsed to nothing is a document whose shape
                // changed. Returning null keeps it out of the cache so the next
                // render retries, rather than pinning an empty list for six
                // hours and reading as "Forge has no versions".
                return $parsed ?: null;
            },
        ) ?? [];
    }

    /**
     * @return array<string, array{recommended: ?string, latest: ?string}>
     */
    private function promotions(): array
    {
        return $this->remember(
            'mcm:forge:promotions',
            (int) config('minecraft-manager.cache.versions', 1800),
            function (): ?array {
                $payload = $this->getJson($this->promotionsUrl());

                if (! is_array($payload)) {
                    return null;
                }

                return ForgeVersions::parsePromotions($payload) ?: null;
            },
        ) ?? [];
    }

    public function gameVersions(): array
    {
        return array_keys($this->index());
    }

    public function buildsFor(string $gameVersion, bool $fullArtifact = true): array
    {
        $builds = $this->index()[$gameVersion] ?? [];

        if ($builds === []) {
            return [];
        }

        $promotions = $this->promotions()[$gameVersion] ?? null;

        return array_map(
            fn (string $build): array => [
                'value' => $fullArtifact ? ForgeVersions::artifact($gameVersion, $build) : $build,
                'label' => ForgeVersions::label($gameVersion, $build, $promotions),
            ],
            $builds,
        );
    }

    /**
     * The build a person should pick if they have no reason to pick another.
     *
     * Recommended before latest, because that is what Forge itself promotes and
     * what a mod pack is most likely built against. Falls back to the newest
     * build when a Minecraft version has no promotion at all, which is normal
     * for a release Forge is still stabilising.
     */
    public function defaultBuildFor(string $gameVersion, bool $fullArtifact = true): ?string
    {
        $promotions = $this->promotions()[$gameVersion] ?? null;
        $builds = $this->index()[$gameVersion] ?? [];

        if ($builds === []) {
            return null;
        }

        foreach ([$promotions['recommended'] ?? null, $promotions['latest'] ?? null] as $candidate) {
            if (is_string($candidate) && in_array($candidate, $builds, true)) {
                return $fullArtifact ? ForgeVersions::artifact($gameVersion, $candidate) : $candidate;
            }
        }

        return $fullArtifact ? ForgeVersions::artifact($gameVersion, $builds[0]) : $builds[0];
    }

    public function isAvailable(): bool
    {
        return $this->index() !== [];
    }
}
