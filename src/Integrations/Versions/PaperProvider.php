<?php

namespace FyWolf\MinecraftManager\Integrations\Versions;

use FyWolf\MinecraftManager\Integrations\ApiClient;

/**
 * PaperMC's project API, which also serves Folia, Velocity and Waterfall.
 *
 * The base URL is config-driven because PaperMC has announced a v3 ("fill")
 * API; if v2 is retired, only that config value needs to change.
 */
class PaperProvider extends ApiClient implements VersionProvider
{
    public function __construct(private string $project = 'paper') {}

    public function key(): string
    {
        return $this->project;
    }

    public function label(): string
    {
        return ucfirst($this->project);
    }

    protected function baseUrl(): string
    {
        return (string) config('minecraft-manager.versions.paper_url', 'https://api.papermc.io/v2');
    }

    public function gameVersions(): array
    {
        $payload = $this->remember(
            "mcm:paper:$this->project:versions",
            (int) config('minecraft-manager.cache.versions', 1800),
            fn () => $this->getJson("/projects/$this->project"),
        );

        $versions = array_values((array) ($payload['versions'] ?? []));

        // The API lists oldest first.
        return array_reverse($versions);
    }

    public function builds(string $gameVersion): array
    {
        $payload = $this->remember(
            "mcm:paper:$this->project:builds:$gameVersion",
            (int) config('minecraft-manager.cache.versions', 1800),
            fn () => $this->getJson("/projects/$this->project/versions/$gameVersion/builds"),
        );

        $builds = [];

        foreach (array_values((array) ($payload['builds'] ?? [])) as $build) {
            if (! is_array($build) || ! isset($build['build'])) {
                continue;
            }

            $channel = (string) ($build['channel'] ?? 'default');

            $builds[] = [
                'id' => (string) $build['build'],
                'label' => '#' . $build['build']
                    . ($channel !== 'default' ? " ($channel)" : '')
                    . (isset($build['time']) ? ' — ' . substr((string) $build['time'], 0, 10) : ''),
            ];
        }

        return array_reverse($builds);
    }

    public function downloadUrl(string $gameVersion, string $buildId): ?string
    {
        $payload = $this->remember(
            "mcm:paper:$this->project:build:$gameVersion:$buildId",
            (int) config('minecraft-manager.cache.immutable', 86400),
            fn () => $this->getJson("/projects/$this->project/versions/$gameVersion/builds/$buildId"),
        );

        $name = $payload['downloads']['application']['name'] ?? null;

        if (! $name) {
            return null;
        }

        return rtrim($this->baseUrl(), '/')
            . "/projects/$this->project/versions/$gameVersion/builds/$buildId/downloads/$name";
    }
}
