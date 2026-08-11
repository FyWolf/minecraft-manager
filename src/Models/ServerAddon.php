<?php

namespace FyWolf\MinecraftManager\Models;

use App\Models\Allocation;
use App\Models\Server;
use FyWolf\MinecraftManager\Enums\AddonState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One addon on one server.
 *
 * @property int $id
 * @property int $server_id
 * @property int $mc_addon_id
 * @property AddonState $state
 * @property ?int $allocation_id
 * @property string $source
 * @property ?string $billing_reference
 * @property ?string $installed_file
 * @property ?string $error
 * @property bool $port_patch_pending
 * @property ?\Illuminate\Support\Carbon $installed_at
 * @property ?\Illuminate\Support\Carbon $revoked_at
 * @property Addon $addon
 */
class ServerAddon extends Model
{
    protected $table = 'mc_server_addons';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => AddonState::class,
            'port_patch_pending' => 'boolean',
            'installed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class, 'mc_addon_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class);
    }

    /**
     * The address a player actually types, for addons that expose one.
     */
    public function endpoint(): ?string
    {
        $allocation = $this->allocation;

        if (! $allocation) {
            return null;
        }

        $host = filled($allocation->alias) ? $allocation->alias : $allocation->ip;

        return $host . ':' . $allocation->port;
    }

    public function isActive(): bool
    {
        return $this->state === AddonState::Active;
    }
}
