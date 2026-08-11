<?php

namespace FyWolf\MinecraftManager\Filament\Admin\Resources\CapabilityProfiles\Pages;

use App\Models\Egg;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use FyWolf\MinecraftManager\Filament\Admin\Resources\CapabilityProfiles\CapabilityProfileResource;
use FyWolf\MinecraftManager\Models\CapabilityProfile;
use FyWolf\MinecraftManager\Models\EggCapabilityProfile;
use FyWolf\MinecraftManager\Support\CapabilityResolver;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManageCapabilityProfiles extends ManageRecords
{
    protected static string $resource = CapabilityProfileResource::class;

    /**
     * Memo for the unmapped-egg scan.
     *
     * The badge, the modal body and the action itself all need the same answer,
     * and each scan walks every egg running the loader heuristic. Without this
     * a panel with a large egg library pays for it three times per render.
     *
     * @var array<int, array{egg: Egg, profile: CapabilityProfile}>|null
     */
    private ?array $unmappedMemo = null;

    protected function getHeaderActions(): array
    {
        return [
            $this->detectUnmappedAction(),
            $this->exportAction(),
            CreateAction::make()->createAnother(false),
        ];
    }

    /**
     * Turn the heuristic from an invisible fallback into a visible suggestion.
     *
     * Servers on an unmapped egg still work — the resolver guesses — but the
     * guess is not recorded anywhere an administrator can see or override. This
     * lists what would be guessed and offers to write it down.
     */
    private function detectUnmappedAction(): Action
    {
        return Action::make('detect_unmapped')
            ->label('Detect unmapped eggs')
            ->icon('tabler-wand')
            ->color('gray')
            ->badge(fn () => $this->unmappedCount() ?: null)
            ->modalHeading('Unmapped Minecraft eggs')
            ->modalDescription('These eggs have no profile. Servers using them currently fall back to automatic detection, which works but cannot be overridden. Mapping them writes the detected profile down so you can change it.')
            ->modalSubmitActionLabel('Map them')
            ->modalContent(function (CapabilityResolver $resolver) {
                $rows = $this->unmappedEggs($resolver);

                if ($rows === []) {
                    return str('<p class="text-sm">Every Minecraft egg already has a profile.</p>')->toHtmlString();
                }

                $items = collect($rows)
                    ->map(fn (array $row) => '<li class="py-1"><strong>' . e($row['egg']->name) . '</strong> &rarr; ' . e($row['profile']->name) . '</li>')
                    ->implode('');

                return str('<ul class="text-sm list-disc ps-5">' . $items . '</ul>')->toHtmlString();
            })
            ->action(function (CapabilityResolver $resolver) {
                $rows = $this->unmappedEggs($resolver);

                foreach ($rows as $row) {
                    EggCapabilityProfile::firstOrCreate(
                        ['egg_id' => $row['egg']->id],
                        ['mc_capability_profile_id' => $row['profile']->id],
                    );
                }

                // The memo is now stale — those eggs are mapped.
                $this->unmappedMemo = null;

                Notification::make()
                    ->title(count($rows) === 0 ? 'Nothing to map' : 'Mapped ' . count($rows) . ' egg(s)')
                    ->success()
                    ->send();
            });
    }

    /**
     * Uninstalling this plugin rolls back its migrations, which drops the
     * mapping tables outright — `PluginService::uninstallPlugin()` calls
     * `$migrator->reset()` and a plugin cannot opt out. This is the only way to
     * keep a mapping across a reinstall.
     */
    private function exportAction(): Action
    {
        return Action::make('export')
            ->label('Export mapping')
            ->icon('tabler-download')
            ->color('gray')
            ->tooltip('Uninstalling the plugin drops these tables. Export first if you plan to reinstall.')
            ->action(function (): StreamedResponse {
                $payload = [
                    'exported_at' => now()->toIso8601String(),
                    'profiles' => CapabilityProfile::with('eggs:id,uuid,name')->get()->map(fn (CapabilityProfile $p) => [
                        'name' => $p->name,
                        'loader' => $p->loader,
                        'capabilities' => $p->capabilities,
                        'content_dir' => $p->content_dir,
                        'worlds_dir' => $p->worlds_dir,
                        'dimension_layout' => $p->dimension_layout,
                        'version_provider' => $p->version_provider,
                        'jar_path' => $p->jar_path,
                        'mc_version_variables' => $p->mc_version_variables,
                        'loader_version_variables' => $p->loader_version_variables,
                        // Exported by egg UUID, not id: ids are per-install but
                        // UUIDs survive a reimport.
                        'eggs' => $p->eggs->map(fn (Egg $e) => ['uuid' => $e->uuid, 'name' => $e->name])->all(),
                    ])->all(),
                ];

                return response()->streamDownload(
                    fn () => print json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    'minecraft-manager-profiles.json',
                    ['Content-Type' => 'application/json'],
                );
            });
    }

    /**
     * @return array<int, array{egg: Egg, profile: CapabilityProfile}>
     */
    private function unmappedEggs(CapabilityResolver $resolver): array
    {
        if ($this->unmappedMemo !== null) {
            return $this->unmappedMemo;
        }

        $profiles = CapabilityProfile::whereNotNull('loader')->get()->keyBy('loader');

        if ($profiles->isEmpty()) {
            return $this->unmappedMemo = [];
        }

        $mapped = EggCapabilityProfile::pluck('egg_id')->all();

        $rows = [];

        foreach (Egg::whereNotIn('id', $mapped ?: [0])->get() as $egg) {
            $loader = $resolver->detectLoaderForEgg($egg);

            if ($loader && $profiles->has($loader->value)) {
                $rows[] = ['egg' => $egg, 'profile' => $profiles->get($loader->value)];
            }
        }

        return $this->unmappedMemo = $rows;
    }

    private function unmappedCount(): int
    {
        return count($this->unmappedEggs(app(CapabilityResolver::class)));
    }
}
