<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Enum;

enum ApplicantType: string
{
    case Individual = 'Individual';
    case Company = 'Company';

    public function label(): string
    {
        return match ($this) {
            self::Individual => _t(self::class . '.Individual', 'Fizičko lice'),
            self::Company => _t(self::class . '.Company', 'Pravno lice / obrt'),
        };
    }

    public function identificationLabel(): string
    {
        return match ($this) {
            self::Individual => _t(self::class . '.JMBG', 'JMBG'),
            self::Company => _t(self::class . '.IDNumber', 'Identifikacioni broj (ID/JIB)'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Individual->value => self::Individual->label(),
            self::Company->value => self::Company->label(),
        ];
    }
}
