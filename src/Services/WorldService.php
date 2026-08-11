<?php

namespace FyWolf\MinecraftManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use FyWolf\MinecraftManager\Support\DaemonDirs;
use FyWolf\MinecraftManager\Support\PropertiesFile;
use FyWolf\MinecraftManager\Support\ResolvedProfile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finding, measuring and grouping a server's worlds.
 *
 * The important thing this does is group dimensions. On the Bukkit family
 * `world`, `world_nether` and `world_the_end` are three sibling folders that
 * together make one logical world, so archiving `world` alone produces a backup
 * with no nether and deleting `world` alone leaves two orphans that the server
 * will happily keep using. On Vanilla/Fabric/Forge the dimensions live inside
 * `world/DIM-1` and nothing extra is needed. Which layout applies comes from the
 * capability profile, not from guesswork.
 */
class WorldService
{
    public function __construct(private DaemonFileRepository $repository) {}

    /**
     * Discover the server's worlds.
     *
     * @return array<int, array{
     *     name: string,
     *     path: string,
     *     dimensions: array<int, string>,
     *     folders: array<int, string>,
     *     is_active: bool,
     *     modified_at: ?string
     * }>
     */
    public function list(Server $server, ResolvedProfile $profile): array
    {
        $root = $profile->worldsDir ?: '/';

        $entries = $this->repository->setServer($server)->getDirectory($root);

        if (isset($entries['error'])) {
            throw new \RuntimeException((string) $entries['error']);
        }

        $ignored = array_map('strtolower', (array) config('minecraft-manager.worlds.ignored_directories', []));

        // The egg's own denylist is a good extra signal — those paths are ones
        // the admin has already declared off-limits.
        $server->loadMissing('egg');
        foreach ((array) ($server->egg?->inherit_file_denylist ?? []) as $denied) {
            $ignored[] = strtolower(trim((string) $denied, '/*'));
        }

        $candidates = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || empty($entry['directory'])) {
                continue;
            }

            $name = (string) ($entry['name'] ?? '');

            if ($name === '' || str_starts_with($name, '.')) {
                continue;
            }

            if (in_array(strtolower($name), $ignored, true)) {
                continue;
            }

