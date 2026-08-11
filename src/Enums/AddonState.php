<?php

namespace FyWolf\MinecraftManager\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AddonState: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Installing = 'installing';
    case Active = 'active';

    /**
     * Entitlement withdrawn: the port has been released, the files left alone.
     *
     * Deliberately distinct from Removed. The mod is still on disk and will log
     * a bind failure until someone deals with it, and re-granting is a matter of
     * handing back a port rather than downloading anything.
     */
    case Suspended = 'suspended';

    case Failed = 'failed';
    case Removed = 'removed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Installing => 'Installing',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Failed => 'Failed',
            self::Removed => 'Removed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Failed => 'danger',
            self::Suspended => 'warning',
            self::Removed => 'gray',
            default => 'info',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Active, self::Failed, self::Suspended, self::Removed], true);
    }

    /** Whether the addon currently holds a port. */
    public function holdsPort(): bool
    {
        return in_array($this, [self::Active, self::Installing], true);
    }
}
