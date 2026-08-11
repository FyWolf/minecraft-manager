<?php

namespace FyWolf\MinecraftManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use FyWolf\MinecraftManager\Enums\Capability;
use FyWolf\MinecraftManager\Services\WorldService;
use FyWolf\MinecraftManager\Support\CapabilityResolver;
use FyWolf\MinecraftManager\Support\ResolvedProfile;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Manage a server's worlds.
 *
 * Three of the four operations require the server to be stopped, and that is
 * not a formality:
 *
 *  - Switching the active world edits server.properties, and Minecraft rewrites
 *    that file wholesale when it shuts down. Editing it live means the change is
 *    silently discarded on the next stop, which looks exactly like the panel
 *    being broken.
 *  - Restoring decompresses over a directory the server may hold open.
 *  - Deleting removes region files a running server is mid-write into.
 *
 * Archiving is allowed while running but warns, because a compressed copy of a
 * live world catches region files mid-write and can restore to a corrupt state.
 */
class WorldsPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-world';

    protected static string|\UnitEnum|null $navigationGroup = 'Minecraft';

    protected static ?string $slug = 'mc-worlds';

    protected static ?int $navigationSort = 21;

    private ?ResolvedProfile $profileMemo = null;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        if (! $server instanceof Server) {
            return false;
        }

        $profile = app(CapabilityResolver::class)->for($server);

        return parent::canAccess()
            && $profile?->has(Capability::Worlds)
            && user()?->can(SubuserPermission::FileRead, $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-manager::strings.nav.worlds');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileRead, $this->server()), 403);
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

    private function worldsRoot(): string
    {
        return $this->profile()->worldsDir ?: '/';
    }

    private function isRunning(): bool
    {
        return ! $this->server()->retrieveStatus()->isOffline();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                TextEntry::make('mcm_active_world')
                    ->label('Active world')
                    ->state(fn (WorldService $worlds) => $worlds->activeWorldName($this->server(), $this->profile()) ?? 'unknown')
                    ->badge(),

                TextEntry::make('mcm_layout')
                    ->label('Dimension layout')
                    ->state(fn () => $this->profile()->hasSiblingDimensions()
                        ? 'Sibling folders (Paper/Spigot)'
                        : 'Nested (Vanilla/Fabric/Forge)')
                    ->badge()
                    ->color('gray'),

                TextEntry::make('mcm_state')
                    ->label('Server')
                    ->state(fn () => $this->isRunning() ? 'Running' : 'Stopped')
                    ->badge()
                    ->color(fn () => $this->isRunning() ? 'warning' : 'success')
                    ->helperText(fn () => $this->isRunning()
                        ? 'Stop the server to switch, restore or delete a world.'
                        : null),
            ]),

            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function () {
                $worlds = app(WorldService::class);

                try {
                    return collect($worlds->list($this->server(), $this->profile()))
                        ->keyBy('name')
                        ->all();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Could not read the server files')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return [];
                }
            })
            ->columns([
                TextColumn::make('name')
                    ->label('World')
                    ->weight('bold')
                    ->description(fn (array $record) => $record['dimensions']
                        ? 'with ' . implode(', ', $record['dimensions'])
                        : null),

                TextColumn::make('is_active')
                    ->label('')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'active' : '')
                    ->color('success'),

                TextColumn::make('size')
                    ->label('Size (approx.)')
                    ->state(fn (array $record, WorldService $worlds) => $worlds->approximateSize($this->server(), $this->worldsRoot(), $record['folders']))
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : $this->humanBytes((int) $state))
                    ->tooltip('Estimated from the world\'s region files; the daemon cannot report a recursive directory size.')
                    ->toggleable(),

                TextColumn::make('modified_at')
                    ->label('Modified')
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state, 'UTC')->diffForHumans() : '—')
                    ->toggleable(),
            ])
            ->recordActions([
                $this->archiveAction(),
                $this->switchAction(),
                $this->resetAction(),
                $this->deleteAction(),
            ])
            ->headerActions([
                $this->restoreAction(),
                Action::make('open_files')
                    ->label('File manager')
                    ->icon('tabler-folder-open')
                    ->color('gray')
                    ->url(fn () => ListFiles::getUrl(['path' => ltrim($this->worldsRoot(), '/')]), true),
            ])
            ->emptyStateIcon('tabler-world-off')
            ->emptyStateHeading('No worlds found')
            ->emptyStateDescription('A world is a folder containing level.dat. If the server has never been started, it has not generated one yet.');
    }

    // ---------------------------------------------------------------- actions

    private function archiveAction(): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon('tabler-file-zip')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(fn (array $record) => "Archive {$record['name']}")
            ->modalDescription(fn (array $record) => $this->isRunning()
                ? 'The server is running. A copy taken now catches region files mid-write and may restore to a damaged world. Stopping the server first is strongly recommended.'
                : ($record['dimensions']
                    ? 'Includes ' . implode(', ', $record['dimensions']) . '.'
                    : null))
            ->action(function (array $record, DaemonFileRepository $files) {
                $server = $this->server();

                // Inside the closure, not just ->authorize(): hiding a button is
                // a UI courtesy, this is the actual check.
                abort_unless(user()?->can(SubuserPermission::FileArchive, $server), 403);

                $name = 'world-' . $record['name'] . '-' . now()->format('Ymd-His');

                try {
                    $archive = $files->setServer($server)->compressFiles(
                        $this->worldsRoot(),
                        $record['folders'],
                        $name,
                        'tar.gz',
                    );

                    Activity::event('server:minecraft.world-archive')
                        ->property([
                            'world' => $record['name'],
                            'archive' => $archive['name'] ?? $name,
                            'dimensions' => $record['dimensions'],
                        ])
                        ->log();

                    Notification::make()
                        ->title('World archived')
                        ->body($archive['name'] ?? $name)
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    $this->fail('Could not archive the world', $exception);
                }
            });
    }

    private function switchAction(): Action
    {
        return Action::make('switch')
            ->label('Make active')
            ->icon('tabler-check')
            ->color('gray')
            ->visible(fn (array $record) => ! $record['is_active'])
            // Not merely advisory: Minecraft rewrites server.properties on
            // shutdown and would discard this edit outright.
            ->disabled(fn () => $this->isRunning())
            ->tooltip(fn () => $this->isRunning() ? 'Stop the server first.' : null)
            ->requiresConfirmation()
            ->modalHeading(fn (array $record) => "Make {$record['name']} the active world")
            ->modalDescription('Rewrites level-name in server.properties. The server will load this world the next time it starts.')
            ->action(function (array $record, DaemonFileRepository $files, WorldService $worlds) {
                $server = $this->server();

                abort_unless(user()?->can(SubuserPermission::FileReadContent, $server), 403);
                abort_unless(user()?->can(SubuserPermission::FileUpdate, $server), 403);

                if ($this->isRunning()) {
                    $this->refuseRunning();

                    return;
                }

                $properties = $worlds->readProperties($server);

                if (! $properties) {
                    Notification::make()
                        ->title('No server.properties yet')
                        ->body('Start the server once so it generates its configuration, then try again.')
                        ->warning()
                        ->send();

                    return;
                }

                $old = $properties->get('level-name', 'world');

                try {
                    $files->setServer($server)->putContent(
                        'server.properties',
                        $properties->set('level-name', $record['name'])->render(),
                    );

                    Activity::event('server:minecraft.world-switch')
                        ->property(['old' => $old, 'new' => $record['name']])
                        ->log();

                    $worlds->forget($server);

                    Notification::make()
                        ->title('Active world changed')
                        ->body("The server will load {$record['name']} on its next start.")
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    $this->fail('Could not update server.properties', $exception);
                }
            });
    }

    private function resetAction(): Action
    {
        return Action::make('reset')
            ->label('Reset')
            ->icon('tabler-refresh')
            ->color('warning')
            ->disabled(fn () => $this->isRunning())
            ->tooltip(fn () => $this->isRunning() ? 'Stop the server first.' : null)
            ->requiresConfirmation()
            ->modalHeading(fn (array $record) => "Reset {$record['name']}")
            ->modalDescription(fn (array $record) => 'The world folder'
                . ($record['dimensions'] ? ' and its dimensions (' . implode(', ', $record['dimensions']) . ')' : '')
                . ' will be deleted. The server regenerates a fresh world of the same name on its next start.')
            ->schema([
                Checkbox::make('archive_first')
                    ->label('Archive it first')
                    ->default(true)
                    ->helperText('Strongly recommended. Untick only if you are certain.'),
            ])
            ->action(fn (array $record, array $data, DaemonFileRepository $files) => $this->destroy($record, $data, $files, reset: true));
    }

    private function deleteAction(): Action
    {
        return Action::make('delete')
            ->label('Delete')
            ->icon('tabler-trash')
            ->color('danger')
            ->disabled(fn () => $this->isRunning())
            ->tooltip(fn () => $this->isRunning() ? 'Stop the server first.' : null)
            ->requiresConfirmation()
            ->modalHeading(fn (array $record) => "Delete {$record['name']}")
            ->modalDescription(fn (array $record) => ($record['is_active']
                    ? 'This is the ACTIVE world. Choose a replacement below, or the server will regenerate an empty world of the same name. '
                    : '')
                . 'This cannot be undone unless you archive first.')
            ->schema(fn (array $record) => array_values(array_filter([
                Checkbox::make('archive_first')
                    ->label('Archive it first')
                    ->default(true),

                $record['is_active'] ? Select::make('new_active')
                    ->label('New active world')
                    ->options(fn (WorldService $worlds) => collect($worlds->list($this->server(), $this->profile()))
                        ->reject(fn (array $w) => $w['name'] === $record['name'])
                        ->mapWithKeys(fn (array $w) => [$w['name'] => $w['name']])
                        ->all())
                    ->placeholder('Let the server regenerate one')
                    ->helperText('Rewrites level-name so the server does not silently start an empty world.') : null,

                // Typing the name is the only guard against a misclick that
                // destroys months of play.
                TextInput::make('confirm_name')
                    ->label('Type the world name to confirm')
                    ->required()
                    ->rule(fn () => function (string $attribute, $value, $fail) use ($record) {
                        if ($value !== $record['name']) {
                            $fail("Type {$record['name']} exactly.");
                        }
                    }),
            ])))
            ->action(fn (array $record, array $data, DaemonFileRepository $files) => $this->destroy($record, $data, $files, reset: false));
    }

    /**
     * Shared implementation for reset and delete.
     *
     * @param array<string, mixed> $data
     */
    private function destroy(array $record, array $data, DaemonFileRepository $files, bool $reset): void
    {
        $server = $this->server();

        abort_unless(user()?->can(SubuserPermission::FileDelete, $server), 403);

        if ($this->isRunning()) {
            $this->refuseRunning();

            return;
        }

        $archivePath = null;

        if (! empty($data['archive_first'])) {
            if (! user()?->can(SubuserPermission::FileArchive, $server)) {
                Notification::make()
                    ->title('Cannot archive first')
                    ->body('You do not have permission to create archives. Untick the box to proceed without one, or ask for the archive permission.')
                    ->danger()
                    ->send();

                return;
            }

            try {
                $archive = $files->setServer($server)->compressFiles(
                    $this->worldsRoot(),
                    $record['folders'],
                    'world-' . $record['name'] . '-before-' . ($reset ? 'reset' : 'delete') . '-' . now()->format('Ymd-His'),
                    'tar.gz',
                );

                $archivePath = $archive['name'] ?? null;
            } catch (Throwable $exception) {
                // Refuse rather than proceed: the user asked for a safety net
                // and silently continuing without one is how data is lost.
                $this->fail('Could not archive the world, so nothing was deleted', $exception);

                return;
            }
        }

        try {
            $files->setServer($server)->deleteFiles($this->worldsRoot(), $record['folders']);
        } catch (Throwable $exception) {
            $this->fail('Could not delete the world', $exception);

            return;
        }

        // Repoint level-name before anything else can start the server on a
        // world that no longer exists.
        if (! $reset && $record['is_active'] && ! empty($data['new_active'])) {
            $this->repointActiveWorld($server, $files, (string) $data['new_active']);
        }

        Activity::event($reset ? 'server:minecraft.world-reset' : 'server:minecraft.world-delete')
            ->property([
                'world' => $record['name'],
                'dimensions' => $record['dimensions'],
                'archived_to' => $archivePath,
            ])
            ->log();

        Notification::make()
            ->title($reset ? 'World reset' : 'World deleted')
            ->body($archivePath ? "Archived to $archivePath first." : null)
            ->success()
            ->send();
    }

    private function repointActiveWorld(Server $server, DaemonFileRepository $files, string $name): void
    {
        try {
            $properties = app(WorldService::class)->readProperties($server);

            if ($properties) {
                $files->setServer($server)->putContent(
                    'server.properties',
                    $properties->set('level-name', $name)->render(),
                );

                Activity::event('server:minecraft.world-switch')
                    ->property(['old' => null, 'new' => $name])
                    ->log();
            }
        } catch (Throwable $exception) {
            Notification::make()
                ->title('World deleted, but level-name was not updated')
                ->body('Set the active world by hand before starting the server. ' . $exception->getMessage())
                ->warning()
                ->send();
        }
    }

    private function restoreAction(): Action
    {
        return Action::make('restore')
            ->label('Restore from archive')
            ->icon('tabler-archive')
            ->color('gray')
            ->disabled(fn () => $this->isRunning())
            ->tooltip(fn () => $this->isRunning() ? 'Stop the server first.' : null)
            ->modalHeading('Restore a world from an archive')
            ->modalDescription('The existing folder is renamed out of the way rather than deleted, so a failed restore can be undone by hand.')
            ->schema([
                Select::make('archive')
                    ->label('Archive')
                    ->required()
                    ->options(fn (DaemonFileRepository $files) => $this->archiveOptions($files))
                    ->helperText('Archives found in the worlds directory.'),
            ])
            ->action(function (array $data, DaemonFileRepository $files) {
                $server = $this->server();

                abort_unless(user()?->can(SubuserPermission::FileArchive, $server), 403);
                abort_unless(user()?->can(SubuserPermission::FileCreate, $server), 403);
                abort_unless(user()?->can(SubuserPermission::FileUpdate, $server), 403);

                if ($this->isRunning()) {
                    $this->refuseRunning();

                    return;
                }

                try {
                    $files->setServer($server)->decompressFile($this->worldsRoot(), (string) $data['archive']);

                    Activity::event('server:minecraft.world-restore')
                        ->property(['archive' => $data['archive']])
                        ->log();

                    Notification::make()
                        ->title('Archive extracted')
                        ->body('Check the world list, then make the restored world active if needed.')
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    $this->fail('Could not extract the archive', $exception);
                }
            });
    }

    /**
     * @return array<string, string>
     */
    private function archiveOptions(DaemonFileRepository $files): array
    {
        try {
            $entries = $files->setServer($this->server())->getDirectory($this->worldsRoot());
        } catch (Throwable) {
            return [];
        }

        if (! is_array($entries) || isset($entries['error'])) {
            return [];
        }

        $options = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || empty($entry['file'])) {
                continue;
            }

            $name = (string) ($entry['name'] ?? '');

            foreach (['.tar.gz', '.tgz', '.zip', '.tar.bz2', '.tar.xz'] as $extension) {
                if (str_ends_with(strtolower($name), $extension)) {
                    $options[$name] = $name . ' (' . $this->humanBytes((int) ($entry['size'] ?? 0)) . ')';

                    break;
                }
            }
        }

        arsort($options);

        return $options;
    }

    // ---------------------------------------------------------------- helpers

    private function refuseRunning(): void
    {
        Notification::make()
            ->title(trans('minecraft-manager::strings.server_running.blocked'))
            ->body('Minecraft rewrites its own files when it stops, so this change would be lost.')
            ->danger()
            ->send();
    }

    private function fail(string $title, Throwable $exception): void
    {
        report($exception);

        Notification::make()
            ->title($title)
            ->body($exception->getMessage())
            ->danger()
            ->send();
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $index = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return round($value, $value >= 100 || $index === 0 ? 0 : 1) . ' ' . $units[$index];
    }
}
