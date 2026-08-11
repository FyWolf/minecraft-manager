<?php

namespace FyWolf\MinecraftManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use FyWolf\MinecraftManager\Enums\AddonState;
use FyWolf\MinecraftManager\Enums\Capability;
use FyWolf\MinecraftManager\Models\Addon;
use FyWolf\MinecraftManager\Models\ServerAddon;
use FyWolf\MinecraftManager\Services\AddonService;
use FyWolf\MinecraftManager\Support\CapabilityResolver;
use FyWolf\MinecraftManager\Support\ResolvedProfile;
use Throwable;

/**
 * The addons a server can have, and the state of the ones it does.
 *
 * Paid addons are never granted here — entitlement comes from billing, and this
 * page only ever reflects it. What a customer can do here is install a free
 * addon, see the port a paid one was given, and remove one they no longer want.
 */
class AddonsPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-puzzle';

    protected static string|\UnitEnum|null $navigationGroup = 'Minecraft';

    protected static ?string $slug = 'mc-addons';

    protected static ?int $navigationSort = 26;

    private ?ResolvedProfile $profileMemo = null;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        if (! $server instanceof Server) {
            return false;
        }

        $profile = app(CapabilityResolver::class)->for($server);

        return parent::canAccess()
            && $profile?->has(Capability::Addons)
            && user()?->can(SubuserPermission::FileRead, $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-manager::strings.nav.addons');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileRead, $this->server()), 403);

        // Filament runs mount() before it enforces canAccess().
        $this->profileMemo = app(CapabilityResolver::class)->for($this->server());

        abort_unless($this->profileMemo?->has(Capability::Addons), 403);
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

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function () {
                $server = $this->server();
                $loader = $this->profile()->loader;

                $installs = ServerAddon::with('allocation')
                    ->where('server_id', $server->id)
                    ->get()
                    ->keyBy('mc_addon_id');

                $records = [];

                foreach (Addon::where('enabled', true)->orderBy('sort')->get() as $addon) {
                    // Hide what cannot run here at all, rather than offering a
                    // customer something that would never load.
                    if (! $addon->supports($loader)) {
                        continue;
                    }

                    $install = $installs->get($addon->id);

                    $records[$addon->key] = [
                        'key' => $addon->key,
                        'name' => $addon->name,
                        'description' => $addon->description,
                        'free' => $addon->free,
                        'needs_port' => $addon->needs_port,
                        'port_protocol' => $addon->port_protocol,
                        'state' => $install?->state->value,
                        'state_label' => $install?->state->getLabel() ?? ($addon->free ? 'Available' : 'Not purchased'),
                        'state_color' => $install?->state->getColor() ?? 'gray',
                        'endpoint' => $install?->endpoint(),
                        'port_pending' => (bool) $install?->port_patch_pending,
                        'error' => $install?->error,
                    ];
                }

                return $records;
            })
            ->columns([
                TextColumn::make('name')
                    ->label('Addon')
                    ->weight('bold')
                    ->description(fn (array $record) => $record['description']),

                TextColumn::make('state_label')
                    ->label('Status')
                    ->badge()
                    ->color(fn (array $record) => $record['state_color']),

                TextColumn::make('endpoint')
                    ->label('Address')
                    ->placeholder('—')
                    ->copyable()
                    ->description(fn (array $record) => $record['needs_port']
                        ? strtoupper($record['port_protocol']) . ($record['port_pending'] ? ' · start the server once to apply' : '')
                        : null),
            ])
            ->recordActions([
                $this->installFreeAction(),
                $this->buyAction(),
                $this->applyPortAction(),
                $this->removeAction(),
            ])
            ->emptyStateIcon('tabler-puzzle-off')
            ->emptyStateHeading('No addons available')
            ->emptyStateDescription('Nothing in the catalogue supports this server\'s mod loader.');
    }

    private function addon(array $record): ?Addon
    {
        return Addon::where('key', $record['key'])->first();
    }

    private function installFreeAction(): Action
    {
        return Action::make('install_free')
            ->label('Install')
            ->icon('tabler-download')
            ->visible(fn (array $record) => $record['free'] && $record['state'] === null)
            ->requiresConfirmation()
            ->modalHeading(fn (array $record) => 'Install ' . $record['name'])
            ->modalDescription(fn (array $record) => $record['needs_port']
                ? 'This addon claims one additional ' . strtoupper($record['port_protocol']) . ' port on your server.'
                : null)
            ->action(function (array $record) {
                $server = $this->server();

                abort_unless(user()?->can(SubuserPermission::FileCreate, $server), 403);

                $addon = $this->addon($record);

                if (! $addon?->free) {
                    // Belt and braces: the visible() above is a UI courtesy, and
                    // a forged request must not self-grant a paid addon.
                    abort(403);
                }

                app(AddonService::class)->grant($server, $addon, source: 'self');

                Notification::make()
                    ->title('Queued')
                    ->body($addon->name . ' is being installed. You will be notified when it is ready.')
                    ->success()
                    ->send();
            });
    }

    private function buyAction(): Action
    {
        return Action::make('buy')
            ->label('Get it')
            ->icon('tabler-shopping-cart')
            ->color('primary')
            ->visible(fn (array $record) => ! $record['free'] && in_array($record['state'], [null, AddonState::Suspended->value], true))
            ->url(fn (array $record) => $this->storeUrl($record), true);
    }

    /**
     * Deep link into the billing app, since that is where the purchase happens.
     */
    private function storeUrl(array $record): ?string
    {
        $base = rtrim((string) config('minecraft-manager.addons.store_url', ''), '/');

        if ($base === '') {
            return null;
        }

        return $base . '?' . http_build_query([
            'server' => $this->server()->uuid,
            'addon' => $record['key'],
        ]);
    }

    /**
     * Retry writing the port into the mod's config.
     *
     * Needed because most mods only write their config on first start, so the
     * port usually cannot be set at install time.
     */
    private function applyPortAction(): Action
    {
        return Action::make('apply_port')
            ->label('Apply port')
            ->icon('tabler-plug')
            ->color('warning')
            ->visible(fn (array $record) => $record['port_pending'])
            ->tooltip('Write the assigned port into the addon\'s configuration file')
            ->action(function (array $record) {
                $server = $this->server();

                abort_unless(user()?->can(SubuserPermission::FileUpdate, $server), 403);

                $addon = $this->addon($record);
                $install = ServerAddon::where('server_id', $server->id)->where('mc_addon_id', $addon?->id)->first();

                if (! $install) {
                    return;
                }

                try {
                    if (app(AddonService::class)->patchPort($install)) {
                        $install->forceFill(['port_patch_pending' => false])->save();

                        Notification::make()
                            ->title('Port applied')
                            ->body('Restart the server for it to take effect.')
                            ->success()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Not yet')
                        ->body('The addon has not written its configuration file yet. Start the server once, then try again.')
                        ->warning()
                        ->send();
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()->title('Could not apply the port')->body($exception->getMessage())->danger()->send();
                }
            });
    }

    private function removeAction(): Action
    {
        return Action::make('remove')
            ->label('Remove')
            ->icon('tabler-trash')
            ->color('danger')
            ->visible(fn (array $record) => in_array($record['state'], [AddonState::Active->value, AddonState::Suspended->value, AddonState::Failed->value], true))
            ->requiresConfirmation()
            ->modalHeading(fn (array $record) => 'Remove ' . $record['name'])
            ->modalDescription('Deletes the mod and frees its port. Data the addon created — a rendered map, for instance — is left on the server.')
            ->action(function (array $record) {
                $server = $this->server();

                abort_unless(user()?->can(SubuserPermission::FileDelete, $server), 403);

                $addon = $this->addon($record);

                if (! $addon) {
                    return;
                }

                try {
                    app(AddonService::class)->uninstall($server, $addon);

                    Notification::make()->title('Removed')->body($addon->name . ' is gone. Restart the server to unload it.')->success()->send();
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()->title('Could not remove it')->body($exception->getMessage())->danger()->send();
                }
            });
    }
}
