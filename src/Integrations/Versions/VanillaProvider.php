<?php

namespace FyWolf\MinecraftManager\Integrations\Versions;

use FyWolf\MinecraftManager\Integrations\ApiClient;

/**
 * Mojang's version manifest.
 *
 * Two hops: the manifest lists every version and a URL to that version's own
 * JSON, which is where the server jar lives. Versions before 1.2.5 have no
 * server download at all and are filtered out rather than offered and then
 * failing.
 */
class VanillaProvider extends ApiClient implements VersionProvider
{
    public function key(): string
    {
        return 'vanilla';
    }

    public function label(): string
    {
        return 'Vanilla';
    }

    protected function baseUrl(): string
    {
        // The manifest is an absolute URL; requests below pass full URLs, so the
        // base is only used for the User-Agent plumbing.
        return 'https://launchermeta.mojang.com';
    }

    private function manifestUrl(): string
    {
        return (string) config(
            'minecraft-manager.versions.vanilla_manifest',
            'https://launchermeta.mojang.com/mc/game/version_manifest_v2.json',
        );
    }

    /**
     * @return array<string, string> version => metadata url
     */
    private function manifest(): array
    {
        return $this->remember(
            'mcm:vanilla:manifest',
            21600, // 6h — Mojang publishes rarely.
            function (): ?array {
                $payload = $this->getJson($this->manifestUrl());

                if (! is_array($payload)) {
                    return null;
                }

                $map = [];

                foreach ((array) ($payload['versions'] ?? []) as $version) {
                    if (! is_array($version) || ($version['type'] ?? null) !== 'release') {
                        continue;
                    }

                    if (isset($version['id'], $version['url'])) {
                        $map[(string) $version['id']] = (string) $version['url'];
                    }
                }

                return $map ?: null;
            },
        ) ?? [];
    }

    public function gameVersions(): array
    {
        // The manifest is already newest-first for releases.
        return array_keys($this->manifest());
    }

    public function builds(string $gameVersion): array
    {
        // Vanilla has exactly one server jar per version.
        return [['id' => 'release', 'label' => 'Official server jar']];
    }

    public function downloadUrl(string $gameVersion, string $buildId): ?string
    {
        $url = $this->manifest()[$gameVersion] ?? null;

        if (! $url) {
            return null;
        }

        $payload = $this->remember(
            'mcm:vanilla:version:' . md5($gameVersion),
            (int) config('minecraft-manager.cache.immutable', 86400),
            fn () => $this->getJson($url),
        );

        // Absent for versions older than 1.2.5, which shipped no server.
        return $payload['downloads']['server']['url'] ?? null;
    }
}
