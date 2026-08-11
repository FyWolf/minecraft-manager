<?php

namespace FyWolf\MinecraftManager\Integrations\Versions;

use FyWolf\MinecraftManager\Integrations\ApiClient;

/**
 * Fabric.
 *
 * Fabric is the pleasant case: its meta service builds a ready-to-run server
 * launcher jar on demand at a predictable URL, so there is no installer to
 * execute and no build metadata to chase. A "build" here is a loader version.
 */
class FabricProvider extends ApiClient implements VersionProvider
{
    public function key(): string
    {
        return 'fabric';
    }

    public function label(): string
    {
        return 'Fabric';
    }

    protected function baseUrl(): string
    {
        return (string) config('minecraft-manager.versions.fabric_url', 'https://meta.fabricmc.net/v2');
    }

    public function gameVersions(): array
    {
        $payload = $this->remember(
            'mcm:fabric:game',
            (int) config('minecraft-manager.cache.versions', 1800),
            fn () => $this->getJson('/versions/game'),
        );

        $versions = [];

        foreach ((array) $payload as $entry) {
            // Snapshots outnumber releases enormously and are not what someone
            // switching a production server is looking for.
            if (is_array($entry) && ! empty($entry['stable']) && isset($entry['version'])) {
                $versions[] = (string) $entry['version'];
            }
        }

        return $versions;
    }

    public function builds(string $gameVersion): array
    {
        $payload = $this->remember(
            'mcm:fabric:loader',
            (int) config('minecraft-manager.cache.versions', 1800),
            fn () => $this->getJson('/versions/loader'),
        );

        $builds = [];

        foreach ((array) $payload as $entry) {
            if (! is_array($entry) || ! isset($entry['version'])) {
                continue;
            }

            $builds[] = [
                'id' => (string) $entry['version'],
                'label' => 'loader ' . $entry['version'] . (empty($entry['stable']) ? ' (unstable)' : ''),
            ];
        }

        return $builds;
    }

    public function downloadUrl(string $gameVersion, string $buildId): ?string
    {
        $installer = $this->latestInstaller();

        if (! $installer) {
            return null;
        }

        return rtrim($this->baseUrl(), '/') . "/versions/loader/$gameVersion/$buildId/$installer/server/jar";
    }

    private function latestInstaller(): ?string
    {
        $payload = $this->remember(
            'mcm:fabric:installer',
            (int) config('minecraft-manager.cache.versions', 1800),
            fn () => $this->getJson('/versions/installer'),
        );

        foreach ((array) $payload as $entry) {
            if (is_array($entry) && isset($entry['version'])) {
                return (string) $entry['version'];
            }
        }

        return null;
    }
}
