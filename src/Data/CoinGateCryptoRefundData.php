<?php

declare(strict_types=1);

namespace Payroad\Provider\CoinGate\Data;

use Payroad\Port\Provider\Crypto\CryptoRefundData;

/**
 * CoinGate does not have a programmatic refund API.
 * This data object exists only for interface compliance and to store
 * any manually-recorded return information.
 */
final class CoinGateCryptoRefundData implements CryptoRefundData
{
    public function __construct(
        private readonly ?string $returnTxHash  = null,
        private readonly ?string $returnAddress = null,
    ) {}

    public function getReturnTxHash(): ?string  { return $this->returnTxHash; }
    public function getReturnAddress(): ?string { return $this->returnAddress; }

    // ── Serialisation ─────────────────────────────────────────────────────────

    public function toArray(): array
    {
        return [
            'returnTxHash'  => $this->returnTxHash,
            'returnAddress' => $this->returnAddress,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            returnTxHash:  $data['returnTxHash']  ?? null,
            returnAddress: $data['returnAddress'] ?? null,
        );
    }
}
