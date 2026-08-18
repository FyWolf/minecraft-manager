<?php

namespace FyWolf\MinecraftManager\Jobs;

use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Filament\Notifications\Notification;
use FyWolf\MinecraftManager\Enums\PackInstallState;
use FyWolf\MinecraftManager\Integrations\Content\ContentProviderRegistry;
use FyWolf\MinecraftManager\Integrations\Content\CurseForgeProvider;
use FyWolf\MinecraftManager\Models\PackInstall;
use FyWolf\MinecraftManager\Services\VersionInstallService;
use FyWolf\MinecraftManager\Support\CapabilityResolver;
use FyWolf\MinecraftManager\Support\DaemonDirs;
use FyWolf\MinecraftManager\Support\PackEntry;
use FyWolf\MinecraftManager\Support\PackManifest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Throwable;
use ZipArchive;

/**
 * Install a whole modpack onto a server.
 *
 * The pack *archive* is fetched to the panel rather than handed to the daemon,
 * which looks backwards but is forced: we have to read `modrinth.index.json` or
 * `manifest.json` to know what the pack even contains, and the panel cannot
 * read a binary zip back out of Wings. Only the pack's *member files* go
 * through the daemon's pull, which is what keeps hundreds of megabytes of mod
 * jars off the panel entirely.
 *
 * Failure policy: individual files that cannot be fetched are recorded and
 * skipped, because a pack three mods short with a clear list beats an aborted
 * install. The whole job aborts only when a large share fails or the daemon
 * stops answering.
 */
class InstallModpackJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** An hour: a 300-file pack against a slow mirror is genuinely slow. */
    public int $timeout = 3600;

    /**
     * Exactly one attempt.
     *
     * A blind framework retry would re-run the backup and the clear step
     * against a half-installed server. Resuming is a deliberate user action,
     * not something the queue should do on its own.
     */
    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $installId)
    {
        $this->onQueue((string) config('minecraft-manager.packs.queue', 'default'));
    }

    /**
     * One install per server at a time. The DB check in the page catches the
     * common case and gives a clean error; this catches the race.
     */
    public function uniqueId(): string
    {
        $install = PackInstall::find($this->installId);

        return 'mcm-pack:' . ($install?->server_id ?? $this->installId);
    }

    public int $uniqueFor = 3600;

    public function handle(
        DaemonFileRepository $files,
        ContentProviderRegistry $registry,
        CapabilityResolver $resolver,
        VersionInstallService $versions,
    ): void {
        $install = PackInstall::find($this->installId);

        if (! $install || $install->state->isTerminal()) {
            return;
        }

        $server = $install->server;

        if (! $server) {
            $this->failInstall($install, 'The server no longer exists.');

            return;
        }

        $temporary = null;

        try {
            $this->guard($server, $install);

            $install->forceFill(['started_at' => now()])->save();

            // 1. Fetch and unpack the archive, on the panel.
            $install->markState(PackInstallState::DownloadingPack, 'Downloading the pack archive');

            $temporary = TemporaryDirectory::make()->deleteWhenDestroyed();

            $packDir = $this->downloadAndExtract($install, $registry, $temporary);

            // 2. Read the manifest.
            $install->markState(PackInstallState::Parsing, 'Reading the manifest');

            $manifest = PackManifest::fromDirectory($packDir);

            $entries = $this->resolveEntries($manifest, $registry);

            $installable = array_values(array_filter($entries, fn (PackEntry $e) => $e->isInstallable()));
            $blocked = array_values(array_filter($entries, fn (PackEntry $e) => ! $e->distributionAllowed));

            $maxFiles = (int) config('minecraft-manager.packs.max_files', 1000);

            if (count($installable) > $maxFiles) {
                throw new RuntimeException(sprintf(
                    'This pack lists %d files, above the configured limit of %d.',
                    count($installable),
                    $maxFiles,
                ));
            }

            $install->forceFill([
                'pack_name' => $manifest->name,
                'pack_version' => $manifest->version ?? $install->pack_version,
                'mc_version' => $manifest->mcVersion,
                'loader' => $manifest->loader,
                'loader_version' => $manifest->loaderVersion,
                'progress_total' => count($installable),
                'progress_current' => 0,
            ])->save();

            $log = [];

            foreach ($blocked as $entry) {
                $log[] = [
                    'path' => $entry->path ?: ('project ' . $entry->projectId),
                    'status' => 'manual',
                    'reason' => 'The author has disabled third-party downloads.',
                    'url' => $entry->browserUrl,
                ];
            }

            // 3. Back up what we are about to overwrite.
            $install->markState(PackInstallState::BackingUp, 'Backing up mods and config');

            $this->backup($files, $server, $install, $resolver);

            // 4. Download every file through the daemon.
            $install->markState(PackInstallState::DownloadingFiles, 'Downloading mods');

            $log = array_merge($log, $this->downloadFiles($files, $server, $install, $installable));

            // 5. Apply the pack's own configuration.
            $install->markState(PackInstallState::ApplyingOverrides, 'Applying configuration');

            $this->applyOverrides($files, $server, $packDir, $manifest);

            // 6. Point the egg at the right versions.
            $install->markState(PackInstallState::Configuring, 'Setting the version');

            if ($manifest->mcVersion) {
                $profile = $resolver->for($server);

                if ($profile) {
                    $result = $versions->writeVersionVariables($server, $profile, $manifest->mcVersion, $manifest->loaderVersion);

                    // A pack whose loader version the egg refuses installs its
                    // mods against a loader the server is not running. Every
                    // file reports 'ok' and the pack does not work, which is the
                    // hardest kind of failure to be handed — so it goes in the
                    // log the operator already reads.
                    foreach ($result['rejected'] as $variable => $value) {
                        $log[] = [
                            'path' => $variable,
                            'status' => 'failed',
                            'reason' => "The egg rejected \"$value\". Set $variable by hand before starting the server.",
                        ];
                    }
                }
            }

            $installed = count(array_filter($log, fn (array $e) => ($e['status'] ?? null) === 'ok'));

            $install->forceFill([
                'log' => $log,
                'current_step' => null,
            ])->save();

            $install->markState(PackInstallState::Completed);

            $this->logActivity($install, 'server:minecraft.modpack-install-finish', [
                'pack' => $manifest->name,
                'installed' => $installed,
                'total' => count($installable),
                'manual' => count($blocked),
            ]);

            $this->notify($install, 'Modpack installed', sprintf(
                '%s: %d of %d files installed%s. Start the server when you are ready.',
                $manifest->name,
                $installed,
                count($installable),
                $blocked === [] ? '' : ', ' . count($blocked) . ' need downloading by hand',
            ));
        } catch (Throwable $exception) {
            report($exception);

            $this->failInstall($install, $exception->getMessage());
        } finally {
            unset($temporary);
        }
    }

    /**
     * Re-check preconditions inside the worker.
     *
     * The page checked these too, but an arbitrary amount of time may have
     * passed in the queue and the server may have been started since.
     */
    private function guard(Server $server, PackInstall $install): void
    {
        if ($server->isInConflictState()) {
            throw new RuntimeException('The server is installing, suspended or transferring.');
        }

        if (! $server->retrieveStatus()->isOffline()) {
            throw new RuntimeException('The server started before the install began. Stop it and try again.');
        }
    }

    private function downloadAndExtract(PackInstall $install, ContentProviderRegistry $registry, TemporaryDirectory $temporary): string
    {
        $provider = $registry->get($install->provider);

        if (! $provider) {
            throw new RuntimeException('The ' . $install->provider . ' provider is not available.');
        }

        $version = $provider->version($install->project_id, $install->version_id);
        $file = $version?->primaryFile();

        if (! $file || ! $file->isInstallable()) {
            throw new RuntimeException('This pack cannot be downloaded through the API.');
        }

        $maxSize = (int) config('minecraft-manager.packs.max_archive_size', 512 * 1024 * 1024);

        if ($file->size !== null && $file->size > $maxSize) {
            throw new RuntimeException('The pack archive is larger than the configured limit.');
        }

        $archivePath = $temporary->path('pack.zip');

        // sink(), never ->body(): a 500 MB pack read into a string would take
        // the panel's PHP process with it.
        $response = Http::timeout(600)->connectTimeout(10)->sink($archivePath)->get($file->url);

        if ($response->failed()) {
            throw new RuntimeException('Downloading the pack archive failed with HTTP ' . $response->status() . '.');
        }

        if (filesize($archivePath) > $maxSize) {
            throw new RuntimeException('The pack archive is larger than the configured limit.');
        }

        $packDir = $temporary->path('pack');

        @mkdir($packDir, 0o755, true);

        $zip = new ZipArchive();

        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('The pack archive could not be opened.');
        }

        // Zip-slip check before extracting anything, the same guard the panel
        // applies to plugin uploads.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();

                throw new RuntimeException('The pack archive contains unsafe paths.');
            }
        }

        if (! $zip->extractTo($packDir)) {
            $zip->close();

            throw new RuntimeException('The pack archive could not be extracted.');
        }

        $zip->close();

        return $packDir;
    }

    /**
     * Turn manifest entries into things with URLs.
     *
     * Modrinth entries already carry direct CDN links. CurseForge entries carry
     * only {projectID, fileID} and are resolved in batches — a 200-mod pack
     * would otherwise be 200 sequential round-trips against a per-key quota.
     *
     * @return array<int, PackEntry>
     */
    private function resolveEntries(PackManifest $manifest, ContentProviderRegistry $registry): array
    {
        if ($manifest->format !== 'curseforge') {
            return $manifest->entries;
        }

        $curseforge = $registry->get('curseforge');

        if (! $curseforge instanceof CurseForgeProvider) {
            throw new RuntimeException('This is a CurseForge pack, but no CurseForge API key is configured.');
        }

        $fileIds = array_values(array_filter(array_map(
            fn (PackEntry $entry) => $entry->fileId,
            $manifest->entries,
        )));

        $resolved = $curseforge->resolveFiles($fileIds);

        $entries = [];

        foreach ($manifest->entries as $entry) {
            $version = $entry->fileId ? ($resolved[$entry->fileId] ?? null) : null;
            $file = $version?->primaryFile();

            if (! $file) {
                $entries[] = new PackEntry(
                    path: 'mods/unknown-' . $entry->fileId,
                    required: $entry->required,
                    distributionAllowed: false,
                    browserUrl: 'https://www.curseforge.com/minecraft/mc-mods',
                    projectId: $entry->projectId,
                    fileId: $entry->fileId,
                );

                continue;
            }

            $entries[] = new PackEntry(
                // CurseForge manifests do not say where a file goes; mods/ is
                // the only sane default and matches what every launcher does.
                path: 'mods/' . $file->filename,
                urls: $file->url ? [$file->url] : [],
                size: $file->size,
                hashes: $file->hashes,
                required: $entry->required,
                distributionAllowed: $file->distributionAllowed,
                browserUrl: $file->browserUrl,
                projectId: $entry->projectId,
                fileId: $entry->fileId,
            );
        }

        return $entries;
    }

    private function backup(DaemonFileRepository $files, Server $server, PackInstall $install, CapabilityResolver $resolver): void
    {
        $profile = $resolver->for($server);
        $contentDir = trim((string) ($profile?->contentDir ?? 'mods'), '/');

        try {
            // compressFiles, deliberately not InitiateBackupService: that is
            // asynchronous (Wings calls back when done), so there would be no
            // moment at which it is known safe to proceed — and it consumes the
            // server's backup_limit. This is synchronous and costs no quota.
            $archive = $files->setServer($server)->compressFiles(
                '/',
                array_values(array_filter([$contentDir, 'config'])),
                'mcm-prepack-' . now()->format('Ymd-His'),
                'tar.gz',
            );

            $install->forceFill(['backup_path' => $archive['name'] ?? null])->save();
        } catch (Throwable $exception) {
            // A pack install over a server with no mods yet has nothing to
            // archive, which is not a failure.
            report($exception);
        }
    }

    /**
     * @param array<int, PackEntry> $entries
     *
     * @return array<int, array<string, mixed>>
     */
    private function downloadFiles(DaemonFileRepository $files, Server $server, PackInstall $install, array $entries): array
    {
        $log = [];
        $done = 0;
        $failures = 0;
        $consecutiveConnectionFailures = 0;

        $delayMs = (int) config('minecraft-manager.packs.file_delay_ms', 150);
        $retries = (int) config('minecraft-manager.packs.file_retries', 2);
        $threshold = (float) config('minecraft-manager.packs.failure_threshold', 0.2);
        $maxConsecutive = (int) config('minecraft-manager.packs.consecutive_connection_failures', 5);

        $ensured = [];

        foreach ($entries as $entry) {
            $directory = $entry->directory();

            // Wings has no recursive mkdir and pull into a missing directory
            // fails, so each new directory is walked once.
            if (! isset($ensured[$directory])) {
                try {
                    DaemonDirs::ensure($files->setServer($server), $directory);
                } catch (Throwable) {
                    // A pull may still succeed if it already exists.
                }

                $ensured[$directory] = true;
            }

            $result = $this->pullWithRetries($files, $server, $entry, $retries);

            if ($result === true) {
                $log[] = ['path' => $entry->path, 'status' => 'ok'];
                $consecutiveConnectionFailures = 0;
            } else {
                $failures++;

                $log[] = [
                    'path' => $entry->path,
                    'status' => 'failed',
                    'reason' => $result,
                    'url' => $entry->url(),
                ];

                if (str_contains((string) $result, 'daemon')) {
                    $consecutiveConnectionFailures++;
                }
            }

            $done++;

            $install->forceFill([
                'progress_current' => $done,
                'current_step' => 'Downloading ' . $entry->filename(),
            ])->save();

            if ($consecutiveConnectionFailures >= $maxConsecutive) {
                throw new RuntimeException('The server daemon stopped responding after ' . $done . ' files.');
            }

            if (count($entries) > 10 && ($failures / count($entries)) > $threshold) {
                throw new RuntimeException(sprintf(
                    'Too many files failed (%d of %d). Aborting rather than leaving a half-installed pack.',
                    $failures,
                    count($entries),
                ));
            }

            // The daemon's pull rate limit is route middleware and does not
            // apply to a job, so nothing but this stops us hammering a node
            // harder than the panel's own UI ever could.
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        return $log;
    }

    /**
     * @return true|string true, or the reason it failed
     */
    private function pullWithRetries(DaemonFileRepository $files, Server $server, PackEntry $entry, int $retries): true|string
    {
        $urls = $entry->urls ?: [];
        $lastError = 'No download URL.';

        foreach ($urls as $url) {
            for ($attempt = 0; $attempt <= $retries; $attempt++) {
                try {
                    // foreground: the default is fire-and-forget, where the
                    // daemon returns 200 on acceptance and a failed download is
                    // completely silent. A progress bar built on that would be
                    // lying.
                    $files->setServer($server)->pull($url, $entry->directory(), [
                        'filename' => $entry->filename(),
                        'foreground' => true,
                    ]);

                    return true;
                } catch (ConnectionException $exception) {
                    $lastError = 'daemon: ' . $exception->getMessage();
                } catch (Throwable $exception) {
                    $lastError = $exception->getMessage();
                }

                if ($attempt < $retries) {
                    usleep(400_000);
                }
            }
        }

        return $lastError;
    }

    /**
     * Copy the pack's own config tree onto the server.
     */
    private function applyOverrides(DaemonFileRepository $files, Server $server, string $packDir, PackManifest $manifest): void
    {
        $protected = array_map('strtolower', (array) config('minecraft-manager.packs.protected_overrides', []));

        foreach ($manifest->overrideDirs as $overrideDir) {
            $root = $packDir . '/' . $overrideDir;

            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $item) {
                if (! $item->isFile()) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));

                if (! DaemonDirs::isSafeRelativePath($relative)) {
                    continue;
                }

                // Overwriting server.properties from a pack silently resets the
                // port, level-name and MOTD — all of which the panel or the
                // player owns, not the pack.
                if (in_array(strtolower(basename($relative)), $protected, true)) {
                    continue;
                }

                try {
                    $directory = trim(dirname($relative), '/.');

                    if ($directory !== '') {
                        DaemonDirs::ensure($files->setServer($server), $directory);
                    }

                    $files->setServer($server)->putContent($relative, (string) file_get_contents($item->getPathname()));
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }
    }

    private function failInstall(PackInstall $install, string $message): void
    {
        $install->forceFill(['error' => $message])->save();
        $install->markState(PackInstallState::Failed);

        $this->logActivity($install, 'server:minecraft.modpack-install-failed', [
            'pack' => $install->pack_name,
            'error' => $message,
        ]);

        // Deliberately NOT auto-restoring the pre-install archive. Unpacking a
        // backup over a half-finished install is how a bad install becomes a
        // destroyed server; the page offers restore as an explicit action.
        $this->notify(
            $install,
            'Modpack install failed',
            $message . ($install->backup_path ? " Your mods and config were archived to {$install->backup_path} beforehand." : ''),
            danger: true,
        );
    }

    /**
     * Log an activity entry from inside the queue.
     *
     * A job has no authenticated user, so the actor has to be set explicitly
     * from the row — and guarded, because `user_id` is nullOnDelete and
     * `ActivityLogService::actor()` is typed to a non-null Model. An account
     * deleted mid-install would otherwise take the whole job down with a
     * TypeError at the very last step.
     *
     * @param array<string, mixed> $properties
     */
    private function logActivity(PackInstall $install, string $event, array $properties): void
    {
        $entry = Activity::event($event);

        if ($install->user) {
            $entry = $entry->actor($install->user);
        }

        if ($install->server) {
            $entry = $entry->subject($install->server);
        }

        $entry->property($properties)->log();
    }

    private function notify(PackInstall $install, string $title, string $body, bool $danger = false): void
    {
        if (! $install->user) {
            return;
        }

        // Database, not session: by the time a large pack finishes, the browser
        // tab that started it is long gone.
        Notification::make()
            ->title($title)
            ->body($body)
            ->{$danger ? 'danger' : 'success'}()
            ->sendToDatabase($install->user);
    }

    public function failed(Throwable $exception): void
    {
        $install = PackInstall::find($this->installId);

        if ($install && ! $install->state->isTerminal()) {
            $this->failInstall($install, $exception->getMessage());
        }
    }
}
