<?php

namespace FyWolf\MinecraftManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use FyWolf\MinecraftManager\Enums\Capability;
use FyWolf\MinecraftManager\Enums\ContentType;
use FyWolf\MinecraftManager\Integrations\Content\ContentProvider;
use FyWolf\MinecraftManager\Integrations\Content\ContentProviderRegistry;
use FyWolf\MinecraftManager\Integrations\Content\Data\ContentVersion;
use FyWolf\MinecraftManager\Services\ContentInstallService;
use FyWolf\MinecraftManager\Support\CapabilityResolver;
use FyWolf\MinecraftManager\Support\ResolvedProfile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Browse and install mods or plugins.
 *
 * One page class serves every loader. What it is called, which project type it
 * searches for and which directory it installs into all come from the server's
 * capability profile, so a Paper server sees "Plugins" installing to `plugins/`
 * and a Fabric server sees "Mods" installing to `mods/` — while a Vanilla
 * server, whose profile grants neither capability, never renders it at all.
 *
 * Providers are shown as tabs rather than merged into one list. Merging would
 * mean inventing pagination arithmetic across two incompatible relevance
 * models, and showing most popular mods twice, since nearly all of them are
 * published to both.
 */
class ContentBrowserPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-package';

    protected static ?string $slug = 'mc-content';

    protected static ?int $navigationSort = 23;

    public ?string $provider = null;

    public ?string $contentType = null;

    private ?ResolvedProfile $profileMemo = null;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        if (! $server instanceof Server) {
            return false;
        }

        $profile = app(CapabilityResolver::class)->for($server);

        return parent::canAccess()
            && $profile?->hasAny(Capability::Mods, Capability::Plugins)
            && user()?->can(SubuserPermission::FileRead, $server);
    }

    public static function getNavigationLabel(): string
    {
        $server = Filament::getTenant();
        $profile = $server instanceof Server ? app(CapabilityResolver::class)->for($server) : null;

        if (! $profile) {
            return trans('minecraft-manager::strings.nav.content.mods');
        }

        $mods = $profile->has(Capability::Mods);
        $plugins = $profile->has(Capability::Plugins);

        return match (true) {
            $mods && $plugins => trans('minecraft-manager::strings.nav.content.both'),
            $plugins => trans('minecraft-manager::strings.nav.content.plugins'),
            default => trans('minecraft-manager::strings.nav.content.mods'),
        };
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileRead, $this->server()), 403);

        $this->contentType ??= ($this->availableTypes()[0] ?? ContentType::Mod)->value;
        $this->provider ??= $this->registry()->default($this->type())?->key();
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    private function profile(): ResolvedProfile
    {
        return $this->profileMemo ??= app(CapabilityResolver::class)->for($this->server());
    }

    private function registry(): ContentProviderRegistry
    {
        return app(ContentProviderRegistry::class);
    }

    /**
     * @return array<int, ContentType>
     */
    private function availableTypes(): array
    {
        return $this->profile()->browsableContentTypes();
    }

    private function type(): ContentType
    {
        return ContentType::tryFrom((string) $this->contentType)
            ?? $this->availableTypes()[0]
            ?? ContentType::Mod;
    }

    private function activeProvider(): ?ContentProvider
    {
        return $this->registry()->get((string) $this->provider)
            ?? $this->registry()->default($this->type());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(4)->schema([
                TextEntry::make('mcm_loader')
                    ->label('Loader')
                    ->state(fn () => $this->profile()->loader?->getLabel() ?? 'unknown')
                    ->badge(),

                TextEntry::make('mcm_version')
                    ->label('Minecraft version')
                    ->state(fn (ContentInstallService $content) => $content->minecraftVersion($this->server(), $this->profile()) ?? 'any')
                    ->badge()
                    ->color('gray')
                    ->helperText(fn (ContentInstallService $content) => $content->minecraftVersion($this->server(), $this->profile())
                        ? null
                        : 'No version variable set, so results are not filtered by version.'),

                TextEntry::make('mcm_target')
                    ->label('Installs into')
                    ->state(fn () => $this->profile()->contentDir ?? '—')
                    ->badge()
                    ->color('gray'),

                TextEntry::make('mcm_installed')
                    ->label('Installed')
                    ->state(fn (ContentInstallService $content) => $content->installedCount($this->server(), $this->profile()) ?? '—')
                    ->badge(),
            ]),

            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // Only the named table arguments are declared here. Filament's
            // evaluator does inject container bindings elsewhere, but mixing
            // both in records() is not a pattern any reference plugin uses, so
            // services are resolved explicitly rather than relied upon.
            ->records(function (?string $search, int $page) {
                $provider = $this->activeProvider();

                if (! $provider) {
                    return new LengthAwarePaginator([], 0, 20, $page);
                }

                $content = app(ContentInstallService::class);

                $query = $content->contextFor($this->server(), $this->profile(), $this->type(), $page, $search);

                $result = $provider->search($query);

                // A degraded result is not the same as an empty one, and saying
                // so is the difference between "this mod does not exist" and
                // "we could not reach the index".
                if ($result->degraded && $result->message) {
                    Notification::make()
                        ->title($provider->label() . ' could not be searched')
                        ->body($result->message)
                        ->warning()
                        ->send();
                }

                $records = [];

                foreach ($result->items as $project) {
                    $records[$project->providerKey . ':' . $project->id] = $project->toArray();
                }

                return new LengthAwarePaginator($records, $result->total, $result->perPage, $page);
            })
            ->paginated([20])
            ->searchable()
            ->deferLoading()
            ->columns([
                ImageColumn::make('icon_url')->label('')->circular(),

                TextColumn::make('title')
                    ->label('Name')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (array $record) => str((string) $record['summary'])->limit(120)),

                TextColumn::make('author')->label('By')->toggleable(),

                TextColumn::make('downloads')
                    ->icon('tabler-download')
                    ->numeric()
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state, 'UTC')->diffForHumans() : '—')
                    ->toggleable(),
            ])
            ->recordUrl(fn (array $record) => $record['url'], true)
            ->recordActions([$this->versionsAction()])
            ->headerActions(array_values(array_filter([
                ...$this->providerSwitchActions(),
                ...$this->typeSwitchActions(),
                Action::make('open_folder')
                    ->label('File manager')
                    ->icon('tabler-folder-open')
                    ->color('gray')
                    ->visible(fn () => filled($this->profile()->contentDir))
                    ->url(fn () => ListFiles::getUrl(['path' => (string) $this->profile()->contentDir]), true),
            ])))
            ->emptyStateIcon('tabler-package-off')
            ->emptyStateHeading('Nothing found')
            ->emptyStateDescription(fn () => $this->activeProvider()
                ? 'Try a different search term, or switch provider.'
                : 'No content provider is available. CurseForge needs an API key in the plugin settings.');
    }

    /**
     * @return array<int, Action>
     */
    private function providerSwitchActions(): array
    {
        $providers = $this->registry()->available($this->type());

        // One provider needs no switcher.
        if (count($providers) < 2) {
            return [];
        }

        return array_values(array_map(
            fn (ContentProvider $provider) => Action::make('provider_' . $provider->key())
                ->label($provider->label())
                ->badge()
                ->color(fn () => $this->activeProvider()?->key() === $provider->key() ? 'primary' : 'gray')
                ->action(function () use ($provider) {
                    $this->provider = $provider->key();
                    $this->resetTable();
                }),
            $providers,
        ));
    }

    /**
     * Only relevant for hybrid servers (Mohist and friends) that genuinely
     * accept both mods and plugins.
     *
     * @return array<int, Action>
     */
    private function typeSwitchActions(): array
    {
        $types = $this->availableTypes();

        if (count($types) < 2) {
            return [];
        }

        return array_map(
            fn (ContentType $type) => Action::make('type_' . $type->value)
                ->label($type->getLabel())
                ->badge()
                ->color(fn () => $this->type() === $type ? 'primary' : 'gray')
                ->action(function () use ($type) {
                    $this->contentType = $type->value;
                    $this->resetTable();
                }),
            $types,
        );
    }

    /**
     * The version picker: a modal built at render time from the API response,
     * one collapsible section per version.
     */
    private function versionsAction(): Action
    {
        return Action::make('versions')
            ->label('Install')
            ->icon('tabler-download')
            ->modalHeading(fn (array $record) => 'Install ' . $record['title'])
            ->modalDescription(fn (array $record) => $record['distribution_allowed']
                ? 'Choose a version. Required dependencies are installed alongside it unless you turn that off.'
                : 'The author of this project has disabled third-party downloads, so it cannot be installed automatically.')
            // The section header buttons are the only way to act, so the modal's
            // own submit button would do nothing.
            ->modalSubmitAction(false)
            ->schema(fn (array $record) => $this->versionSections($record));
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<int, mixed>
     */
    private function versionSections(array $record): array
    {
        $provider = $this->registry()->get((string) $record['provider']);

        if (! $provider) {
            return [TextEntry::make('unavailable')->label('')->state('That provider is no longer available.')];
        }

        if (! $record['distribution_allowed']) {
            return [
                TextEntry::make('blocked')
                    ->label('')
                    ->state('CurseForge does not permit this project to be downloaded through the API. Download it from the project page and upload it through the file manager.')
                    ->helperText($record['url']),
            ];
        }

        $context = app(ContentInstallService::class)->contextFor($this->server(), $this->profile(), $this->type());

        $versions = $provider->versions((string) $record['id'], $context, 10);

        if ($versions === []) {
            return [
                TextEntry::make('none')
                    ->label('')
                    ->state(sprintf(
                        'No release of this project matches %s on %s.',
                        $this->profile()->loader?->getLabel() ?? 'this loader',
                        $context->gameVersion ?? 'any version',
                    )),
            ];
        }

        return array_map(
            fn (ContentVersion $version) => Section::make($version->name)
                ->icon($version->channelIcon())
                ->iconColor($version->channelColor())
                ->collapsed(! $version->featured)
                ->description(trim(sprintf(
                    '%s · %s%s',
                    $version->versionNumber ?? $version->id,
                    $version->publishedAt ? Carbon::parse($version->publishedAt, 'UTC')->diffForHumans() : 'unknown date',
                    $version->requiredDependencies() !== []
                        ? ' · ' . count($version->requiredDependencies()) . ' required dependency(ies)'
                        : '',
                )))
                ->headerActions([$this->installAction($provider, $version)])
                ->schema(array_values(array_filter([
                    $version->changelog
                        ? TextEntry::make('changelog_' . $version->id)
                            ->label('Changelog')
                            ->state(str($version->changelog)->limit(1500))
                            ->markdown()
                        : null,

                    $version->isInstallable()
                        ? null
                        : TextEntry::make('blocked_' . $version->id)
                            ->label('')
                            ->state('This file cannot be downloaded through the API.')
                            ->color('danger'),
                ]))),
            $versions,
        );
    }

    private function installAction(ContentProvider $provider, ContentVersion $version): Action
    {
        return Action::make('install_' . $version->id)
            ->label('Install this version')
            ->icon('tabler-download')
            ->disabled(! $version->isInstallable())
            ->schema($version->requiredDependencies() === [] ? [] : [
                Checkbox::make('with_dependencies')
                    ->label('Also install the ' . count($version->requiredDependencies()) . ' required dependency(ies)')
                    ->default(true)
                    ->helperText('Most mods will not load without theirs.'),
            ])
            ->action(function (array $data = []) use ($provider, $version) {
                $server = $this->server();

                abort_unless(user()?->can(SubuserPermission::FileCreate, $server), 403);

                $result = app(ContentInstallService::class)->install(
                    server: $server,
                    profile: $this->profile(),
                    provider: $provider,
                    version: $version,
                    type: $this->type(),
                    withDependencies: (bool) ($data['with_dependencies'] ?? true),
                );

                if ($result['installed'] === []) {
                    Notification::make()
                        ->title('Nothing was installed')
                        ->body($result['failed'][0]['error'] ?? 'Unknown error.')
                        ->danger()
                        ->send();

                    return;
                }

                $body = implode(', ', $result['installed']);

                if ($result['failed'] !== []) {
                    $body .= ' — but ' . count($result['failed']) . ' failed: '
                        . implode('; ', array_map(fn (array $f) => $f['name'] . ' (' . $f['error'] . ')', $result['failed']));
                }

                Notification::make()
                    ->title('Installed ' . count($result['installed']) . ' file(s)')
                    ->body($body . '. Restart the server to load them.')
                    ->{$result['failed'] === [] ? 'success' : 'warning'}()
                    ->send();
            });
    }
}
