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

        $count = 0;

        foreach ($entries as $entry) {
            if (is_array($entry) && ! empty($entry['file']) && str_ends_with(strtolower((string) ($entry['name'] ?? '')), '.jar')) {
                $count++;
            }
        }

        return $count;
    }
}
