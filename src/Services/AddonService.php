<?php

namespace FyWolf\MinecraftManager\Services;

use App\Exceptions\Service\Allocation\AutoAllocationNotEnabledException;
use App\Exceptions\Service\Allocation\NoAutoAllocationSpaceAvailableException;
use App\Facades\Activity;
use App\Models\Allocation;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Allocations\FindAssignableAllocationService;
use FyWolf\MinecraftManager\Enums\AddonState;
use FyWolf\MinecraftManager\Integrations\Content\ContentProviderRegistry;
use FyWolf\MinecraftManager\Jobs\InstallAddonJob;
use FyWolf\MinecraftManager\Models\Addon;
use FyWolf\MinecraftManager\Models\ServerAddon;
use FyWolf\MinecraftManager\Support\CapabilityResolver;
use FyWolf\MinecraftManager\Support\DaemonDirs;
use FyWolf\MinecraftManager\Support\PortPatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Granting, provisioning and revoking addons.
 *
 * The commercially interesting part is the port. A port is finite on a node —
 * that is what makes an addon worth charging for — so this records exactly
 * which allocation belongs to which addon. Without that, revoking cannot tell
 * which of a server's ports to reclaim and the capacity being sold leaks away
 * one cancellation at a time.
 */
class AddonService
{
    public function __construct(
        private FindAssignableAllocationService $allocator,
        private DaemonFileRepository $files,
        private ContentInstallService $content,
        private CapabilityResolver $resolver,
        private ContentProviderRegistry $providers,
    ) {}

    /**
     * Record an entitlement and queue the work.
     *
     * Idempotent by (server, addon): billing retries a call it never saw a
     * response to, and a timeout on one that actually succeeded must not
     * provision a second port.
     */
    public function grant(Server $server, Addon $addon, string $source = 'billing', ?string $reference = null): ServerAddon
    {
        $install = DB::transaction(function () use ($server, $addon, $source, $reference) {
            $existing = ServerAddon::where('server_id', $server->id)
                ->where('mc_addon_id', $addon->id)
                ->lockForUpdate()
                ->first();

            if ($existing && in_array($existing->state, [AddonState::Active, AddonState::Installing, AddonState::Pending], true)) {
                return $existing;
            }

            $attributes = [
                'state' => AddonState::Pending,
                'source' => $source,
                'billing_reference' => $reference,
                'error' => null,
                'revoked_at' => null,
            ];

            if ($existing) {
                // Re-granting something suspended: the files are still on disk,
                // so this is only about handing a port back.
                $existing->forceFill($attributes)->save();

                return $existing;
            }

            return ServerAddon::create($attributes + [
                'server_id' => $server->id,
                'mc_addon_id' => $addon->id,
            ]);
        });

        if (in_array($install->state, [AddonState::Pending], true)) {
            InstallAddonJob::dispatch($install->id);
        }

        return $install;
    }

    /**
     * Do the work: claim a port, install the mod, write the port into its config.
     */
    public function provision(ServerAddon $install): void
    {
        $server = $install->server;
        $addon = $install->addon;

        if (! $server || ! $addon) {
            throw new RuntimeException('The server or addon no longer exists.');
        }

        $install->forceFill(['state' => AddonState::Installing, 'error' => null])->save();

        $profile = $this->resolver->for($server);

        if (! $profile) {
            throw new RuntimeException('This server\'s egg has no Minecraft profile, so nothing can be installed.');
        }

        if (! $addon->supports($profile->loader)) {
            throw new RuntimeException($addon->name . ' does not support ' . ($profile->loader?->getLabel() ?? 'this server'));
        }

        // 1. The port first. It is the scarce thing, and failing to get one
        //    should not leave a downloaded mod behind with nowhere to listen.
        if ($addon->needs_port && ! $install->allocation_id) {
            $allocation = $this->claimPort($server);

            $install->forceFill(['allocation_id' => $allocation->id])->save();
        }

        // 2. The mod itself.
        $file = $this->installMod($install, $server, $profile, $addon);

        // 3. Its config. Usually cannot be done yet — see patchPort().
        $patched = $addon->needs_port ? $this->patchPort($install) : true;

        $install->forceFill([
            'state' => AddonState::Active,
            'installed_file' => $file,
            'installed_at' => now(),
            'port_patch_pending' => ! $patched,
        ])->save();

        Activity::event('server:minecraft.addon-install')
            ->property([
                'addon' => $addon->name,
                'port' => $install->allocation?->port,
                'file' => $file,
            ])
            ->log();
    }

    /**
     * Claim an allocation for this server.
     *
     * Also raises allocation_limit so the panel's own network page stays
     * coherent: without it a customer sees "3 of 2 allocations used" and the
     * add button greyed out, which looks like a bug.
     */
    private function claimPort(Server $server): Allocation
    {
        try {
            $allocation = $this->allocator->handle($server);
        } catch (AutoAllocationNotEnabledException) {
            throw new RuntimeException('Automatic allocations are disabled panel-wide. Enable them under Settings, or assign a port to this server by hand.');
        } catch (NoAutoAllocationSpaceAvailableException) {
            throw new RuntimeException('No ports are free in the configured range on this node.');
        }

        if ($server->allocation_limit !== null) {
            $used = $server->allocations()->count();

            if ($used > $server->allocation_limit) {
                $server->forceFill(['allocation_limit' => $used])->saveOrFail();
            }
        }

        return $allocation;
    }

