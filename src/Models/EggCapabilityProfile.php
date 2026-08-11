<?php

namespace FyWolf\MinecraftManager\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $egg_id
 * @property int $mc_capability_profile_id
 */
class EggCapabilityProfile extends Pivot
{
    protected $table = 'egg_mc_capability_profile';

    public $timestamps = false;

    public $incrementing = false;
}
