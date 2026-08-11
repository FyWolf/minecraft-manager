<?php

namespace FyWolf\MinecraftManager\Policies;

use App\Policies\DefaultAdminPolicies;

class AddonPolicy
{
    use DefaultAdminPolicies;

    protected string $modelName = 'mc_addon';
}
