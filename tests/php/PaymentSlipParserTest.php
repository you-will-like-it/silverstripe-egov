<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Tests;

use PHPUnit\Framework\TestCase;
use YouWillLikeIT\EGov\Verification\PaymentSlipParser;

final class PaymentSlipParserTest extends TestCase
{
    private const SLIP = <<<TXT
        NALOG ZA UPLATU JAVNIH PRIHODA
        Uplatilac: [JMBG/ID] Ime Prezime, Srebrenik
        Svrha doznake: Administrativna taksa – urbanistička saglasnost
        Primalac: Budžet Grada Srebrenik
        Račun primaoca: 132 150 0299 280 097
        Iznos: KM 150,00
        Broj poreznog obveznika 0101990170006
        Vrsta uplate 0   Vrsta prihoda 722131
        Općina 084   Budžetska organizacija 0000000
        Poziv na broj 0000000000
        TXT;

    public function testParsesTypicalSlip(): void
    {
        $data = (new PaymentSlipParser())->parse(self::SLIP);

        self::assertContains('1321500299280097', $data->accounts);
        self::assertSame('722131', $data->revenueType);
        self::assertSame('084', $data->municipalityCode);
        self::assertSame('0000000', $data->budgetOrganization);
        self::assertSame(150.0, $data->amount);
    }

    public function testOcrConfusionsInDigitGroups(): void
    {
        $data = (new PaymentSlipParser())->parse("Racun primaoca 132-1OO-O256OOOO8O\nVrsta prihoda 7225l5\nIznos 11,00 KM");
        self::assertContains('1321000256000080', $data->accounts);
        self::assertSame('722515', $data->revenueType);
        self::assertSame(11.0, $data->amount);
    }

    public function testUnreadableSlipYieldsNulls(): void
    {
        $data = (new PaymentSlipParser())->parse('nečitljiv sken');
        self::assertTrue($data->isEmpty());
    }

    public function testAmountFormats(): void
    {
        self::assertSame(1234.5, PaymentSlipParser::toFloat('1.234,50'));
        self::assertSame(15.0, PaymentSlipParser::toFloat('15.00'));
        self::assertNull(PaymentSlipParser::toFloat('abc'));
    }
}