            $candidates[$name] = $entry;
        }

        if ($candidates === []) {
            return [];
        }

        $suffixes = (array) config('minecraft-manager.worlds.dimension_suffixes', ['_nether', '_the_end']);

        // On the Bukkit layout, fold `X_nether` into `X` when `X` is itself a
        // candidate. Done before the level.dat probe because a dimension folder
        // has its own level.dat and would otherwise be listed as a world in its
        // own right.
        $dimensionsOf = [];

        if ($profile->hasSiblingDimensions()) {
            foreach (array_keys($candidates) as $name) {
                foreach ($suffixes as $suffix) {
                    if (! str_ends_with($name, $suffix)) {
                        continue;
                    }

                    $base = substr($name, 0, -strlen($suffix));

                    if ($base !== '' && isset($candidates[$base])) {
                        $dimensionsOf[$base][] = $name;
                        unset($candidates[$name]);
                    }

                    break;
                }
            }
        }

        $active = $this->activeWorldName($server, $profile);

        $worlds = [];

        foreach ($candidates as $name => $entry) {
            if (! $this->looksLikeWorld($server, $root, $name)) {
                continue;
            }

            $dimensions = $dimensionsOf[$name] ?? [];

            $worlds[] = [
                'name' => $name,
                'path' => DaemonDirs::join($root, $name),
                'dimensions' => $dimensions,
                // Every folder that must move together. This is the list that
                // archive and delete operate on — never the bare name.
                'folders' => array_merge([$name], $dimensions),
                'is_active' => $name === $active,
                'modified_at' => $entry['modified'] ?? null,
            ];
        }

        usort($worlds, fn (array $a, array $b) => [$b['is_active'], strtolower($a['name'])] <=> [$a['is_active'], strtolower($b['name'])]);

        return $worlds;
    }

    /**
     * A directory is a world if it contains level.dat.
     *
     * One extra listing per candidate, which is why the ignore list above
     * matters: at the server root it keeps this to a handful of probes.
     */
    private function looksLikeWorld(Server $server, string $root, string $name): bool
    {
        return cache()->remember(
            "mcm:world-probe:{$server->uuid}:" . md5($root . '/' . $name),
            (int) config('minecraft-manager.cache.directory', 30),
            function () use ($server, $root, $name) {
                try {
                    $contents = $this->repository->setServer($server)->getDirectory(DaemonDirs::join($root, $name));

                    if (isset($contents['error']) || ! is_array($contents)) {
                        return false;
                    }

                    foreach ($contents as $entry) {
                        if (is_array($entry) && ($entry['name'] ?? null) === 'level.dat') {
                            return true;
                        }
                    }
                } catch (Throwable $exception) {
                    Log::debug('minecraft-manager: world probe failed', [
                        'server' => $server->uuid,
                        'world' => $name,
                        'error' => $exception->getMessage(),
                    ]);
                }

                return false;
            },
        );
    }

    /**
     * The active world, per `level-name` in server.properties.
     *
     * Defaults to `world`, which is what Minecraft itself does when the key is
     * absent. Returns null only when the file cannot be read at all — a server
     * that has never been started has no server.properties, and that is a
     * banner rather than an error.
     */
    public function activeWorldName(Server $server, ResolvedProfile $profile): ?string
    {
        try {
            $properties = $this->readProperties($server);
        } catch (Throwable) {
            return null;
        }

        if (! $properties) {
            return null;
        }

        $name = trim((string) $properties->get('level-name', 'world'));

        return $name !== '' ? $name : 'world';
    }

    public function readProperties(Server $server): ?PropertiesFile
    {
        try {
            $contents = $this->repository->setServer($server)->getContent(
                'server.properties',
                (int) config('minecraft-manager.configs.max_file_size', 512 * 1024),
            );
        } catch (Throwable) {
            return null;
        }

        return PropertiesFile::parse($contents);
    }

    /**
     * Approximate a world's size on disk.
     *
     * Wings reports the inode size for a directory rather than a recursive
     * total and exposes no recursive-size endpoint, so this walks the handful of
     * subdirectories that hold essentially all of a world's bytes. Region files
     * are few and large, so the result is close enough to be useful and is
     * labelled "approx." in the UI. Returns null rather than a wrong number if
     * anything fails.
     */
    public function approximateSize(Server $server, string $root, array $folders): ?int
    {
        $key = "mcm:world-size:{$server->uuid}:" . md5($root . '|' . implode(',', $folders));

        return cache()->remember($key, 300, function () use ($server, $root, $folders): ?int {
            $probes = (array) config('minecraft-manager.worlds.size_probe_paths', []);
            $total = 0;
            $sawAnything = false;

            foreach ($folders as $folder) {
                foreach ($probes as $probe) {
                    try {
                        $entries = $this->repository->setServer($server)->getDirectory(DaemonDirs::join($root, $folder, $probe));
                    } catch (Throwable) {
                        continue;
                    }

                    if (! is_array($entries) || isset($entries['error'])) {
                        continue;
                    }

                    foreach ($entries as $entry) {
                        if (is_array($entry) && ! empty($entry['file'])) {
                            $total += (int) ($entry['size'] ?? 0);
                            $sawAnything = true;
                        }
                    }
                }
            }

            return $sawAnything ? $total : null;
        });
    }

    /**
     * Forget cached probes for a server, after an operation changed its worlds.
     */
    public function forget(Server $server): void
    {
        // Cache tags are unavailable on the file and database drivers, so the
        // per-key TTLs are short by design and this is best-effort.
        cache()->forget("mcm:world-active:{$server->uuid}");
    }
}
