<?php

namespace FyWolf\MinecraftManager\Filament\Admin\Resources\Addons\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use FyWolf\MinecraftManager\Filament\Admin\Resources\Addons\AddonResource;

class ManageAddons extends ManageRecords
{
    protected static string $resource = AddonResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->createAnother(false)];
    }
}
