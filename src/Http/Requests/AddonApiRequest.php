<?php

namespace FyWolf\MinecraftManager\Http\Requests;

use App\Http\Requests\Api\Application\ApplicationApiRequest;
use App\Services\Acl\Api\AdminAcl;
use FyWolf\MinecraftManager\Providers\MinecraftManagerProvider;

/**
 * Base request for every endpoint the billing service calls.
 *
 * Gated on this plugin's own `minecraft` resource rather than the bridge's
 * `billing` one, for the same reason vcenter-vps registers `vps`: these routes
 * consume node capacity by claiming ports, and that should be grantable
 * separately from "provision game servers". A key can hold one without the
 * other.
 *
 * Never issue the billing service a root-admin `pacc_` key; those bypass the
 * application ACL entirely.
 */
abstract class AddonApiRequest extends ApplicationApiRequest
{
    protected ?string $resource = MinecraftManagerProvider::RESOURCE_NAME;

    protected int $permission = AdminAcl::WRITE;
}
