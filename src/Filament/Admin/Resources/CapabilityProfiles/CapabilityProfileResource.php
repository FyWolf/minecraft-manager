<?php

namespace FyWolf\MinecraftManager\Filament\Admin\Resources\CapabilityProfiles;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FyWolf\MinecraftManager\Enums\Capability;
use FyWolf\MinecraftManager\Enums\ModLoader;
use FyWolf\MinecraftManager\Filament\Admin\Resources\CapabilityProfiles\Pages\ManageCapabilityProfiles;
use FyWolf\MinecraftManager\Models\CapabilityProfile;

class CapabilityProfileResource extends Resource
{
    protected static ?string $model = CapabilityProfile::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-cube';

    protected static ?string $slug = 'minecraft-profiles';

    public static function getNavigationLabel(): string
    {
        return 'Minecraft Profiles';
    }

    public static function getModelLabel(): string
    {
        return 'Minecraft profile';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Minecraft profiles';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Profile')
                    ->searchable(),

                TextColumn::make('loader')
                    ->label('Loader')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? (ModLoader::tryFrom($state)?->getLabel() ?? $state) : '—'),

                TextColumn::make('capabilities')
                    ->label('Grants')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Capability::tryFrom($state)?->getLabel() ?? $state),

                TextColumn::make('content_dir')
                    ->label('Installs into')
                    ->placeholder('no content browser')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('eggs.name')
                    ->label('Eggs')
                    ->badge()
                    ->icon('tabler-eggs')
                    ->placeholder('not mapped to any egg')
                    ->limitList(4)
                    ->expandableLimitedList(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make()->createAnother(false),
            ])
            ->emptyStateIcon('tabler-cube')
            ->emptyStateHeading('No profiles')
            ->emptyStateDescription('Reinstall the plugin to seed the built-in profiles, or create one by hand.');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->helperText('Shown to administrators only.'),

                    Select::make('loader')
                        ->label('Server software')
                        ->options(fn () => collect(ModLoader::cases())->mapWithKeys(fn (ModLoader $l) => [$l->value => $l->getLabel()])->all())
                        ->searchable()
                        ->helperText('Decides which search filters are sent to Modrinth and CurseForge.'),
                ]),

            Section::make('What this grants')
                ->schema([
                    CheckboxList::make('capabilities')
                        ->label('')
                        ->options(fn () => collect(Capability::cases())->mapWithKeys(fn (Capability $c) => [$c->value => $c->getLabel()])->all())
                        ->descriptions(fn () => collect(Capability::cases())->mapWithKeys(fn (Capability $c) => [$c->value => $c->getDescription()])->all())
                        ->columns(2)
                        ->bulkToggleable(),
                ]),

            Section::make('Paths')
                ->columns(3)
                ->schema([
                    TextInput::make('content_dir')
                        ->label('Mods / plugins directory')
                        ->placeholder('mods')
                        ->helperText('Relative to the server root. Leave blank for software with no mod support.'),

                    TextInput::make('worlds_dir')
                        ->label('Worlds directory')
                        ->placeholder('/')
                        ->helperText('Leave blank for a proxy, which has no worlds.'),

                    Select::make('dimension_layout')
                        ->label('Dimension layout')
                        ->required()
                        ->selectablePlaceholder(false)
                        ->options([
                            'vanilla' => 'Nested — world/DIM-1 (Vanilla, Fabric, Forge)',
                            'bukkit' => 'Sibling folders — world_nether, world_the_end (Paper, Spigot)',
                        ])
                        ->helperText('Sibling dimensions must be archived and deleted together with their base world.'),
                ]),

            Section::make('Version switching')
                ->columns(2)
                ->schema([
                    Select::make('version_provider')
                        ->label('Jar source')
                        ->options([
                            'vanilla' => 'Mojang (Vanilla)',
                            'paper' => 'PaperMC',
                            'purpur' => 'PurpurMC',
                            'folia' => 'Folia',
                            'fabric' => 'Fabric',
                            'velocity' => 'Velocity',
                            'waterfall' => 'Waterfall',
                        ])
                        ->placeholder('None — reinstall instead')
                        ->helperText('Leave empty for software distributed as an installer rather than a runnable jar (Forge, NeoForge). Those change version by updating the startup variable and reinstalling.'),

                    TextInput::make('jar_path')
                        ->label('Jar filename')
                        ->placeholder('server.jar')
                        ->helperText("Leave blank to use the server's own SERVER_JARFILE variable."),

                    TagsInput::make('mc_version_variables')
                        ->label('Minecraft version variables')
                        ->placeholder('MINECRAFT_VERSION')
                        ->helperText('Startup variable names to try, in order. The first one the server actually has wins.'),

                    TagsInput::make('loader_version_variables')
                        ->label('Loader / build variables')
                        ->placeholder('BUILD_NUMBER'),
                ]),

            Section::make('Eggs')
                ->description('Servers using these eggs get this profile. An egg can belong to only one profile.')
                ->schema([
                    Select::make('eggs')
                        ->label('')
                        ->relationship('eggs', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCapabilityProfiles::route('/'),
        ];
    }
}
