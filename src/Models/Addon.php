<?php

namespace FyWolf\MinecraftManager\Models;

use FyWolf\MinecraftManager\Enums\ModLoader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable extra: a mod plus what it takes to make it work.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property ?string $description
 * @property ?string $icon
 * @property string $provider
 * @property string $project_id
 * @property ?array<int, string> $loaders
 * @property bool $needs_port
 * @property string $port_protocol
 * @property ?array<string, mixed> $port_patch
 * @property bool $free
 * @property ?string $billing_sku
 * @property bool $enabled
 * @property int $sort
 */
class Addon extends Model
{
    protected $table = 'mc_addons';

    protected $fillable = [
        'key', 'name', 'description', 'icon', 'provider', 'project_id', 'loaders',
        'needs_port', 'port_protocol', 'port_patch', 'free', 'billing_sku', 'enabled', 'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'loaders' => 'array',
            'port_patch' => 'array',
            'needs_port' => 'boolean',
            'free' => 'boolean',
            'enabled' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function installs(): HasMany
    {
        return $this->hasMany(ServerAddon::class, 'mc_addon_id');
    }

    /**
     * An empty loader list means "any" — some addons are loader-agnostic.
     */
    public function supports(?ModLoader $loader): bool
    {
        $loaders = $this->loaders ?? [];

        if ($loaders === []) {
            return true;
        }

        return $loader !== null && in_array($loader->value, $loaders, true);
    }

    /**
     * Candidate config paths, in the order they should be tried.
     *
     * A list because the same mod lands in different places per loader: Geyser
     * is under plugins/ on Paper and config/ on Fabric.
     *
     * @return array<int, string>
     */
    public function portPatchPaths(): array
    {
        return array_values(array_filter((array) ($this->port_patch['paths'] ?? [])));
    }

    public function portPatchFormat(): string
    {
        return (string) ($this->port_patch['format'] ?? 'line');
    }
}
