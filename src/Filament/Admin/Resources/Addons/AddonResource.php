<?php

namespace FyWolf\MinecraftManager\Filament\Admin\Resources\Addons;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FyWolf\MinecraftManager\Enums\ModLoader;
use FyWolf\MinecraftManager\Filament\Admin\Resources\Addons\Pages\ManageAddons;
use FyWolf\MinecraftManager\Models\Addon;

class AddonResource extends Resource
{
    protected static ?string $model = Addon::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-puzzle';

    protected static ?string $slug = 'minecraft-addons';

    public static function getNavigationLabel(): string
    {
        return 'Minecraft Addons';
    }

    public static function getModelLabel(): string
    {
        return 'addon';
    }

    public static function getPluralModelLabel(): string
    {
        return 'addons';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label('Addon')->searchable(),

                TextColumn::make('key')->label('Key')->badge()->color('gray')
                    ->tooltip('What billing refers to. Never rename it.'),

                IconColumn::make('free')
                    ->label('Free')
                    ->boolean()
                    ->tooltip('Free addons install themselves; paid ones wait for billing to grant them.'),

                TextColumn::make('port_protocol')
                    ->label('Port')
                    ->badge()
                    ->formatStateUsing(fn ($state, Addon $record) => $record->needs_port ? strtoupper($state) : 'none')
                    ->color(fn (Addon $record) => $record->needs_port ? 'warning' : 'gray'),

                TextColumn::make('installs_count')
                    ->label('In use')
                    ->counts('installs')
                    ->tooltip('How many servers hold this addon — and therefore how many ports it is consuming.'),

                IconColumn::make('enabled')->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([CreateAction::make()->createAnother(false)])
            ->emptyStateIcon('tabler-puzzle')
            ->emptyStateHeading('No addons')
            ->emptyStateDescription('Reinstall the plugin to seed the built-in catalogue.');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required(),

                    TextInput::make('key')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->helperText('The identifier billing sends. Renaming it breaks every existing grant.'),

                    Textarea::make('description')->columnSpanFull()->rows(2),

                    TextInput::make('icon')->placeholder('tabler-map'),

                    TextInput::make('sort')->numeric()->default(0),
                ]),

            Section::make('Where the mod comes from')
                ->columns(3)
                ->schema([
                    Select::make('provider')
                        ->required()
                        ->selectablePlaceholder(false)
                        ->options(['modrinth' => 'Modrinth', 'curseforge' => 'CurseForge'])
                        ->default('modrinth'),

                    TextInput::make('project_id')
                        ->required()
                        ->helperText('Modrinth project id or slug; CurseForge numeric id.'),

                    TagsInput::make('loaders')
                        ->helperText('Leave empty for any. A server whose loader is not listed never sees this addon.')
                        ->suggestions(fn () => array_column(ModLoader::cases(), 'value')),
                ]),

            Section::make('Commercial')
                ->columns(2)
                ->schema([
                    Toggle::make('free')
                        ->label('Free — customers can install it themselves')
                        ->helperText('Off means the addon waits for billing to grant it. It still claims a port, so "free" is not "costs you nothing".'),

                    Toggle::make('enabled')->default(true),

                    TextInput::make('billing_sku')
                        ->label('Billing SKU')
                        ->helperText('Informational on this side — what billing sells to enable it.')
                        ->columnSpanFull(),
                ]),

            Section::make('Port')
                ->description('The scarce resource. Each addon that needs one permanently consumes a port on the node, which is what makes it worth charging for.')
                ->columns(2)
                ->schema([
                    Toggle::make('needs_port')->label('Claims an additional port')->live(),

                    Select::make('port_protocol')
                        ->options(['tcp' => 'TCP', 'udp' => 'UDP'])
                        ->default('tcp')
                        ->selectablePlaceholder(false)
                        ->visible(fn ($get) => (bool) $get('needs_port'))
                        ->helperText('Web maps are TCP; voice chat and Bedrock are UDP. A firewall rule for one will not serve the other.'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAddons::route('/')];
    }
}
