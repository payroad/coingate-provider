<?php

declare(strict_types=1);

namespace Payroad\Provider\CoinGate\Data;

use Payroad\Domain\Channel\Crypto\CryptoAttemptData;

final class CoinGateCryptoAttemptData implements CryptoAttemptData
{
    public function __construct(
        /** CoinGate-assigned order ID (integer, stored as string). */
        private readonly string $coinGateOrderId,
        /**
         * Hosted payment page URL. Customer must be redirected here to pay.
         * CoinGate does not expose a raw wallet address at order creation time —
         * the address is assigned only after the customer opens this URL and selects a coin.
         */
        private readonly string $paymentUrl,
        /** Wallet address — empty at creation, populated from the paid webhook. */
        private readonly string $paymentAddress = '',
        /** Crypto currency code the merchant receives (e.g. "BTC", "ETH"). */
        private readonly string $receiveCurrency = '',
        /** Amount in crypto that the merchant will receive. */
        private readonly string $receiveAmount = '0',
    ) {}

    public function getCoinGateOrderId(): string  { return $this->coinGateOrderId; }
    public function getPaymentUrl(): string       { return $this->paymentUrl; }
    public function getPaymentAddress(): string   { return $this->paymentAddress; }
    public function getReceiveCurrency(): string  { return $this->receiveCurrency; }
    public function getReceiveAmount(): string    { return $this->receiveAmount; }

    // ── CryptoAttemptData ─────────────────────────────────────────────────────

    /** Empty at creation; populated after paid webhook. */
    public function getWalletAddress(): string { return $this->paymentAddress; }
    public function getPayCurrency(): string   { return $this->receiveCurrency; }
    public function getPayAmount(): string     { return $this->receiveAmount; }

    /**
     * CoinGate does not expose block confirmation counts via its API.
     * Always returns 0.
     */
    public function getConfirmationCount(): int { return 0; }

    /**
     * CoinGate handles confirmation logic internally.
     * We treat 1 confirmation as sufficient from the domain perspective.
     */
    public function getRequiredConfirmations(): int { return 1; }

    /**
     * CoinGate does not report partial payment amounts via webhook.
     * Always returns null.
     */
    public function getActualPaidAmount(): ?string { return null; }

    public function getPaymentUrl(): ?string { return $this->paymentUrl !== '' ? $this->paymentUrl : null; }

    /** CoinGate does not use memos. Always returns null. */
    public function getMemo(): ?string { return null; }

    // ── Serialisation ─────────────────────────────────────────────────────────

    public function toArray(): array
    {
        return [
            'coinGateOrderId' => $this->coinGateOrderId,
            'paymentUrl'      => $this->paymentUrl,
            'paymentAddress'  => $this->paymentAddress,
            'receiveCurrency' => $this->receiveCurrency,
            'receiveAmount'   => $this->receiveAmount,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            coinGateOrderId: $data['coinGateOrderId'],
            paymentUrl:      $data['paymentUrl']      ?? '',
            paymentAddress:  $data['paymentAddress']  ?? '',
            receiveCurrency: $data['receiveCurrency'] ?? '',
            receiveAmount:   $data['receiveAmount']   ?? '0',
        );
    }
}
