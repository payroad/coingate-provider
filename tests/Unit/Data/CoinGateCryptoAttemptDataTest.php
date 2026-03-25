<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use Payroad\Provider\CoinGate\Data\CoinGateCryptoAttemptData;
use PHPUnit\Framework\TestCase;

final class CoinGateCryptoAttemptDataTest extends TestCase
{
    private function makeData(array $overrides = []): CoinGateCryptoAttemptData
    {
        return new CoinGateCryptoAttemptData(
            coinGateOrderId: $overrides['coinGateOrderId'] ?? '12345',
            paymentUrl:      $overrides['paymentUrl']      ?? 'https://pay.coingate.com/invoice/test',
            token:           $overrides['token']           ?? '',
            paymentAddress:  $overrides['paymentAddress']  ?? '',
            receiveCurrency: $overrides['receiveCurrency'] ?? 'BTC',
            receiveAmount:   $overrides['receiveAmount']   ?? '0.00042',
        );
    }

    public function testGetters(): void
    {
        $data = $this->makeData([
            'coinGateOrderId' => '99999',
            'paymentUrl'      => 'https://pay.coingate.com/invoice/abc',
            'token'           => 'cg-secret-token',
            'paymentAddress'  => '0xEthWallet',
            'receiveCurrency' => 'ETH',
            'receiveAmount'   => '0.5',
        ]);

        $this->assertSame('99999',                                $data->getCoinGateOrderId());
        $this->assertSame('https://pay.coingate.com/invoice/abc', $data->getPaymentUrl());
        $this->assertSame('cg-secret-token',                      $data->getToken());
        $this->assertSame('0xEthWallet',                          $data->getPaymentAddress());
        $this->assertSame('ETH',                                  $data->getReceiveCurrency());
        $this->assertSame('0.5',                                  $data->getReceiveAmount());
    }

    public function testTokenDefaultsToEmptyString(): void
    {
        $data = $this->makeData();
        $this->assertSame('', $data->getToken());
    }

    public function testPaymentAddressDefaultsToEmpty(): void
    {
        $data = $this->makeData();
        $this->assertSame('', $data->getPaymentAddress());
        $this->assertSame('', $data->getWalletAddress());
    }

    public function testGetWalletAddressReturnPaymentAddress(): void
    {
        $data = $this->makeData(['paymentAddress' => 'bc1qXXXYYYZZZ']);
        $this->assertSame('bc1qXXXYYYZZZ', $data->getWalletAddress());
    }

    public function testGetPayCurrencyAliasesReceiveCurrency(): void
    {
        $data = $this->makeData(['receiveCurrency' => 'ETH']);
        $this->assertSame('ETH', $data->getPayCurrency());
    }

    public function testGetPayAmountAliasesReceiveAmount(): void
    {
        $data = $this->makeData(['receiveAmount' => '0.12345']);
        $this->assertSame('0.12345', $data->getPayAmount());
    }

    public function testGetConfirmationCountAlwaysReturnsZero(): void
    {
        $data = $this->makeData();
        $this->assertSame(0, $data->getConfirmationCount());
    }

    public function testGetRequiredConfirmationsAlwaysReturnsOne(): void
    {
        $data = $this->makeData();
        $this->assertSame(1, $data->getRequiredConfirmations());
    }

    public function testToArrayContainsAllFields(): void
    {
        $data  = $this->makeData(['token' => 'tok-abc', 'paymentUrl' => 'https://pay.coingate.com/invoice/x']);
        $array = $data->toArray();

        $this->assertArrayHasKey('coinGateOrderId', $array);
        $this->assertArrayHasKey('paymentUrl',      $array);
        $this->assertArrayHasKey('token',           $array);
        $this->assertArrayHasKey('paymentAddress',  $array);
        $this->assertArrayHasKey('receiveCurrency', $array);
        $this->assertArrayHasKey('receiveAmount',   $array);
        $this->assertSame('tok-abc', $array['token']);
        $this->assertSame('https://pay.coingate.com/invoice/x', $array['paymentUrl']);
    }

    public function testFromArrayRoundtrip(): void
    {
        $original = $this->makeData([
            'coinGateOrderId' => '77777',
            'paymentUrl'      => 'https://pay.coingate.com/invoice/roundtrip',
            'token'           => 'cg-roundtrip-token',
            'paymentAddress'  => 'bc1qRoundtripAddress',
            'receiveCurrency' => 'LTC',
            'receiveAmount'   => '1.23456789',
        ]);

        $restored = CoinGateCryptoAttemptData::fromArray($original->toArray());

        $this->assertSame($original->getCoinGateOrderId(), $restored->getCoinGateOrderId());
        $this->assertSame($original->getPaymentUrl(),      $restored->getPaymentUrl());
        $this->assertSame($original->getToken(),           $restored->getToken());
        $this->assertSame($original->getPaymentAddress(),  $restored->getPaymentAddress());
        $this->assertSame($original->getReceiveCurrency(), $restored->getReceiveCurrency());
        $this->assertSame($original->getReceiveAmount(),   $restored->getReceiveAmount());
    }

    public function testFromArrayWithMissingOptionalFieldsUsesDefaults(): void
    {
        $data = CoinGateCryptoAttemptData::fromArray([
            'coinGateOrderId' => '111',
            'paymentUrl'      => 'https://pay.coingate.com/invoice/min',
        ]);

        $this->assertSame('', $data->getToken());
        $this->assertSame('', $data->getPaymentAddress());
        $this->assertSame('', $data->getReceiveCurrency());
        $this->assertSame('0', $data->getReceiveAmount());
    }
}
