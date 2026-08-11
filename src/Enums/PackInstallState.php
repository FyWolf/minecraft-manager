<?php

namespace FyWolf\MinecraftManager\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The stages of a modpack install, in order.
 *
 * The ordering is load-bearing, not cosmetic: `rank()` is what makes a resume
 * able to say "skip anything at or before where we got to" with a single
 * comparison.
 */
enum PackInstallState: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case DownloadingPack = 'downloading_pack';
    case Parsing = 'parsing';
    case BackingUp = 'backing_up';
    case Clearing = 'clearing';
    case DownloadingFiles = 'downloading_files';
    case ApplyingOverrides = 'applying_overrides';
    case Configuring = 'configuring';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::DownloadingPack => 'Downloading the pack',
            self::Parsing => 'Reading the manifest',
            self::BackingUp => 'Backing up',
            self::Clearing => 'Clearing old files',
            self::DownloadingFiles => 'Downloading mods',
            self::ApplyingOverrides => 'Applying configuration',
            self::Configuring => 'Setting the version',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
            default => 'warning',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::DownloadingPack => 1,
            self::Parsing => 2,
            self::BackingUp => 3,
            self::Clearing => 4,
            self::DownloadingFiles => 5,
            self::ApplyingOverrides => 6,
            self::Configuring => 7,
            default => 8,
        };
    }
}
