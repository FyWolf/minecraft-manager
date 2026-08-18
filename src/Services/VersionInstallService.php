<?php

namespace FyWolf\MinecraftManager\Services;

use App\Facades\Activity;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Repositories\Daemon\DaemonFileRepository;
use FyWolf\MinecraftManager\Integrations\Versions\VersionProvider;
use FyWolf\MinecraftManager\Integrations\Versions\VersionProviderRegistry;
use FyWolf\MinecraftManager\Support\ResolvedProfile;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Changing a server's Minecraft version.
 *
 * Two paths, chosen by the capability profile:
 *
 *   version_provider set   -> swap the jar, leaving everything else alone
 *   version_provider null  -> write the startup variable and reinstall
 *
 * The second is not a fallback for laziness. Forge and NeoForge publish an
 * installer rather than a runnable jar, so there is nothing to swap; only the
 * egg's own install script knows how to run it.
 */
class VersionInstallService
{
    public function __construct(
        private DaemonFileRepository $files,
        private VersionProviderRegistry $registry,
    ) {}

    public function providerFor(ResolvedProfile $profile): ?VersionProvider
    {
        return $this->registry->get($profile->versionProvider);
    }

    /**
     * The jar this server actually runs.
     *
     * The profile may pin it; otherwise the egg's own SERVER_JARFILE variable
     * is authoritative, because that is what the startup command references.
     */
    public function jarFilename(Server $server, ResolvedProfile $profile): string
    {
        if (filled($profile->jarPath)) {
            return (string) $profile->jarPath;
        }

        $variable = $server->variables->first(
            fn ($variable) => strtoupper((string) $variable->env_variable) === 'SERVER_JARFILE',
        );

        $value = trim((string) ($variable?->server_value ?? $variable?->default_value ?? ''));

        return $value !== '' ? $value : (string) config('minecraft-manager.versions.default_jar', 'server.jar');
    }

