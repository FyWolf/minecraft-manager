<?php

namespace FyWolf\MinecraftManager\Console\Commands;

use FyWolf\MinecraftManager\Enums\AddonState;
use FyWolf\MinecraftManager\Models\ServerAddon;
use FyWolf\MinecraftManager\Services\AddonService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Finish the half of addon provisioning that cannot be done at install time.
 *
 * Most mods write their config on first start, so at install there is usually
 * no file to put the allocated port into. Those installs are flagged
 * `port_patch_pending`; once the customer has started the server the file
 * exists and this writes the port in.
 *
 * Also releases ports held by installs that died mid-provision, so a crashed
 * worker cannot quietly consume node capacity.
 */
class ReconcileAddonsCommand extends Command
{
    protected $signature = 'minecraft-manager:reconcile-addons
                            {--stale-minutes=30 : Treat an install stuck installing for longer than this as failed}';

    protected $description = 'Write pending addon ports into mod configs and reclaim ports from dead installs';

    public function handle(AddonService $addons): int
    {
        $patched = 0;
        $stillWaiting = 0;

        foreach (ServerAddon::where('port_patch_pending', true)->where('state', AddonState::Active)->get() as $install) {
            try {
                if ($addons->patchPort($install)) {
                    $install->forceFill(['port_patch_pending' => false])->save();
                    $patched++;

                    $this->info("Wrote port {$install->allocation?->port} into {$install->addon?->name} on server {$install->server_id}.");
                } else {
                    $stillWaiting++;
                }
            } catch (Throwable $exception) {
                report($exception);

                $this->warn("Could not patch install #{$install->id}: {$exception->getMessage()}");
            }
        }

        $minutes = (int) $this->option('stale-minutes');
        $released = 0;

        foreach (
            ServerAddon::where('state', AddonState::Installing)
                ->where('updated_at', '<', now()->subMinutes($minutes))
                ->get() as $install
        ) {
            try {
                $addons->releasePort($install);

                $install->forceFill([
                    'state' => AddonState::Failed,
                    'error' => "Provisioning made no progress for $minutes minutes; the queue worker probably stopped.",
                ])->save();

                $released++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->info("Patched $patched config(s); $stillWaiting still waiting for the server to generate one; released $released stalled port(s).");

        return self::SUCCESS;
    }
}
