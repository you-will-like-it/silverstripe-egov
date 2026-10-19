<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YouWillLikeIT\EGov\Enum\ApplicantType;
use YouWillLikeIT\EGov\Validation\IdentificationNumberValidator;

final class IdentificationNumberValidatorTest extends TestCase
{
    private const WEIGHTS = [7, 6, 5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    /** Builds a JMBG with a correct control digit from its first 12 digits. */
    private static function withChecksum(string $twelve): ?string
    {
        $sum = 0;
        foreach (self::WEIGHTS as $i => $w) {
            $sum += (int) $twelve[$i] * $w;
        }
        $m = 11 - ($sum % 11);
        if ($m === 10) {
            return null;
        }
        return $twelve . ($m === 11 ? '0' : (string) $m);
    }

    /** @return iterable<string, array{string}> */
    public static function validJmbgProvider(): iterable
    {
        foreach (['010199017000', '150398517512', '310700518001', '290200017003'] as $base) {
            $jmbg = self::withChecksum($base);
            if ($jmbg !== null) {
                yield $jmbg => [$jmbg];
            }
        }
    }

    #[DataProvider('validJmbgProvider')]
    public function testValidJmbg(string $jmbg): void
    {
        $validator = new IdentificationNumberValidator();
        self::assertNull($validator->validate($jmbg, ApplicantType::Individual));
        self::assertNull($validator->validate(substr($jmbg, 0, 6) . ' ' . substr($jmbg, 6), ApplicantType::Individual), 'spaces are ignored');
    }

    public function testWrongControlDigit(): void
    {
        $jmbg = self::withChecksum('010199017000');
        self::assertNotNull($jmbg);
        $broken = substr($jmbg, 0, 12) . (string) (((int) $jmbg[12] + 1) % 10);
        self::assertNotNull((new IdentificationNumberValidator())->validate($broken, ApplicantType::Individual));
    }

    public function testInvalidDate(): void
    {
        $jmbg = self::withChecksum('320199017000') ?? self::withChecksum('320199017001');
        self::assertNotNull($jmbg);
        self::assertSame('JMBG sadrži neispravan datum rođenja.', (new IdentificationNumberValidator())->validate($jmbg, ApplicantType::Individual));
    }

    public function testLength(): void
    {
        self::assertNotNull((new IdentificationNumberValidator())->validate('12345', ApplicantType::Individual));
        self::assertNotNull((new IdentificationNumberValidator())->validate('12345', ApplicantType::Company));
    }

    public function testCompanyMustStartWithFour(): void
    {
        $validator = new IdentificationNumberValidator();
        self::assertNull($validator->validate('4209876543210', ApplicantType::Company));
        self::assertNotNull($validator->validate('1209876543210', ApplicantType::Company));
    }

    public function testStrictCompanyChecksum(): void
    {
        $id = self::withChecksum('420987654321');
        self::assertNotNull($id);
        self::assertNull((new IdentificationNumberValidator(true))->validate($id, ApplicantType::Company));
    }
}
