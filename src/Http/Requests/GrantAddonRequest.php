<?php

namespace FyWolf\MinecraftManager\Http\Requests;

class GrantAddonRequest extends AddonApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The server's uuid, not the panel's numeric id: it is the
            // identifier both sides had before anything was provisioned, and it
            // survives a panel restore.
            'server' => ['required', 'string'],

            // The addon's stable key, e.g. "bluemap".
            'addon' => ['required', 'string'],

            // Billing's own identifier for whatever pays for this, so the two
            // sides can reconcile without sharing a database.
            'reference' => ['nullable', 'string', 'max:191'],
        ];
    }
}
