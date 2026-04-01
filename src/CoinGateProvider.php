<?php

declare(strict_types=1);

namespace Payroad\Provider\CoinGate;

use Payroad\Domain\Attempt\PaymentAttemptId;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\PaymentFlow\Crypto\CryptoPaymentAttempt;
use Payroad\Port\Provider\Crypto\CryptoAttemptContext;
use Payroad\Port\Provider\Crypto\CryptoProviderInterface;
use Payroad\Port\Provider\WebhookEvent;
use Payroad\Port\Provider\WebhookResult;
use Payroad\Provider\CoinGate\Data\CoinGateCryptoAttemptData;
use Payroad\Provider\CoinGate\Mapper\CoinGateStatusMapper;

/**
 * CoinGate crypto payment provider.
 *
 * API reference: https://developer.coingate.com/reference/introduction
 *
 * Authentication: Authorization: Token {api_key} header.
 *
 * Webhook verification: an HMAC-SHA256 token (keyed with the API key) of the
 * CoinGate order ID is appended to the callback URL as ?token=<hmac>.
 * CoinGate calls that URL on every status change, so the token arrives as a
 * query parameter. parseIncomingWebhook() re-computes the HMAC and compares
 * it — no repository access required.
 *
 * Note: CoinGate does not provide a programmatic refund API.
 * Refunds must be processed manually via the CoinGate dashboard.
 */
final class CoinGateProvider implements CryptoProviderInterface
{
    public const PRODUCTION_URL = 'https://api.coingate.com/v2';
    public const SANDBOX_URL    = 'https://api-sandbox.coingate.com/v2';

    /** @var callable|null  fn(string $method, string $url, ?array $body): array */
    private readonly mixed $httpClient;

    public function __construct(
        private readonly string             $apiKey,
        private readonly string             $ipnCallbackUrl,
        private readonly string             $baseUrl = self::PRODUCTION_URL,
        private readonly CoinGateStatusMapper $mapper = new CoinGateStatusMapper(),
        /**
         * Optional HTTP transport for testing.
         * Signature: fn(string $method, string $url, ?array $body): array
         * When null, the real curl implementation is used.
         */
        mixed $httpClient = null,
    ) {
        $this->httpClient = $httpClient;
    }

    // ── PaymentProviderInterface ──────────────────────────────────────────────

    public function supports(string $providerName): bool
    {
        return $providerName === 'coingate';
    }

    // ── CryptoProviderInterface ───────────────────────────────────────────────

    /**
     * Creates a CoinGate order and returns a CryptoPaymentAttempt containing
     * the deposit wallet address and receive currency/amount details.
     *
     * The `$context->network` field maps to CoinGate's `receive_currency`
     * (e.g. 'BTC', 'ETH', 'USDT').
     */
    public function initiateCryptoAttempt(
        PaymentAttemptId     $id,
        PaymentId            $paymentId,
        string               $providerName,
        Money                $amount,
        CryptoAttemptContext $context,
    ): CryptoPaymentAttempt {
        $priceAmount   = bcdiv($amount->getMinorAmountString(), bcpow('10', (string) $amount->getCurrency()->precision, 0), $amount->getCurrency()->precision);
        $priceCurrency = strtoupper($amount->getCurrency()->code);

        $hmacToken   = hash_hmac('sha256', (string) $id, $this->apiKey);
        $callbackUrl = $this->ipnCallbackUrl . '?token=' . $hmacToken;

        $response = $this->post('/orders', [
            'order_id'         => (string) $id,
            'price_amount'     => (float) $priceAmount,
            'price_currency'   => $priceCurrency,
            'receive_currency' => $context->network,
            'callback_url'     => $callbackUrl,
        ]);

        $data = new CoinGateCryptoAttemptData(
            coinGateOrderId: (string) $response['id'],
            paymentUrl:      (string) ($response['payment_url'] ?? ''),
            paymentAddress:  (string) ($response['payment_address'] ?? ''),
            receiveCurrency: (string) ($response['receive_currency'] ?? $context->network),
            receiveAmount:   (string) ($response['receive_amount'] ?? '0'),
        );

        $attempt = CryptoPaymentAttempt::create($id, $paymentId, $providerName, $amount, $data);
        $attempt->setProviderReference((string) $response['id']);
        $attempt->markAwaitingConfirmation('new');

        return $attempt;
    }

    // ── Webhooks ──────────────────────────────────────────────────────────────

    /**
     * Parses a CoinGate callback and maps the event to a WebhookEvent.
     *
     * Verifies the HMAC token from the callback URL query string (?token=<hmac>),
     * which was embedded by initiateCryptoAttempt() at order creation time.
     */
    public function parseIncomingWebhook(array $payload, array $headers): ?WebhookEvent
    {
        $receivedToken = (string) ($headers['token'] ?? '');
        $expectedToken = hash_hmac('sha256', (string) ($payload['order_id'] ?? $payload['id'] ?? ''), $this->apiKey);

        if (!hash_equals($expectedToken, $receivedToken)) {
            throw new \InvalidArgumentException('Invalid CoinGate callback token.');
        }

        $newStatus = $this->mapper->mapStatus((string) ($payload['status'] ?? ''));
        if ($newStatus === null) {
            return null;
        }

        $updatedData = null;
        if (strtolower((string) ($payload['status'] ?? '')) === 'paid') {
            $updatedData = new CoinGateCryptoAttemptData(
                coinGateOrderId: (string) $payload['id'],
                paymentUrl:      '',
                paymentAddress:  (string) ($payload['payment_address'] ?? ''),
                receiveCurrency: (string) ($payload['receive_currency'] ?? ''),
                receiveAmount:   (string) ($payload['receive_amount'] ?? '0'),
            );
        }

        return new WebhookResult(
            providerReference:   (string) $payload['id'],
            newStatus:           $newStatus,
            providerStatus:      (string) ($payload['status'] ?? ''),
            updatedSpecificData: $updatedData,
        );
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    private function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    private function request(string $method, string $path, ?array $body): array
    {
        $url = $this->baseUrl . $path;

        if ($this->httpClient !== null) {
            return ($this->httpClient)($method, $url, $body);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Token ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            throw new \RuntimeException("CoinGate HTTP error: {$curlErr}");
        }

        $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $decoded['message'] ?? $decoded['reason'] ?? (string) $raw;
            throw new \RuntimeException("CoinGate API error {$httpCode}: {$msg}");
        }

        return $decoded;
    }

}
