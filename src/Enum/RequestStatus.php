<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Enum;

/**
 * Lifecycle states of an application request (predmet).
 *
 * Staff decisions are only possible from InProcess, i.e. after a referent
 * clicked "Pokreni obradu" and thereby locked the case for himself.
 *
 * Rejected  = odbijen (odluka o meritumu, rješenje)
 * Dismissed = odbačen (formalni razlog: nepotpun, nenadležnost, povučen ...)
 */
enum RequestStatus: string
{
    case Draft = 'Draft';
    case Submitted = 'Submitted';
    case Incomplete = 'Incomplete';
    case AwaitingPayment = 'AwaitingPayment';
    case InProcess = 'InProcess';
    case ReadyForPickup = 'ReadyForPickup';
    case Completed = 'Completed';
    case Rejected = 'Rejected';
    case Dismissed = 'Dismissed';

    public static function dbEnumValues(): string
    {
        return implode(',', array_map(static fn (self $s): string => $s->value, self::cases()));
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => _t(self::class . '.Draft', 'Nepotvrđen (nacrt)'),
            self::Submitted => _t(self::class . '.Submitted', 'Podnesen'),
            self::Incomplete => _t(self::class . '.Incomplete', 'Potrebna dopuna'),
            self::AwaitingPayment => _t(self::class . '.AwaitingPayment', 'Čeka uplatu'),
            self::InProcess => _t(self::class . '.InProcess', 'U obradi'),
            self::ReadyForPickup => _t(self::class . '.ReadyForPickup', 'Spreman za preuzimanje'),
            self::Completed => _t(self::class . '.Completed', 'Završen'),
            self::Rejected => _t(self::class . '.Rejected', 'Odbijen'),
            self::Dismissed => _t(self::class . '.Dismissed', 'Odbačen'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::Submitted => 'info',
            self::Incomplete, self::AwaitingPayment => 'warning',
            self::InProcess => 'primary',
            self::ReadyForPickup, self::Completed => 'success',
            self::Rejected, self::Dismissed => 'danger',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::InProcess],
            self::Incomplete, self::AwaitingPayment => [self::Submitted, self::Dismissed],
            self::InProcess => [self::Incomplete, self::AwaitingPayment, self::ReadyForPickup, self::Completed, self::Rejected, self::Dismissed],
            self::ReadyForPickup => [self::Completed],
            self::Completed, self::Rejected, self::Dismissed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Statutory decision deadline is suspended (request not "uredan").
     */
    public function suspendsDeadline(): bool
    {
        return in_array($this, [self::Draft, self::Incomplete, self::AwaitingPayment], true);
    }

    public function acceptsCitizenUploads(): bool
    {
        return in_array($this, [self::Draft, self::Incomplete, self::AwaitingPayment], true);
    }

    /**
     * Waiting for the citizen – case is unlocked.
     */
    public function isSupplementRound(): bool
    {
        return in_array($this, [self::Incomplete, self::AwaitingPayment], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(bool $includeDraft = true): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            if (!$includeDraft && $case === self::Draft) {
                continue;
            }
            $out[$case->value] = $case->label();
        }
        return $out;
    }
}
