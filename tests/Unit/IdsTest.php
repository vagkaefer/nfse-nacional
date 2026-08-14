<?php

declare(strict_types=1);

namespace NFSe\Tests\Unit;

use NFSe\Utils\Ids;
use PHPUnit\Framework\TestCase;

final class IdsTest extends TestCase
{
    public function testIdDpsComCnpj(): void
    {
        $id = Ids::dps('4216909', '11.222.333/0001-81', '900', '7');

        $this->assertSame('DPS' . '4216909' . '2' . '11222333000181' . '00900' . '000000000000007', $id);
        $this->assertSame(45, strlen($id));
    }

    public function testIdDpsComCpfUsaTipoInscricao1EPreencheZeros(): void
    {
        $id = Ids::dps('123', '123.456.789-09', '1', '42');

        $this->assertSame('DPS' . '0000123' . '1' . '00012345678909' . '00001' . '000000000000042', $id);
        $this->assertSame(45, strlen($id));
    }

    public function testIdPedRegEventoEDeterministico(): void
    {
        $chave = str_repeat('5', 50);

        $id = Ids::pedRegEvento($chave);

        $this->assertSame('PRE' . $chave . '101101', $id);
        $this->assertMatchesRegularExpression('/^PRE\d{56}$/', $id);
        // Determinístico: duas chamadas geram o mesmo ID (sem timestamp)
        $this->assertSame($id, Ids::pedRegEvento($chave));
    }
}
