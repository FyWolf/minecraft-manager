<?php

namespace FyWolf\MinecraftManager\Models;

use App\Models\Server;
use App\Models\User;
use FyWolf\MinecraftManager\Enums\PackInstallState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One modpack installation, running or finished.
 *
 * @property int $id
 * @property int $server_id
 * @property ?int $user_id
 * @property string $provider
 * @property string $project_id
 * @property string $version_id
 * @property string $pack_name
 * @property ?string $pack_version
 * @property ?string $loader
 * @property ?string $loader_version
 * @property ?string $mc_version
 * @property PackInstallState $state
 * @property int $progress_current
 * @property int $progress_total
 * @property ?string $current_step
 * @property ?string $error
 * @property ?array<int, array<string, mixed>> $log
 * @property ?string $backup_path
 * @property ?\Illuminate\Support\Carbon $started_at
 * @property ?\Illuminate\Support\Carbon $finished_at
 */
class PackInstall extends Model
{
    protected $table = 'mc_pack_installs';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => PackInstallState::class,
            'log' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'progress_current' => 'integer',
            'progress_total' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<PackInstall> $query
     */
    public function scopeActive($query): void
    {
        $query->whereNotIn('state', [
            PackInstallState::Completed->value,
            PackInstallState::Failed->value,
            PackInstallState::Cancelled->value,
        ]);
    }

    public function percent(): int
    {
        if ($this->state === PackInstallState::Completed) {
            return 100;
        }

        if ($this->progress_total <= 0) {
            return 0;
        }

        return (int) min(99, round($this->progress_current / $this->progress_total * 100));
    }

    /**
     * Files the user has to fetch by hand, because their author disabled
     * third-party distribution.
     *
     * @return array<int, array<string, mixed>>
     */
    public function manualFiles(): array
    {
        return array_values(array_filter(
            $this->log ?? [],
            fn (array $entry) => ($entry['status'] ?? null) === 'manual',
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function failedFiles(): array
    {
        return array_values(array_filter(
            $this->log ?? [],
            fn (array $entry) => ($entry['status'] ?? null) === 'failed',
        ));
    }

    public function markState(PackInstallState $state, ?string $step = null): void
    {
        $this->state = $state;

        if ($step !== null) {
            $this->current_step = $step;
        }

        if ($state->isTerminal() && ! $this->finished_at) {
            $this->finished_at = now();
        }

        $this->save();
    }
}
