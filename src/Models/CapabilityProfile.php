<?php

namespace FyWolf\MinecraftManager\Models;

use App\Models\Egg;
use FyWolf\MinecraftManager\Enums\Capability;
use FyWolf\MinecraftManager\Enums\ModLoader;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * What a family of eggs is allowed to do, and where its files live.
 *
 * @property int $id
 * @property string $name
 * @property ?string $loader
 * @property array<int, string> $capabilities
 * @property ?string $content_dir
 * @property ?string $worlds_dir
 * @property string $dimension_layout
 * @property ?string $version_provider
 * @property ?string $jar_path
 * @property ?array<int, string> $mc_version_variables
 * @property ?array<int, string> $loader_version_variables
 * @property ?array<int, string> $config_files
 * @property Collection<int, Egg> $eggs
 * @property ?int $eggs_count
 */
class CapabilityProfile extends Model
{
    protected $table = 'mc_capability_profiles';

    protected $fillable = [
        'name',
        'loader',
        'capabilities',
        'content_dir',
        'worlds_dir',
        'dimension_layout',
        'version_provider',
        'jar_path',
        'mc_version_variables',
        'loader_version_variables',
        'config_files',
    ];

    protected $attributes = [
        'dimension_layout' => 'vanilla',
        'worlds_dir' => '/',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'mc_version_variables' => 'array',
            'loader_version_variables' => 'array',
            'config_files' => 'array',
        ];
    }

    /**
     * The pivot table has to be named explicitly: Laravel would derive
     * `capability_profile_egg` from the two model names, but the table is
     * `egg_mc_capability_profile` so it reads sensibly beside the panel's own
     * `egg_game_query`.
     */
    public function eggs(): BelongsToMany
    {
        return $this->belongsToMany(Egg::class, 'egg_mc_capability_profile', 'mc_capability_profile_id', 'egg_id');
    }

    public function loader(): ?ModLoader
    {
        return $this->loader ? ModLoader::tryFrom($this->loader) : null;
    }

    public function has(Capability $capability): bool
    {
        return in_array($capability->value, $this->capabilities ?? [], true);
    }

    public function hasAny(Capability ...$capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->has($capability)) {
                return true;
            }
        }

        return false;
    }
}
