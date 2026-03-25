<?php

declare(strict_types=1);

namespace Tests\Unit;

use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Domain\Attempt\PaymentAttemptId;
use Payroad\Domain\Money\Currency;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\PaymentFlow\Crypto\CryptoPaymentAttempt;
use Payroad\Domain\Refund\RefundId;
use Payroad\Port\Provider\Crypto\CryptoAttemptContext;
use Payroad\Port\Provider\Crypto\CryptoRefundContext;
use Payroad\Port\Provider\WebhookResult;
use Payroad\Provider\CoinGate\CoinGateProvider;
use Payroad\Provider\CoinGate\Data\CoinGateCryptoAttemptData;
use PHPUnit\Framework\TestCase;

final class CoinGateProviderTest extends TestCase
{
    private const API_KEY = 'test_api_key';

    // ── Factory helpers ───────────────────────────────────────────────────────

    /**
     * Builds a provider backed by a fake HTTP client.
     *
     * @param array<string, array> $responses  Keyed by "$METHOD $urlSuffix"
     *                                          e.g. ['POST /orders' => [...response...]]
     */
    private function makeProvider(array $responses = []): CoinGateProvider
    {
        $httpClient = function (string $method, string $url, ?array $body) use ($responses): array {
            foreach ($responses as $key => $response) {
                [$keyMethod, $keySuffix] = explode(' ', $key, 2);
                if ($keyMethod === $method && str_ends_with($url, $keySuffix)) {
                    return $response;
                }
            }
            throw new \RuntimeException("Unexpected HTTP call: {$method} {$url}");
        };

        return new CoinGateProvider(
            apiKey:         self::API_KEY,
            ipnCallbackUrl: 'https://example.com/webhooks/coingate',
            httpClient:     $httpClient,
        );
    }

    private function makeOrderResponse(
        int    $orderId         = 12345,
        string $paymentUrl      = 'https://pay-sandbox.coingate.com/invoice/test-uuid',
        string $receiveCurrency = 'BTC',
        string $receiveAmount   = '0.00042',
        string $status          = 'new',
        string $token           = 'cg-random-order-token',
    ): array {
        return [
            'id'              => $orderId,
            'status'          => $status,
            'price_amount'    => '10.00',
            'price_currency'  => 'USD',
            'receive_currency' => $receiveCurrency,
            'receive_amount'  => $receiveAmount,
            'payment_url'     => $paymentUrl,
            'token'           => $token,
        ];
    }

    private function makeCallbackPayload(array $overrides = []): array
    {
        $base = [
            'id'               => 12345,
            'status'           => 'paid',
            'price_amount'     => '10.00',
            'price_currency'   => 'USD',
            'receive_currency' => 'BTC',
            'receive_amount'   => '0.00042',
            'payment_address'  => 'bc1qTestWallet',
            // CoinGate random token — provider only checks presence, not value.
            // Value comparison against stored token is done in the webhook controller.
            'token'            => 'cg-random-order-token',
        ];

        return array_merge($base, $overrides);
    }

    // ── supports() ────────────────────────────────────────────────────────────

    public function testSupportsCoingate(): void
    {
        $this->assertTrue($this->makeProvider()->supports('coingate'));
    }

    public function testDoesNotSupportOtherProviders(): void
    {
        $this->assertFalse($this->makeProvider()->supports('stripe'));
        $this->assertFalse($this->makeProvider()->supports('nowpayments'));
        $this->assertFalse($this->makeProvider()->supports('binance'));
    }

    // ── initiateCryptoAttempt() ───────────────────────────────────────────────

    public function testInitiateCryptoAttemptCreatesOrder(): void
    {
        $capturedBody = null;
        $orderId      = 99001;
        $paymentUrl   = 'https://pay-sandbox.coingate.com/invoice/abc-uuid';

        $provider = new CoinGateProvider(
            apiKey:         self::API_KEY,
            ipnCallbackUrl: 'https://example.com/ipn',
            httpClient: function (string $method, string $url, ?array $body) use ($orderId, $paymentUrl, &$capturedBody): array {
                $capturedBody = $body;
                return $this->makeOrderResponse($orderId, $paymentUrl);
            },
        );

        $attempt = $provider->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(1),
            PaymentId::fromInt(42),
            'coingate',
            Money::ofMinor(1000, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'BTC'),
        );

