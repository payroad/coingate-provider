<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use Payroad\Provider\CoinGate\Data\CoinGateCryptoRefundData;
use PHPUnit\Framework\TestCase;

final class CoinGateCryptoRefundDataTest extends TestCase
{
    public function testGettersReturnConstructorValues(): void
    {
        $data = new CoinGateCryptoRefundData(
            returnTxHash:  'abc123txhash',
            returnAddress: 'bc1qReturnAddress',
        );

        $this->assertSame('abc123txhash',     $data->getReturnTxHash());
        $this->assertSame('bc1qReturnAddress', $data->getReturnAddress());
    }

    public function testDefaultsAreNull(): void
    {
        $data = new CoinGateCryptoRefundData();

        $this->assertNull($data->getReturnTxHash());
        $this->assertNull($data->getReturnAddress());
    }

    public function testToArrayContainsAllFields(): void
    {
        $data  = new CoinGateCryptoRefundData(returnTxHash: 'tx1', returnAddress: 'addr1');
        $array = $data->toArray();

        $this->assertArrayHasKey('returnTxHash',  $array);
        $this->assertArrayHasKey('returnAddress', $array);
        $this->assertSame('tx1',   $array['returnTxHash']);
        $this->assertSame('addr1', $array['returnAddress']);
    }

    public function testToArrayWithNullValues(): void
    {
        $data  = new CoinGateCryptoRefundData();
        $array = $data->toArray();

        $this->assertNull($array['returnTxHash']);
        $this->assertNull($array['returnAddress']);
    }

    public function testFromArrayRoundtrip(): void
    {
        $original = new CoinGateCryptoRefundData(
            returnTxHash:  'roundtrip_tx_hash',
            returnAddress: 'roundtrip_addr',
        );

        $restored = CoinGateCryptoRefundData::fromArray($original->toArray());

        $this->assertSame($original->getReturnTxHash(),  $restored->getReturnTxHash());
        $this->assertSame($original->getReturnAddress(), $restored->getReturnAddress());
    }

    public function testFromArrayWithMissingOptionalFields(): void
    {
        $data = CoinGateCryptoRefundData::fromArray([]);

        $this->assertNull($data->getReturnTxHash());
        $this->assertNull($data->getReturnAddress());
    }
}
