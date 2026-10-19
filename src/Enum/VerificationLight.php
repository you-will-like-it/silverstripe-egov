<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Enum;

/**
 * Traffic light result of an attachment verification.
 */
enum VerificationLight: string
{
    case Pending = 'Pending';
    case Green = 'Green';
    case Yellow = 'Yellow';
    case Red = 'Red';
    case Error = 'Error';

    public function icon(): string
    {
        return match ($this) {
            self::Pending => '⏳',
            self::Green => '🟢',
            self::Yellow => '🟡',
            self::Red => '🔴',
            self::Error => '⚠️',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => _t(self::class . '.Pending', 'Čeka provjeru'),
            self::Green => _t(self::class . '.Green', 'Ispravno'),
            self::Yellow => _t(self::class . '.Yellow', 'Potrebna ručna provjera'),
            self::Red => _t(self::class . '.Red', 'Neispravno'),
            self::Error => _t(self::class . '.Error', 'Greška pri provjeri'),
        };
    }

    /**
     * Severity used for aggregating multiple attachments (higher = worse).
     */
    public function severity(): int
    {
        return match ($this) {
            self::Green => 0,
            self::Pending => 1,
            self::Yellow => 2,
            self::Error => 3,
            self::Red => 4,
        };
    }

    public static function dbEnumValues(): string
    {
        return implode(',', array_map(static fn (self $s): string => $s->value, self::cases()));
    }

    /**
     * @param iterable<self> $lights
     */
    public static function worstOf(iterable $lights): self
    {
        $worst = self::Green;
        $any = false;
        foreach ($lights as $light) {
            $any = true;
            if ($light->severity() > $worst->severity()) {
                $worst = $light;
            }
        }
        return $any ? $worst : self::Pending;
    }
}
