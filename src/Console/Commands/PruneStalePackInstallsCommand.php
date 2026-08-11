<?php

namespace FyWolf\MinecraftManager\Console\Commands;

use FyWolf\MinecraftManager\Enums\PackInstallState;
use FyWolf\MinecraftManager\Models\PackInstall;
use Illuminate\Console\Command;

/**
 * Release installs abandoned by a dead worker.
 *
 * Without this, one `queue:restart` during a deploy permanently bricks the
 * feature for any server that was mid-install: the row stays in
 * `downloading_files` forever, and the one-install-per-server guard then
 * refuses every future attempt with "an install is already running".
 *
 * Schedule it hourly.
 */
class PruneStalePackInstallsCommand extends Command
{
    protected $signature = 'minecraft-manager:prune-installs {--minutes= : Consider an install abandoned after this long without progress}';

    protected $description = 'Fail modpack installs abandoned by a stopped queue worker';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?: config('minecraft-manager.packs.stale_after_minutes', 30));

        $cutoff = now()->subMinutes($minutes);

        // updated_at, not started_at: the job touches the row on every file, so
        // a genuinely progressing install of any size stays fresh.
        $stale = PackInstall::query()
            ->active()
            ->where('updated_at', '<', $cutoff)
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stale installs.');

            return self::SUCCESS;
        }

        foreach ($stale as $install) {
            $install->forceFill([
                'error' => "No progress for $minutes minutes; the queue worker probably stopped. The server files were left as they are.",
            ])->save();

            $install->markState(PackInstallState::Failed);

            $this->warn("Failed stale install #{$install->id} for server {$install->server_id} ({$install->pack_name}).");
        }

        $this->info('Released ' . $stale->count() . ' install(s).');

        return self::SUCCESS;
    }
}
