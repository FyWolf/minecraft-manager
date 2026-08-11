<?php

namespace FyWolf\MinecraftManager\Http\Requests;

use App\Services\Acl\Api\AdminAcl;

class ReadAddonRequest extends AddonApiRequest
{
    protected int $permission = AdminAcl::READ;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