    private function installMod(ServerAddon $install, Server $server, $profile, Addon $addon): ?string
    {
        $provider = $this->providers->get($addon->provider);

        if (! $provider) {
            throw new RuntimeException('The ' . $addon->provider . ' provider is not available.');
        }

        $context = $this->content->contextFor($server, $profile, \FyWolf\MinecraftManager\Enums\ContentType::Mod);

        $version = $provider->latestVersionFor($addon->project_id, $context);

        if (! $version) {
            throw new RuntimeException('No build of ' . $addon->name . ' matches this server\'s version and loader.');
        }

        $directory = $profile->contentDir ?? 'mods';

        $result = $this->content->installFile($server, $version, $directory);

        if (! $result['ok']) {
            throw new RuntimeException('Could not install ' . $addon->name . ': ' . ($result['error'] ?? 'unknown error'));
        }

        return $result['filename'];
    }

    /**
     * Write the allocated port into the mod's own config.
     *
     * Usually impossible at install time: most mods generate their config on
     * first start, so there is nothing to patch until the server has run once
     * with the mod present. That is not a failure — the install is recorded as
     * `port_patch_pending` and the reconciler picks it up later, while the UI
     * shows the port so an impatient customer can set it by hand.
     *
     * @return bool whether the port is now in the config
     */
    public function patchPort(ServerAddon $install): bool
    {
        $addon = $install->addon;
        $allocation = $install->allocation;

        if (! $addon?->needs_port || ! $allocation) {
            return true;
        }

        $spec = (array) ($addon->port_patch ?? []);
        $paths = $addon->portPatchPaths();

        if ($paths === []) {
            return true;
        }

        $server = $install->server;

        foreach ($paths as $path) {
            try {
                $contents = $this->files->setServer($server)->getContent($path, 512 * 1024);
            } catch (Throwable) {
                continue; // Not generated yet, or a path for a different loader.
            }

            $patched = PortPatcher::apply($contents, $spec, (int) $allocation->port);

            if ($patched === null) {
                Log::info('minecraft-manager: addon config had no port key to patch', [
                    'addon' => $addon->key,
                    'path' => $path,
                ]);

                continue;
            }

            if ($patched === $contents) {
                return true; // Already correct.
            }

            try {
                $this->files->setServer($server)->putContent($path, $patched);

                Activity::event('server:minecraft.addon-port-set')
                    ->property(['addon' => $addon->name, 'port' => $allocation->port, 'file' => $path])
                    ->log();

                return true;
            } catch (Throwable $exception) {
                report($exception);

                return false;
            }
        }

        // Nothing to patch yet. Write a stub only if the addon defines one —
        // for formats where a partial file is worse than none, it will not.
        $stub = PortPatcher::stub($spec, (int) $allocation->port);

        if ($stub !== null && $paths !== []) {
            try {
                $target = $paths[0];

                DaemonDirs::ensure($this->files->setServer($server), dirname($target));

                $this->files->setServer($server)->putContent($target, $stub);

                return true;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return false;
    }

    /**
     * Withdraw an entitlement.
     *
     * Releases the port and leaves the files alone: the port is the scarce
     * resource and is reclaimed at once, while BlueMap's rendered map can be
     * gigabytes and hours of CPU that nobody should destroy on a lapsed
     * subscription. Re-granting then costs nothing but handing a port back.
     */
    public function revoke(Server $server, Addon $addon): ?ServerAddon
    {
        $install = ServerAddon::where('server_id', $server->id)
            ->where('mc_addon_id', $addon->id)
            ->first();

        if (! $install || $install->state === AddonState::Suspended) {
            return $install;
        }

        $port = $install->allocation?->port;

        $this->releasePort($install);

        $install->forceFill([
            'state' => AddonState::Suspended,
            'revoked_at' => now(),
            'port_patch_pending' => false,
        ])->save();

        Activity::event('server:minecraft.addon-revoke')
            ->property(['addon' => $addon->name, 'port' => $port])
            ->log();

        return $install;
    }

    /**
     * Hand the allocation back to the node.
     *
     * Never touches the server's primary allocation — that is the server's own
     * address and unassigning it would make the server unreachable.
     */
    public function releasePort(ServerAddon $install): void
    {
        $allocation = $install->allocation;

        if (! $allocation) {
            return;
        }

        $server = $install->server;

        if ($server && $server->allocation_id === $allocation->id) {
            Log::warning('minecraft-manager: refusing to release a server\'s primary allocation', [
                'server' => $server->uuid,
                'allocation' => $allocation->id,
            ]);

            $install->forceFill(['allocation_id' => null])->save();

            return;
        }

        DB::transaction(function () use ($allocation, $install, $server) {
            $allocation->update(['server_id' => null, 'notes' => null]);

            $install->forceFill(['allocation_id' => null])->save();

            if ($server && $server->allocation_limit !== null && $server->allocation_limit > 0) {
                $used = $server->allocations()->count();

                $server->forceFill(['allocation_limit' => max($used, $server->allocation_limit - 1)])->saveOrFail();
            }
        });
    }

    /**
     * Fully remove: release the port and delete the mod jar.
     *
     * Only reached when a customer asks for it explicitly — revocation for
     * non-payment deliberately does not come here.
     */
    public function uninstall(Server $server, Addon $addon): void
    {
        $install = ServerAddon::where('server_id', $server->id)
            ->where('mc_addon_id', $addon->id)
            ->first();

        if (! $install) {
            return;
        }

        $this->releasePort($install);

        $profile = $this->resolver->for($server);

        if ($install->installed_file && $profile?->contentDir) {
            try {
                $this->files->setServer($server)->deleteFiles(
                    DaemonDirs::join($profile->contentDir),
                    [$install->installed_file],
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $install->forceFill([
            'state' => AddonState::Removed,
            'revoked_at' => now(),
            'installed_file' => null,
            'port_patch_pending' => false,
        ])->save();

        Activity::event('server:minecraft.addon-uninstall')
            ->property(['addon' => $addon->name])
            ->log();
    }
}