        // Verify request body fields
        $this->assertSame('BTC',  $capturedBody['receive_currency']);
        $this->assertSame('USD',  $capturedBody['price_currency']);
        $this->assertSame(10.0,   $capturedBody['price_amount']);

        // Verify returned aggregate
        $this->assertInstanceOf(CryptoPaymentAttempt::class, $attempt);
        $this->assertSame((string) $orderId, $attempt->getProviderReference());

        // Verify data object
        /** @var CoinGateCryptoAttemptData $data */
        $data = $attempt->getData();
        $this->assertInstanceOf(CoinGateCryptoAttemptData::class, $data);
        $this->assertSame($paymentUrl,           $data->getPaymentUrl());
        $this->assertSame((string) $orderId,     $data->getCoinGateOrderId());
        $this->assertSame('cg-random-order-token', $data->getToken());
    }

    public function testInitiateCryptoAttemptInitialStatusIsPending(): void
    {
        $provider = $this->makeProvider(['POST /orders' => $this->makeOrderResponse()]);

        $attempt = $provider->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(1),
            PaymentId::fromInt(1),
            'coingate',
            Money::ofMinor(500, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'ETH'),
        );

        $this->assertSame(AttemptStatus::PENDING, $attempt->getStatus());
    }

    public function testInitiateCryptoAttemptStoresTokenFromResponse(): void
    {
        $token    = 'cg-unique-order-token-xyz';
        $provider = $this->makeProvider([
            'POST /orders' => $this->makeOrderResponse(token: $token),
        ]);

        $attempt = $provider->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(1),
            PaymentId::fromInt(1),
            'coingate',
            Money::ofMinor(500, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'BTC'),
        );

        /** @var CoinGateCryptoAttemptData $data */
        $data = $attempt->getData();
        $this->assertSame($token, $data->getToken());
    }

    public function testInitiateCryptoAttemptSetsPayCurrencyAndAmount(): void
    {
        $provider = $this->makeProvider([
            'POST /orders' => $this->makeOrderResponse(receiveCurrency: 'ETH', receiveAmount: '0.25'),
        ]);

        $attempt = $provider->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(1),
            PaymentId::fromInt(1),
            'coingate',
            Money::ofMinor(500, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'ETH'),
        );

        /** @var CoinGateCryptoAttemptData $data */
        $data = $attempt->getData();
        $this->assertSame('ETH',  $data->getPayCurrency());
        $this->assertSame('0.25', $data->getPayAmount());
    }

    public function testInitiateCryptoAttemptSetsPaymentUrl(): void
    {
        $paymentUrl = 'https://pay-sandbox.coingate.com/invoice/xyz-uuid';
        $provider   = $this->makeProvider([
            'POST /orders' => $this->makeOrderResponse(paymentUrl: $paymentUrl),
        ]);

        $attempt = $provider->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(1),
            PaymentId::fromInt(1),
            'coingate',
            Money::ofMinor(2000, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'BTC'),
        );

        /** @var CoinGateCryptoAttemptData $data */
        $data = $attempt->getData();
        $this->assertSame($paymentUrl, $data->getPaymentUrl());
    }

    // ── initiateRefund() ──────────────────────────────────────────────────────

    public function testInitiateRefundThrows(): void
    {
        $provider = $this->makeProvider();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CoinGate does not support programmatic refunds');

        $provider->initiateRefund(
            id:                        RefundId::fromInt(1),
            paymentId:                 PaymentId::fromInt(10),
            originalAttemptId:         PaymentAttemptId::fromInt(5),
            providerName:              'coingate',
            amount:                    Money::ofMinor(500, new Currency('USD', 2)),
            originalProviderReference: '12345',
            context:                   new CryptoRefundContext(returnAddress: 'bc1qRefundAddr'),
        );
    }

    // ── parseIncomingWebhook() ────────────────────────────────────────────────

    public function testParseWebhookReturnsWebhookResult(): void
    {
        $payload = $this->makeCallbackPayload(['id' => 12345, 'status' => 'paid']);
        $event   = $this->makeProvider()->parseIncomingWebhook($payload, []);

        $this->assertInstanceOf(WebhookResult::class, $event);
        $this->assertSame('12345',              $event->providerReference);
        $this->assertSame(AttemptStatus::SUCCEEDED, $event->newStatus);
        $this->assertSame('paid',               $event->providerStatus);
    }

    public function testParseWebhookAttachesUpdatedDataOnPaid(): void
    {
        $payload = $this->makeCallbackPayload([
            'id'               => 55555,
            'status'           => 'paid',
            'payment_address'  => 'bc1qPaidAddress',
            'receive_currency' => 'BTC',
            'receive_amount'   => '0.001',
        ]);

        $event = $this->makeProvider()->parseIncomingWebhook($payload, []);

        $this->assertInstanceOf(WebhookResult::class, $event);
        $this->assertNotNull($event->updatedSpecificData);

        /** @var CoinGateCryptoAttemptData $data */
        $data = $event->updatedSpecificData;
        $this->assertInstanceOf(CoinGateCryptoAttemptData::class, $data);
        $this->assertSame('bc1qPaidAddress', $data->getWalletAddress());
        $this->assertSame('BTC',             $data->getReceiveCurrency());
        $this->assertSame('0.001',           $data->getReceiveAmount());
    }

    public function testParseWebhookDoesNotAttachUpdatedDataForNonPaidStatus(): void
    {
        $payload = $this->makeCallbackPayload(['id' => 12345, 'status' => 'confirming']);
        $event   = $this->makeProvider()->parseIncomingWebhook($payload, []);

        $this->assertInstanceOf(WebhookResult::class, $event);
        $this->assertSame(AttemptStatus::PROCESSING, $event->newStatus);
        $this->assertNull($event->updatedSpecificData);
    }

    public function testParseWebhookReturnsNullForUnknownStatus(): void
    {
        $payload = $this->makeCallbackPayload(['id' => 12345, 'status' => 'unknown']);
        $event   = $this->makeProvider()->parseIncomingWebhook($payload, []);

        $this->assertNull($event);
    }

    public function testAnyNonEmptyTokenIsAcceptedByProvider(): void
    {
        // The provider only checks token presence. Value verification (comparing
        // against the stored token) is the webhook controller's responsibility.
        $payload = $this->makeCallbackPayload(['token' => 'any-non-empty-string']);
        $event   = $this->makeProvider()->parseIncomingWebhook($payload, []);

        $this->assertInstanceOf(WebhookResult::class, $event);
    }

    public function testMissingTokenThrows(): void
    {
        $payload = $this->makeCallbackPayload();
        unset($payload['token']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing CoinGate callback token.');

        $this->makeProvider()->parseIncomingWebhook($payload, []);
    }

    public function testEmptyTokenThrows(): void
    {
        $payload = $this->makeCallbackPayload(['token' => '']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing CoinGate callback token.');

        $this->makeProvider()->parseIncomingWebhook($payload, []);
    }

    public function testParseWebhookMapsPendingStatus(): void
    {
        $payload = $this->makeCallbackPayload(['id' => 12345, 'status' => 'pending']);
        $event   = $this->makeProvider()->parseIncomingWebhook($payload, []);

        $this->assertInstanceOf(WebhookResult::class, $event);
        $this->assertSame(AttemptStatus::PENDING, $event->newStatus);
    }

    public function testParseWebhookMapsCanceledStatus(): void
    {
        $payload = $this->makeCallbackPayload(['id' => 12345, 'status' => 'canceled']);
        $event   = $this->makeProvider()->parseIncomingWebhook($payload, []);

        $this->assertInstanceOf(WebhookResult::class, $event);
        $this->assertSame(AttemptStatus::CANCELED, $event->newStatus);
    }
}
