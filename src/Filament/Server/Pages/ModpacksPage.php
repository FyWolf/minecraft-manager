<?php

namespace FyWolf\MinecraftManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
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
use FyWolf\MinecraftManager\Enums\PackInstallState;
use FyWolf\MinecraftManager\Integrations\Content\ContentProvider;
use FyWolf\MinecraftManager\Integrations\Content\ContentProviderRegistry;
use FyWolf\MinecraftManager\Integrations\Content\Data\ContentVersion;
use FyWolf\MinecraftManager\Jobs\InstallModpackJob;
use FyWolf\MinecraftManager\Models\PackInstall;
use FyWolf\MinecraftManager\Services\ContentInstallService;
use FyWolf\MinecraftManager\Support\CapabilityResolver;
use FyWolf\MinecraftManager\Support\ResolvedProfile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Browse and install modpacks.
 *
 * An install is a queued job, not a request: a large pack is hundreds of
 * downloads and many minutes of work. The page polls the install row so the
 * user can close the tab and come back.
 */
class ModpacksPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-box-seam';

    protected static ?string $slug = 'mc-modpacks';

    protected static ?int $navigationSort = 25;

    /** Only while an install is running — see getPollingInterval(). */
    protected static ?string $pollingInterval = null;

    public ?string $provider = null;

    private ?ResolvedProfile $profileMemo = null;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        if (! $server instanceof Server) {
            return false;
        }

        $profile = app(CapabilityResolver::class)->for($server);

        return parent::canAccess()
            && $profile?->has(Capability::Modpacks)
            && user()?->can(SubuserPermission::FileRead, $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-manager::strings.nav.modpacks');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileRead, $this->server()), 403);

        $this->provider ??= app(ContentProviderRegistry::class)->default(ContentType::Modpack)?->key();
    }

    /**
     * Poll only while something is actually running, so an idle page costs
     * nothing.
     */
    public function getPollingInterval(): ?string
    {
        return $this->activeInstall() ? '3s' : null;
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

    private function activeProvider(): ?ContentProvider
    {
        return $this->registry()->get((string) $this->provider)
            ?? $this->registry()->default(ContentType::Modpack);
    }

    private function activeInstall(): ?PackInstall
    {
        return PackInstall::where('server_id', $this->server()->id)->active()->latest('id')->first();
    }

    private function lastInstall(): ?PackInstall
    {
        return PackInstall::where('server_id', $this->server()->id)->latest('id')->first();
    }

    private function isRunning(): bool
    {
        return ! $this->server()->retrieveStatus()->isOffline();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components(array_values(array_filter([
            $this->statusSection(),

            Grid::make(3)->schema([
                TextEntry::make('mcm_loader')
                    ->label('Loader')
                    ->state(fn () => $this->profile()->loader?->getLabel() ?? 'unknown')
                    ->badge(),

                TextEntry::make('mcm_version')
                    ->label('Minecraft version')
                    ->state(fn () => app(ContentInstallService::class)->minecraftVersion($this->server(), $this->profile()) ?? 'any')
                    ->badge()
                    ->color('gray'),

                TextEntry::make('mcm_state')
                    ->label('Server')
                    ->state(fn () => $this->isRunning() ? 'Running' : 'Stopped')
                    ->badge()
                    ->color(fn () => $this->isRunning() ? 'warning' : 'success')
                    ->helperText(fn () => $this->isRunning() ? 'Stop the server before installing a pack.' : null),
            ]),

            EmbeddedTable::make(),
        ])));
    }

    private function statusSection(): ?Section
    {
        $install = $this->activeInstall() ?? $this->lastInstall();

        if (! $install) {
            return null;
        }

        return Section::make($install->state->isTerminal()
                ? "Last install — {$install->pack_name}"
                : "Installing {$install->pack_name}")
            ->icon($install->state === PackInstallState::Failed ? 'tabler-alert-triangle' : 'tabler-package')
            ->collapsible($install->state->isTerminal())
            ->collapsed($install->state === PackInstallState::Completed)
            ->schema(array_values(array_filter([
                Grid::make(3)->schema([
                    TextEntry::make('install_state')
                        ->label('Status')
                        ->state($install->state->getLabel())
                        ->badge()
                        ->color($install->state->getColor()),

                    TextEntry::make('install_progress')
                        ->label('Progress')
                        ->state(fn () => $install->progress_total > 0
                            ? "{$install->progress_current} / {$install->progress_total} files ({$install->percent()}%)"
                            : ($install->state->isTerminal() ? '—' : 'preparing…')),

                    TextEntry::make('install_step')
                        ->label('Step')
                        ->state(fn () => $install->current_step ?? '—'),
                ]),

                $install->error
                    ? TextEntry::make('install_error')->label('Error')->state($install->error)->color('danger')
                    : null,

                $install->manualFiles() !== []
                    ? TextEntry::make('install_manual')
                        ->label('Download these by hand')
                        ->state(fn () => count($install->manualFiles()) . ' file(s) whose authors disabled third-party downloads: '
                            . implode(', ', array_slice(array_column($install->manualFiles(), 'path'), 0, 15)))
                        ->color('warning')
                    : null,

                $install->failedFiles() !== []
                    ? TextEntry::make('install_failed')
                        ->label('Failed')
                        ->state(fn () => implode(', ', array_slice(array_column($install->failedFiles(), 'path'), 0, 15)))
                        ->color('danger')
                    : null,

                $install->backup_path
                    ? TextEntry::make('install_backup')
                        ->label('Pre-install archive')
                        ->state($install->backup_path)
                    : null,
            ])))
            ->footerActions(array_values(array_filter([
                $install->state->isTerminal() ? null : $this->cancelAction($install),
            ])));
    }

    private function cancelAction(PackInstall $install): Action
    {
        return Action::make('cancel_install')
            ->label('Cancel')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Cancel this install')
            ->modalDescription('Files already downloaded stay where they are. The server will be left partly installed.')
            ->action(function () use ($install) {
                abort_unless(user()?->can(SubuserPermission::FileCreate, $this->server()), 403);

                $install->forceFill(['error' => 'Cancelled by ' . (user()?->username ?? 'a user') . '.'])->save();
                $install->markState(PackInstallState::Cancelled);

                Activity::event('server:minecraft.modpack-install-cancel')
                    ->property(['pack' => $install->pack_name])
                    ->log();

                Notification::make()->title('Install cancelled')->success()->send();
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int $page) {
                $provider = $this->activeProvider();

                if (! $provider) {
                    return new LengthAwarePaginator([], 0, 20, $page);
                }

                $query = app(ContentInstallService::class)
                    ->contextFor($this->server(), $this->profile(), ContentType::Modpack, $page, $search);

                $result = $provider->search($query);

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
                    ->label('Modpack')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (array $record) => str((string) $record['summary'])->limit(120)),

                TextColumn::make('author')->label('By')->toggleable(),

                TextColumn::make('downloads')->icon('tabler-download')->numeric()->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state, 'UTC')->diffForHumans() : '—')
                    ->toggleable(),
            ])
            ->recordUrl(fn (array $record) => $record['url'], true)
            ->recordActions([$this->installAction()])
            ->headerActions($this->providerSwitchActions())
            ->emptyStateIcon('tabler-box-off')
            ->emptyStateHeading('No modpacks found')
            ->emptyStateDescription('Try a different search term.');
    }

    /**
     * @return array<int, Action>
     */
    private function providerSwitchActions(): array
    {
        $providers = $this->registry()->available(ContentType::Modpack);

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

    private function installAction(): Action
    {
        return Action::make('install_pack')
            ->label('Install')
            ->icon('tabler-download')
            ->modalHeading(fn (array $record) => 'Install ' . $record['title'])
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
            return [TextEntry::make('gone')->label('')->state('That provider is no longer available.')];
        }

        $context = app(ContentInstallService::class)
            ->contextFor($this->server(), $this->profile(), ContentType::Modpack);

        $versions = $provider->versions((string) $record['id'], $context, 10);

        if ($versions === []) {
            return [TextEntry::make('none')->label('')->state('No release of this pack matches this server.')];
        }

        return array_map(
            fn (ContentVersion $version) => Section::make($version->name)
                ->icon($version->channelIcon())
                ->iconColor($version->channelColor())
                ->collapsed(! $version->featured)
                ->description(($version->versionNumber ?? $version->id)
                    . ($version->publishedAt ? ' · ' . Carbon::parse($version->publishedAt, 'UTC')->diffForHumans() : ''))
                ->headerActions([$this->startInstallAction($provider, $version, (string) $record['title'])])
                ->schema([]),
            $versions,
        );
    }

    private function startInstallAction(ContentProvider $provider, ContentVersion $version, string $packName): Action
    {
        return Action::make('start_' . $version->id)
            ->label('Install this version')
            ->icon('tabler-download')
            ->disabled(! $version->isInstallable())
            ->requiresConfirmation()
            ->modalHeading('Install ' . $packName)
            ->modalDescription('Your current mods and config directory are archived first. The install runs in the background — you can close this page. The server must be stopped.')
            ->schema([
                Checkbox::make('understood')
                    ->label('I understand this will change the server\'s mods and configuration')
                    ->accepted()
                    ->required(),
            ])
            ->action(function () use ($provider, $version, $packName) {
                $server = $this->server();

                abort_unless(user()?->can(SubuserPermission::FileCreate, $server), 403);
                abort_unless(user()?->can(SubuserPermission::FileUpdate, $server), 403);

                if ($this->isRunning()) {
                    Notification::make()
                        ->title('Stop the server first')
                        ->body('Writing a pack into a running server leaves it running the old code, and clearing files under it can crash it outright.')
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    // Serialised so two browser tabs cannot both pass the check.
                    // The job's ShouldBeUnique catches whatever slips past.
                    $install = DB::transaction(function () use ($server, $provider, $version, $packName) {
                        $existing = PackInstall::where('server_id', $server->id)
                            ->active()
                            ->lockForUpdate()
                            ->first();

                        if ($existing) {
                            throw new \RuntimeException('An install is already running for this server.');
                        }

                        return PackInstall::create([
                            'server_id' => $server->id,
                            'user_id' => user()?->id,
                            'provider' => $provider->key(),
                            'project_id' => $version->projectId,
                            'version_id' => $version->id,
                            'pack_name' => $packName,
                            'pack_version' => $version->versionNumber ?? $version->id,
                            'state' => PackInstallState::Queued,
                        ]);
                    });
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Could not start the install')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                InstallModpackJob::dispatch($install->id);

                Activity::event('server:minecraft.modpack-install-start')
                    ->property(['pack' => $packName, 'version' => $version->versionNumber ?? $version->id])
                    ->log();

                Notification::make()
                    ->title('Install queued')
                    ->body('Progress appears at the top of this page. You will be notified when it finishes.')
                    ->success()
                    ->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('restore_backup')
                ->label('Restore pre-install archive')
                ->icon('tabler-archive')
                ->color('gray')
                ->visible(fn () => filled($this->lastInstall()?->backup_path))
                ->requiresConfirmation()
                ->modalHeading('Restore the pre-install archive')
                ->modalDescription('Extracts the archive taken before the last install back over the server. Files added since will be overwritten. The server must be stopped.')
                ->action(function (DaemonFileRepository $files) {
                    $server = $this->server();

                    abort_unless(user()?->can(SubuserPermission::FileArchive, $server), 403);
                    abort_unless(user()?->can(SubuserPermission::FileUpdate, $server), 403);

                    if ($this->isRunning()) {
                        Notification::make()->title('Stop the server first')->danger()->send();

                        return;
                    }

                    $path = $this->lastInstall()?->backup_path;

                    if (! $path) {
                        return;
                    }

                    try {
                        $files->setServer($server)->decompressFile('/', $path);

                        Notification::make()->title('Archive restored')->success()->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Could not restore the archive')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
