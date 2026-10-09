<?php

namespace App\Enums;

enum UserRole: string
{
    case Buyer = 'buyer';
    case Seller = 'seller';
    case Support = 'support';
    case Finance = 'finance';
    case Admin = 'admin';

    /** Staff roles can open the admin area (with permissions from App\Support\Permissions). */
    public function isStaff(): bool
    {
        return in_array($this, [self::Support, self::Finance, self::Admin], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
