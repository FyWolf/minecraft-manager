<?php

namespace FyWolf\MinecraftManager\Policies;

use App\Policies\DefaultAdminPolicies;

class CapabilityProfilePolicy
{
    use DefaultAdminPolicies;

    protected string $modelName = 'mc_capability_profile';
}
