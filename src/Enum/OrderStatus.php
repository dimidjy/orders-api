<?php

namespace App\Enum;

enum OrderStatus: string
{
    case New = 'new';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::New => \in_array($target, [self::Paid, self::Cancelled], true),
            self::Paid => \in_array($target, [self::Shipped, self::Cancelled], true),
            self::Shipped, self::Cancelled => false,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Shipped || $this === self::Cancelled;
    }
}