    /**
     * Swap the server jar for a different version.
     *
     * @return array{ok: bool, message: string, archived?: ?string}
     */
    public function swapJar(
        Server $server,
        ResolvedProfile $profile,
        VersionProvider $provider,
        string $gameVersion,
        string $buildId,
        bool $archiveFirst = true,
    ): array {
        $url = $provider->downloadUrl($gameVersion, $buildId);

        if (! $url) {
            return ['ok' => false, 'message' => 'Could not resolve a download for that build. The upstream API may be unavailable.'];
        }

        $jar = $this->jarFilename($server, $profile);
        $archived = null;

        if ($archiveFirst) {
            try {
                $archive = $this->files->setServer($server)->compressFiles(
                    '/',
                    [$jar],
                    'mcm-jar-' . now()->format('Ymd-His'),
                    'tar.gz',
                );

                $archived = $archive['name'] ?? null;
            } catch (Throwable $exception) {
                // A ~50 MB archive is cheap insurance, but not being able to
                // take one should not block a version change outright — the jar
                // is re-downloadable. Warn by returning the reason.
                report($exception);
            }
        }

        try {
            // foreground: the daemon's pull defaults to background and returns
            // 200 the moment it accepts the job, so a 404 from a moved artifact
            // would leave the old jar in place while we reported success.
            $this->files->setServer($server)->pull($url, '/', [
                'filename' => $jar,
                'foreground' => true,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return [
                'ok' => false,
                'message' => 'The download failed: ' . $exception->getMessage()
                    . ($archived ? " The previous jar is archived as $archived." : ''),
                'archived' => $archived,
            ];
        }

        // The step everyone forgets. The mod browser filters on the version
        // *variable*, not on the jar, so skipping this is exactly why "I
        // upgraded to 1.21 but it still shows me 1.20 mods" happens.
        $this->writeVersionVariables($server, $profile, $gameVersion, $buildId);

        Activity::event('server:minecraft.version-change')
            ->property([
                'mode' => 'jar',
                'version' => $gameVersion,
                'build' => $buildId,
                'provider' => $provider->key(),
                'jar' => $jar,
                'archived_to' => $archived,
            ])
            ->log();

        return [
            'ok' => true,
            'message' => "Installed $gameVersion (build $buildId) as $jar."
                . ($archived ? " The previous jar is archived as $archived." : ''),
            'archived' => $archived,
        ];
    }

    /**
     * Write the Minecraft and loader version startup variables.
     *
     * Uses the panel's own validated write path: skip variables the egg marks
     * as not user-editable, validate against the variable's own rules, and log
     * a core `server:startup.edit` event so the change reads normally in the
     * activity feed rather than as a raw key.
     *
     * **`$loaderVersion` used to be omitted on the reinstall path**, which is the
     * only path Forge and NeoForge ever take — so `loader_version_variables` was
     * configured, documented, and never written for the two loaders that have
     * it. Changing a 1.15.2 server to 1.20.1 left `FORGE_VERSION=31.2.4` behind
     * and the install script then asked Forge for a build that does not exist
     * for that Minecraft version.
     *
     * @return array{written: array<int, string>, rejected: array<string, string>, matched: array<int, string>}
     *     `rejected` is env variable => the value the egg refused, and is
     *     returned rather than logged because the caller has to *say so*: a
     *     partial write is a server that reinstalls into a state nobody asked
     *     for, and reporting only the total failure is how that stayed invisible.
     *     `matched` separates "this egg has no version variable" from "it was
     *     already set to that", which are both an empty `written`.
     */
    public function writeVersionVariables(
        Server $server,
        ResolvedProfile $profile,
        string $gameVersion,
        ?string $loaderVersion = null,
    ): array {
        $targets = [];

        foreach ($profile->mcVersionVariables as $name) {
            $targets[strtoupper($name)] = $gameVersion;
        }

        if ($loaderVersion !== null && $loaderVersion !== '') {
            foreach ($profile->loaderVersionVariables as $name) {
                $targets[strtoupper($name)] = $loaderVersion;
            }
        }

        // Resolve and validate everything BEFORE writing anything. Validating as
        // it went meant a rejected FORGE_VERSION landed after MINECRAFT_VERSION
        // had already been saved, leaving the server pointing at a Minecraft
        // version its Forge build does not exist for — a state nobody chose and
        // which reads, from the startup tab, as though the change worked.
        $pending = [];
        $rejected = [];
        $matched = [];

        foreach ($server->variables as $variable) {
            $name = strtoupper((string) $variable->env_variable);

            if (! array_key_exists($name, $targets) || ! $variable->user_editable) {
                continue;
            }

            $matched[] = (string) $variable->env_variable;

            $value = (string) $targets[$name];
            $original = $variable->server_value ?? $variable->default_value;

            // Consumed either way: the profile lists alternatives for one role,
            // not a set to write all of, so the first match wins even when it
            // needs no change.
            unset($targets[$name]);

            if ((string) $original === $value) {
                continue;
            }

            $validator = Validator::make(
                ['variable_value' => $value],
                ['variable_value' => $variable->rules ?? []],
            );

            if ($validator->fails()) {
                // A value this egg's rules reject — an enum of allowed versions,
                // or a FORGE_VERSION pattern wanting the bare build rather than
                // the full artifact. Not forcing it through is right; saying
                // nothing about it was not.
                $rejected[(string) $variable->env_variable] = $value;

                continue;
            }

            $pending[] = ['variable' => $variable, 'value' => $value, 'original' => $original];
        }

        if ($rejected !== []) {
            return ['written' => [], 'rejected' => $rejected, 'matched' => $matched];
        }

        $written = [];

        foreach ($pending as $write) {
            ServerVariable::query()->updateOrCreate(
                ['server_id' => $server->id, 'variable_id' => $write['variable']->id],
                ['variable_value' => $write['value']],
            );

            Activity::event('server:startup.edit')
                ->property([
                    'variable' => $write['variable']->env_variable,
                    'old' => $write['original'],
                    'new' => $write['value'],
                ])
                ->log();

            $written[] = (string) $write['variable']->env_variable;
        }

        return ['written' => $written, 'rejected' => [], 'matched' => $matched];
    }

    /**
     * What `FORGE_VERSION` currently holds, so the right spelling can be written
     * back — see `ForgeVersions::wantsFullArtifact()` for why eggs disagree.
     */
    public function currentLoaderVersion(Server $server, ResolvedProfile $profile): ?string
    {
        $candidates = array_map('strtoupper', $profile->loaderVersionVariables);

        foreach ($server->variables as $variable) {
            if (! in_array(strtoupper((string) $variable->env_variable), $candidates, true)) {
                continue;
            }

            $value = trim((string) ($variable->server_value ?? $variable->default_value ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Which Java major a Minecraft version needs.
     */
    public function requiredJava(string $gameVersion): ?int
    {
        foreach ((array) config('minecraft-manager.versions.java_requirements', []) as $rule) {
            if (! isset($rule['min_mc'], $rule['java'])) {
                continue;
            }

            if (version_compare($this->normaliseVersion($gameVersion), $this->normaliseVersion((string) $rule['min_mc']), '>=')) {
                return (int) $rule['java'];
            }
        }

        return null;
    }

    /**
     * Minecraft versions are dotted but not semver ("1.21", "1.20.5"), and
     * snapshots are not comparable at all. Pad to three components so
     * version_compare behaves.
     */
    private function normaliseVersion(string $version): string
    {
        if (! preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $version, $matches)) {
            return '0.0.0';
        }

        return sprintf('%d.%d.%d', $matches[1], $matches[2], $matches[3] ?? 0);
    }

    /**
     * Find a docker image on this egg providing the required Java major.
     *
     * `docker_images` is a label => image map. The tag usually carries the
     * version ("...:java_21"), and the label usually says "Java 21"; try both.
     *
     * @return array{label: string, image: string}|null
     */
    public function imageForJava(Server $server, int $java): ?array
    {
        $server->loadMissing('egg');

        foreach ((array) ($server->egg?->docker_images ?? []) as $label => $image) {
            if (preg_match('/(\d+)\s*$/', (string) $image, $m) && (int) $m[1] === $java) {
                return ['label' => (string) $label, 'image' => (string) $image];
            }

            if (preg_match('/\b(?:java|jdk|jre)[^0-9]*(\d+)/i', (string) $label, $m) && (int) $m[1] === $java) {
                return ['label' => (string) $label, 'image' => (string) $image];
            }
        }

        return null;
    }

    /**
     * Move the server to a different docker image.
     *
     * Deliberately refuses when the egg has no suitable image rather than
     * writing an arbitrary string: an image the node cannot pull produces a
     * server that fails to start with an opaque docker error, which is far
     * worse than being told up front to ask an administrator.
     *
     * @return array{ok: bool, message: ?string}
     */
    public function ensureJavaImage(Server $server, string $gameVersion): array
    {
        $java = $this->requiredJava($gameVersion);

        if (! $java) {
            return ['ok' => true, 'message' => null];
        }

        $match = $this->imageForJava($server, $java);

        if (! $match) {
            return [
                'ok' => false,
                'message' => "Minecraft $gameVersion needs Java $java, but this egg offers no such image. Ask an administrator to add one before switching.",
            ];
        }

        if ($server->image === $match['image']) {
            return ['ok' => true, 'message' => null];
        }

        $old = $server->image;

        $server->forceFill(['image' => $match['image']])->saveOrFail();

        Activity::event('server:startup.image')
            ->property(['old' => $old, 'new' => $match['image']])
            ->log();

        return ['ok' => true, 'message' => "Docker image moved to {$match['label']} for Java $java."];
    }
}
