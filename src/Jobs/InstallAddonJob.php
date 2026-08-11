<?php

namespace FyWolf\MinecraftManager\Jobs;

use App\Facades\Activity;
use Filament\Notifications\Notification;
use FyWolf\MinecraftManager\Enums\AddonState;
use FyWolf\MinecraftManager\Models\ServerAddon;
use FyWolf\MinecraftManager\Services\AddonService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Provision one addon.
 *
 * Queued because it claims a port and downloads a mod, and because billing is
 * waiting on the HTTP response that dispatched it — a grant should be accepted
 * in milliseconds, not held open for a download.
 */
class InstallAddonJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    /**
     * One attempt. A retry would re-enter provision() with a port already
     * claimed and a mod already downloaded; re-granting is a deliberate action,
     * not something the queue should decide.
     */
    public int $tries = 1;

    public function __construct(public int $installId) {}

    public function uniqueId(): string
    {
        return 'mcm-addon:' . $this->installId;
    }

    public int $uniqueFor = 900;

    public function handle(AddonService $addons): void
    {
        $install = ServerAddon::find($this->installId);

        if (! $install || $install->state === AddonState::Active) {
            return;
        }

        try {
            $addons->provision($install);

            $this->notify($install, 'Addon installed', sprintf(
                '%s is installed%s. Restart the server to load it.',
                $install->addon?->name ?? 'The addon',
                $install->allocation
                    ? ' and listening on port ' . $install->allocation->port
                    : '',
            ));
        } catch (Throwable $exception) {
            report($exception);

            $this->fail_($install, $exception->getMessage());
        }
    }

    public function failed(Throwable $exception): void
    {
        $install = ServerAddon::find($this->installId);

        if ($install && ! $install->state->isTerminal()) {
            $this->fail_($install, $exception->getMessage());
        }
    }

    private function fail_(ServerAddon $install, string $message): void
    {
        // Hand the port back rather than leave a failed install holding one:
        // that is the resource being sold, and a stuck record would quietly
        // consume node capacity forever.
        try {
            app(AddonService::class)->releasePort($install);
        } catch (Throwable $exception) {
            report($exception);
        }

        $install->forceFill(['state' => AddonState::Failed, 'error' => $message])->save();

        Activity::event('server:minecraft.addon-failed')
            ->property(['addon' => $install->addon?->name, 'error' => $message])
            ->log();

        $this->notify($install, 'Addon install failed', $message, danger: true);
    }

    private function notify(ServerAddon $install, string $title, string $body, bool $danger = false): void
    {
        $user = $install->server?->owner;

        if (! $user) {
            return;
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->{$danger ? 'danger' : 'success'}()
            ->sendToDatabase($user);
    }
}
