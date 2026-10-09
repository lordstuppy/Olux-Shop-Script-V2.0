<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum UserRole: string
{
    use HasLabel;

    case Buyer = 'buyer';
    case Seller = 'seller';
    case Support = 'support';
    case Moderator = 'moderator';
    case Finance = 'finance';
    case Manager = 'manager';
    case Admin = 'admin';

    /** Staff roles can open the admin area (with permissions from App\Support\Permissions). */
    public function isStaff(): bool
    {
        return in_array($this, self::staff(), true);
    }

    /** @return list<self> */
    public static function staff(): array
    {
        return [self::Support, self::Moderator, self::Finance, self::Manager, self::Admin];
    }

    /** @return list<string> */
    public static function staffValues(): array
    {
        return array_map(fn (self $r) => $r->value, self::staff());
    }

    public function labelKey(): string
    {
        // "admin" is stored for backwards compatibility; it is the super admin tier.
        return $this === self::Admin ? 'Super admin' : ucfirst($this->value);
    }
}
