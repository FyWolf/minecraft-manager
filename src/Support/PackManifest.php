<?php

namespace FyWolf\MinecraftManager\Support;

use RuntimeException;

/**
 * A parsed modpack, from either format.
 *
 * Modrinth `.mrpack` is a zip holding `modrinth.index.json` (direct CDN URLs,
 * so it is self-contained) plus an `overrides/` tree. CurseForge packs hold a
 * `manifest.json` listing {projectID, fileID} pairs that have to be resolved
 * against the API before anything can be downloaded.
 */
class PackManifest
{
    /**
     * @param array<int, PackEntry> $entries
     * @param array<int, string>    $overrideDirs
     */
    public function __construct(
        public string $format,
        public string $name,
        public ?string $version = null,
        public ?string $mcVersion = null,
        public ?string $loader = null,
        public ?string $loaderVersion = null,
        public array $entries = [],
        public array $overrideDirs = [],
    ) {}

    /**
     * @return array<int, PackEntry>
     */
    public function installable(): array
    {
        return array_values(array_filter($this->entries, fn (PackEntry $e) => $e->isInstallable()));
    }

    /**
     * @return array<int, PackEntry>
     */
    public function blocked(): array
    {
        return array_values(array_filter($this->entries, fn (PackEntry $e) => ! $e->distributionAllowed));
    }

    public function estimatedBytes(): int
    {
        $total = 0;

        foreach ($this->entries as $entry) {
            $total += (int) $entry->size;
        }

        return $total;
    }

    /**
     * Parse whichever manifest is present in an extracted pack directory.
     */
    public static function fromDirectory(string $directory): self
    {
        if (is_file($directory . '/modrinth.index.json')) {
            return self::fromModrinth($directory);
        }

        if (is_file($directory . '/manifest.json')) {
            return self::fromCurseForge($directory);
        }

        throw new RuntimeException('This archive contains neither modrinth.index.json nor manifest.json, so it is not a modpack this plugin understands.');
    }

    private static function decode(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($file), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The pack manifest is not valid JSON.');
        }

        return $decoded;
    }

    private static function fromModrinth(string $directory): self
    {
        $data = self::decode($directory . '/modrinth.index.json');

        $dependencies = (array) ($data['dependencies'] ?? []);

        [$loader, $loaderVersion] = self::modrinthLoader($dependencies);

        $entries = [];

        foreach ((array) ($data['files'] ?? []) as $file) {
            if (! is_array($file) || ! isset($file['path'])) {
                continue;
            }

            $path = (string) $file['path'];

            // Every path here comes from an untrusted archive.
            if (! DaemonDirs::isSafeRelativePath($path)) {
                continue;
            }

            // env.server === 'unsupported' marks a client-only mod. Installing
            // those on a dedicated server is a reliable way to crash it at
            // startup, and the ecosystem gets this wrong constantly.
            if ((($file['env']['server'] ?? null)) === 'unsupported') {
                continue;
            }

            $entries[] = new PackEntry(
                path: $path,
                urls: array_values(array_filter((array) ($file['downloads'] ?? []))),
                size: isset($file['fileSize']) ? (int) $file['fileSize'] : null,
                hashes: (array) ($file['hashes'] ?? []),
                required: (($file['env']['server'] ?? 'required') !== 'optional'),
                // Modrinth has no distribution opt-out.
                distributionAllowed: true,
            );
        }

        return new self(
            format: 'modrinth',
            name: (string) ($data['name'] ?? 'Modpack'),
            version: isset($data['versionId']) ? (string) $data['versionId'] : null,
            mcVersion: isset($dependencies['minecraft']) ? (string) $dependencies['minecraft'] : null,
            loader: $loader,
            loaderVersion: $loaderVersion,
            entries: $entries,
            overrideDirs: self::presentDirs($directory, ['overrides', 'server-overrides']),
        );
    }

    /**
     * @param array<string, mixed> $dependencies
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function modrinthLoader(array $dependencies): array
    {
        foreach ([
            'fabric-loader' => 'fabric',
            'quilt-loader' => 'quilt',
            'neoforge' => 'neoforge',
            'forge' => 'forge',
        ] as $key => $loader) {
            if (isset($dependencies[$key])) {
                return [$loader, (string) $dependencies[$key]];
            }
        }

        return [null, null];
    }

    private static function fromCurseForge(string $directory): self
    {
        $data = self::decode($directory . '/manifest.json');

        $minecraft = (array) ($data['minecraft'] ?? []);

        $loader = null;
        $loaderVersion = null;

        foreach ((array) ($minecraft['modLoaders'] ?? []) as $modLoader) {
            if (! is_array($modLoader)) {
                continue;
            }

            // Only the primary loader matters; the id is "forge-47.2.0".
            if (! empty($modLoader['primary']) || $loader === null) {
                $id = (string) ($modLoader['id'] ?? '');

                if (str_contains($id, '-')) {
                    [$loader, $loaderVersion] = explode('-', $id, 2);
                }
            }
        }

        $entries = [];

        foreach ((array) ($data['files'] ?? []) as $file) {
            if (! is_array($file) || ! isset($file['projectID'], $file['fileID'])) {
                continue;
            }

            // Path and URL are unknown until the batch API resolve fills them in.
            $entries[] = new PackEntry(
                path: '',
                required: (bool) ($file['required'] ?? true),
                projectId: (int) $file['projectID'],
                fileId: (int) $file['fileID'],
            );
        }

        return new self(
            format: 'curseforge',
            name: (string) ($data['name'] ?? 'Modpack'),
            version: isset($data['version']) ? (string) $data['version'] : null,
            mcVersion: isset($minecraft['version']) ? (string) $minecraft['version'] : null,
            loader: $loader,
            loaderVersion: $loaderVersion,
            entries: $entries,
            overrideDirs: self::presentDirs($directory, array_values(array_filter([
                (string) ($data['overrides'] ?? 'overrides'),
                'server-overrides',
            ]))),
        );
    }

    /**
     * @param array<int, string> $candidates
     *
     * @return array<int, string>
     */
    private static function presentDirs(string $directory, array $candidates): array
    {
        return array_values(array_filter(
            array_unique($candidates),
            fn (string $dir) => $dir !== '' && is_dir($directory . '/' . $dir),
        ));
    }
}
