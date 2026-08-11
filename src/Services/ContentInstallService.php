<?php

namespace FyWolf\MinecraftManager\Services;

use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use FyWolf\MinecraftManager\Enums\ContentType;
use FyWolf\MinecraftManager\Integrations\Content\ContentProvider;
use FyWolf\MinecraftManager\Integrations\Content\Data\ContentVersion;
use FyWolf\MinecraftManager\Integrations\Content\Data\SearchQuery;
use FyWolf\MinecraftManager\Support\DaemonDirs;
use FyWolf\MinecraftManager\Support\ResolvedProfile;
use Throwable;

/**
 * Installing a single mod or plugin onto a server.
 */
class ContentInstallService
{
    public function __construct(private DaemonFileRepository $repository) {}

    /**
     * Build the search context for a server: its loader and Minecraft version.
     */
    public function contextFor(Server $server, ResolvedProfile $profile, ContentType $type, int $page = 1, ?string $search = null): SearchQuery
    {
        return new SearchQuery(
            type: $type,
            loader: $profile->loader,
            gameVersion: $this->minecraftVersion($server, $profile),
            search: $search,
            page: $page,
        );
    }

    /**
     * The server's Minecraft version, from whichever startup variable this egg
     * happens to use.
     *
     * Eggs disagree — MINECRAFT_VERSION, MC_VERSION, VERSION — so the profile
     * carries an ordered list of candidates rather than this guessing. A value
     * of "latest" is not a version any API will accept, so it resolves to null
     * and the search simply goes unfiltered by version.
     */
    public function minecraftVersion(Server $server, ResolvedProfile $profile): ?string
    {
        $candidates = $profile->mcVersionVariables ?: ['MINECRAFT_VERSION', 'MC_VERSION', 'VERSION'];

        $variables = $server->variables->keyBy(fn ($variable) => strtoupper((string) $variable->env_variable));

        foreach ($candidates as $name) {
            $variable = $variables->get(strtoupper($name));

            if (! $variable) {
                continue;
            }

            $value = trim((string) ($variable->server_value ?? $variable->default_value ?? ''));

            if ($value === '' || in_array(strtolower($value), ['latest', 'newest'], true)) {
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * Install a version's primary file into a directory.
     *
     * `foreground => true` is deliberate and important. The daemon's pull
     * endpoint defaults to background, returning 200 the instant it accepts the
     * job — so a failed download (404, dead CDN, out of disk) is completely
     * silent and the user is told "installed" for a file that never arrived.
     * Blocking means a failure surfaces as an exception we can report.
     *
     * `use_header` is set when the URL carries no usable filename, which is the
     * normal case for CDN links ending in a hash.
     *
     * @return array{ok: bool, filename: string, error?: string}
     */
    public function installFile(Server $server, ContentVersion $version, string $directory): array
    {
        $file = $version->primaryFile();

        if (! $file || ! $file->isInstallable()) {
            return [
                'ok' => false,
                'filename' => $file?->filename ?? $version->name,
                'error' => 'This file cannot be downloaded through the API — the author has disabled third-party distribution.',
            ];
        }

        $directory = DaemonDirs::join($directory);

        try {
            DaemonDirs::ensure($this->repository->setServer($server), $directory);

            $this->repository->setServer($server)->pull($file->url, $directory, [
                'filename' => $file->filename,
                'foreground' => true,
            ]);

            return ['ok' => true, 'filename' => $file->filename];
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'filename' => $file->filename, 'error' => $exception->getMessage()];
        }
    }

    /**
     * Install a version and, optionally, everything it requires.
     *
     * Dependency resolution is the thing the existing Modrinth plugin omits,
     * and its absence is why installing a Fabric mod through it so often
     * produces a server that will not boot: most of them require Fabric API and
     * nothing says so.
     *
     * Failures are collected rather than thrown. A mod installed without one of
     * its three dependencies, reported clearly, is more useful than an aborted
     * install with a stack trace.
     *
     * @return array{installed: array<int, string>, failed: array<int, array{name: string, error: string}>}
     */
    public function install(
        Server $server,
        ResolvedProfile $profile,
        ContentProvider $provider,
        ContentVersion $version,
        ContentType $type,
        bool $withDependencies = true,
    ): array {
        $directory = $type->directory($profile->contentDir) ?? $profile->contentDir ?? '/';

        $installed = [];
        $failed = [];

        $result = $this->installFile($server, $version, $directory);

        if ($result['ok']) {
            $installed[] = $result['filename'];

            Activity::event('server:minecraft.content-install')
                ->property([
                    'provider' => $provider->key(),
                    'project' => $version->projectId,
                    'name' => $version->name,
                    'version' => $version->versionNumber ?? $version->id,
                    'file' => $result['filename'],
                    'directory' => $directory,
                ])
                ->log();
        } else {
            $failed[] = ['name' => $version->name, 'error' => $result['error'] ?? 'unknown error'];

            // No point chasing dependencies for something that did not install.
            return ['installed' => $installed, 'failed' => $failed];
        }

        if (! $withDependencies) {
            return ['installed' => $installed, 'failed' => $failed];
        }

        $context = $this->contextFor($server, $profile, $type);

        foreach ($version->requiredDependencies() as $dependency) {
            try {
                $dependencyVersion = $dependency->versionId
                    ? $provider->version((string) $dependency->projectId, $dependency->versionId)
                    : $provider->latestVersionFor((string) $dependency->projectId, $context);

                if (! $dependencyVersion) {
                    $failed[] = [
                        'name' => $dependency->name ?? (string) $dependency->projectId,
                        'error' => 'No compatible version found for this server.',
                    ];

                    continue;
                }

                $dependencyResult = $this->installFile($server, $dependencyVersion, $directory);

                if ($dependencyResult['ok']) {
                    $installed[] = $dependencyResult['filename'];

                    Activity::event('server:minecraft.content-install')
                        ->property([
                            'provider' => $provider->key(),
                            'project' => $dependencyVersion->projectId,
                            'name' => $dependencyVersion->name,
                            'version' => $dependencyVersion->versionNumber ?? $dependencyVersion->id,
                            'file' => $dependencyResult['filename'],
                            'directory' => $directory,
                            'dependency_of' => $version->projectId,
                        ])
                        ->log();
                } else {
                    $failed[] = [
                        'name' => $dependencyVersion->name,
                        'error' => $dependencyResult['error'] ?? 'unknown error',
                    ];
                }
            } catch (Throwable $exception) {
                report($exception);

                $failed[] = [
                    'name' => (string) $dependency->projectId,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return ['installed' => $installed, 'failed' => $failed];
    }

    /**
     * Count installed jars in the content directory, for the header badge.
     */
    public function installedCount(Server $server, ResolvedProfile $profile): ?int
    {
        $installed = $this->installed($server, $profile);

        return $installed === null ? null : count($installed);
    }

    /**
     * What is actually in the mods or plugins directory right now.
     *
     * Deliberately reports what is on disk rather than what this plugin
     * remembers installing: mods arrive by SFTP, inside modpacks, and from
     * whatever the customer was using before they moved to this panel. A list
     * that only knew about its own installs would be wrong on every real
     * server.
     *
     * Returns null when the directory cannot be read at all, which is different
     * from an empty directory and is surfaced differently in the UI.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function installed(Server $server, ResolvedProfile $profile): ?array
    {
        if (! $profile->contentDir) {
            return null;
        }

        try {
            $entries = $this->repository->setServer($server)->getDirectory(DaemonDirs::join($profile->contentDir));
        } catch (Throwable) {
            return null;
        }

        if (! is_array($entries) || isset($entries['error'])) {
            return null;
        }

        $files = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || empty($entry['file'])) {
                continue;
            }

            $name = (string) ($entry['name'] ?? '');
            $lower = strtolower($name);

            // `.jar.disabled` is the near-universal convention for a mod turned
            // off without deleting it, and it belongs in this list.
            $isJar = str_ends_with($lower, '.jar');
            $isDisabled = str_ends_with($lower, '.jar.disabled') || str_ends_with($lower, '.disabled');

            if (! $isJar && ! $isDisabled) {
                continue;
            }

            $files[$name] = [
                'name' => $name,
                'guess' => $this->guessProjectName($name),
                'size' => (int) ($entry['size'] ?? 0),
                'modified_at' => $entry['modified'] ?? null,
                'disabled' => $isDisabled,
            ];
        }

        uasort($files, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $files;
    }

    /**
     * Guess a searchable project name from a jar filename.
     *
     * Presented as a search term, never as an identification. Doing this
     * properly would mean hashing each jar and asking Modrinth's
     * /version_file/{sha1} endpoint, but the daemon exposes no hash and
     * streaming every jar through the panel to compute one would be far more
     * expensive than the feature is worth.
     */
    public function guessProjectName(string $filename): string
    {
        $name = preg_replace('/\.(jar|disabled)$/i', '', $filename) ?? $filename;
        $name = preg_replace('/\.jar$/i', '', $name) ?? $name;

        // Cut at the first version-looking token: "sodium-fabric-0.5.8+mc1.20.4"
        // becomes "sodium fabric".
        $name = preg_split('/[-_+]v?\d/', $name)[0] ?? $name;

        // Drop loader and platform noise that would skew a search.
        $name = preg_replace('/\b(fabric|forge|neoforge|quilt|bukkit|spigot|paper|mc|minecraft)\b/i', ' ', $name) ?? $name;

        $name = trim((string) preg_replace('/[-_.]+/', ' ', $name));

        return $name !== '' ? $name : $filename;
    }

    /**
     * Delete a file from the content directory.
     */
    public function deleteInstalled(Server $server, ResolvedProfile $profile, string $filename): void
    {
        $directory = DaemonDirs::join($profile->contentDir);

        $this->repository->setServer($server)->deleteFiles($directory, [$filename]);

        Activity::event('server:minecraft.content-delete')
            ->property(['name' => $filename, 'directory' => $directory])
            ->log();
    }

    /**
     * Turn a mod off without deleting it, by renaming to `.disabled`.
     *
     * Every loader ignores files that do not end in `.jar`, so this is the
     * standard way to bisect a crash without losing the file — and it is
     * reversible, which deletion is not.
     */
    public function toggleInstalled(Server $server, ResolvedProfile $profile, string $filename, bool $disable): string
    {
        $directory = DaemonDirs::join($profile->contentDir);

        $target = $disable
            ? $filename . '.disabled'
            : (string) preg_replace('/\.disabled$/i', '', $filename);

        $this->repository->setServer($server)->renameFiles($directory, [
            ['from' => $filename, 'to' => $target],
        ]);

        Activity::event('server:minecraft.content-toggle')
            ->property(['name' => $filename, 'enabled' => ! $disable, 'directory' => $directory])
            ->log();

        return $target;
    }
}
