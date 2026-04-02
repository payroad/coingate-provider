<?php

declare(strict_types=1);

namespace Tests\Integration;

use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Domain\Attempt\PaymentAttemptId;
use Payroad\Domain\Money\Currency;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\Channel\Crypto\CryptoPaymentAttempt;
use Payroad\Port\Provider\Crypto\CryptoAttemptContext;
use Payroad\Port\Provider\WebhookResult;
use Payroad\Provider\CoinGate\CoinGateProvider;
use Payroad\Provider\CoinGate\Data\CoinGateCryptoAttemptData;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the CoinGate sandbox API.
 *
 * CoinGate flow:
 *   1. POST /orders → receive payment_url + token (no wallet address yet)
 *   2. Customer visits payment_url, selects coin, gets wallet address
 *   3. CoinGate sends callback with payment_address once paid
 *
 * Requires env vars:
 *   COINGATE_API_KEY  — sandbox token
 *   COINGATE_BASE_URL — https://api-sandbox.coingate.com/v2  (default)
 *
 * Run: make test-integration COINGATE_API_KEY=xxx
 */
final class CoinGateApiTest extends TestCase
{
    private CoinGateProvider $provider;

    protected function setUp(): void
    {
        $apiKey = (string) getenv('COINGATE_API_KEY');

        if ($apiKey === '') {
            $this->markTestSkipped('COINGATE_API_KEY env var is not set.');
        }

        $baseUrl = (string) (getenv('COINGATE_BASE_URL') ?: CoinGateProvider::SANDBOX_URL);

        $this->provider = new CoinGateProvider(
            apiKey:         $apiKey,
            ipnCallbackUrl: 'https://example.com/webhooks/coingate',
            baseUrl:        $baseUrl,
        );
    }

    // ── Order creation ────────────────────────────────────────────────────────

    public function testCreateOrderReturnsAttempt(): void
    {
        $attempt = $this->provider->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(1),
            PaymentId::fromInt(1),
            'coingate',
            Money::ofMinor(1000, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'BTC'),
        );

        $this->assertInstanceOf(CryptoPaymentAttempt::class, $attempt);
        $this->assertSame(AttemptStatus::PENDING, $attempt->getStatus());

        $providerRef = $attempt->getProviderReference();
        $this->assertNotEmpty($providerRef, 'providerReference (CoinGate order ID) must not be empty');
        $this->assertMatchesRegularExpression('/^\d+$/', $providerRef, 'CoinGate order ID must be numeric');
    }

    public function testCreateOrderReturnsPaymentUrlAndToken(): void
    {
        $attempt = $this->provider->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(2),
            PaymentId::fromInt(2),
            'coingate',
            Money::ofMinor(500, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'BTC'),
        );

        /** @var CoinGateCryptoAttemptData $data */
        $data = $attempt->getData();
        $this->assertInstanceOf(CoinGateCryptoAttemptData::class, $data);

        // payment_url — customer must be redirected here
        $this->assertNotEmpty($data->getPaymentUrl(), 'paymentUrl must be non-empty');
        $this->assertStringContainsString('coingate.com', $data->getPaymentUrl());

        // token — required for callback verification
        $this->assertNotEmpty($data->getToken(), 'token must be non-empty');

        // wallet address is empty at creation time (assigned when customer opens payment_url)
        $this->assertSame('', $data->getWalletAddress(), 'walletAddress must be empty at order creation');
    }

    public function testCreateOrderWithEth(): void
    {
        $attempt = $this->provider->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(3),
            PaymentId::fromInt(3),
            'coingate',
            Money::ofMinor(2000, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'ETH'),
        );

        /** @var CoinGateCryptoAttemptData $data */
        $data = $attempt->getData();
        $this->assertNotEmpty($data->getPaymentUrl());
        $this->assertNotEmpty($data->getToken());
    }

    public function testCreateOrderWithLtc(): void
    {
        $attempt = $this->provider->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(4),
            PaymentId::fromInt(4),
            'coingate',
            Money::ofMinor(1500, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'LTC'),
        );

        /** @var CoinGateCryptoAttemptData $data */
        $data = $attempt->getData();
        $this->assertNotEmpty($data->getPaymentUrl());
        $this->assertNotEmpty($data->getToken());
    }

    // ── Token is unique per order ─────────────────────────────────────────────

    public function testEachOrderHasUniqueToken(): void
    {
        $attemptA = $this->provider->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(10),
            PaymentId::fromInt(10),
            'coingate',
            Money::ofMinor(1000, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'BTC'),
        );

        $attemptB = $this->provider->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(11),
            PaymentId::fromInt(11),
            'coingate',
            Money::ofMinor(1000, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'BTC'),
        );

        /** @var CoinGateCryptoAttemptData $dataA */
        $dataA = $attemptA->getData();
        /** @var CoinGateCryptoAttemptData $dataB */
        $dataB = $attemptB->getData();

        $this->assertNotSame($dataA->getToken(), $dataB->getToken(), 'Each order must have a unique token');
        $this->assertNotSame(
            $attemptA->getProviderReference(),
            $attemptB->getProviderReference(),
            'Each order must have a unique CoinGate order ID',
        );
    }

    // ── Webhook parsing with real token ───────────────────────────────────────

    public function testParseWebhookWithRealOrderToken(): void
    {
        // Create a real order to get a real token
        $attempt = $this->provider->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(20),
            PaymentId::fromInt(20),
            'coingate',
            Money::ofMinor(1000, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'BTC'),
        );

        /** @var CoinGateCryptoAttemptData $data */
        $data    = $attempt->getData();
        $orderId = $attempt->getProviderReference();
        $token   = $data->getToken();

        // Simulate a 'paid' callback (as CoinGate would send it)
        $payload = [
            'id'               => (int) $orderId,
            'status'           => 'paid',
            'price_amount'     => '10.00',
            'price_currency'   => 'USD',
            'receive_currency' => 'BTC',
            'receive_amount'   => '0.00042',
            'payment_address'  => 'bc1qSimulatedWallet',
            'token'            => $token,
        ];

        $event = $this->provider->parseIncomingWebhook($payload, []);

        $this->assertInstanceOf(WebhookResult::class, $event);
        $this->assertSame($orderId,                 $event->providerReference);
        $this->assertSame(AttemptStatus::SUCCEEDED, $event->newStatus);
        $this->assertSame('paid',                   $event->providerStatus);

        /** @var CoinGateCryptoAttemptData $updatedData */
        $updatedData = $event->updatedSpecificData;
        $this->assertInstanceOf(CoinGateCryptoAttemptData::class, $updatedData);
        $this->assertSame('bc1qSimulatedWallet', $updatedData->getWalletAddress());
        $this->assertSame('BTC',                 $updatedData->getReceiveCurrency());
        $this->assertSame('0.00042',             $updatedData->getReceiveAmount());
    }

    // ── Invalid API key ───────────────────────────────────────────────────────

    public function testInvalidApiKeyThrows(): void
    {
        $badProvider = new CoinGateProvider(
            apiKey:         'invalid-api-key-000',
            ipnCallbackUrl: 'https://example.com/webhooks/coingate',
            baseUrl:        (string) (getenv('COINGATE_BASE_URL') ?: CoinGateProvider::SANDBOX_URL),
        );

        $this->expectException(\RuntimeException::class);

        $badProvider->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(99),
            PaymentId::fromInt(99),
            'coingate',
            Money::ofMinor(1000, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'BTC'),
        );
    }
}
