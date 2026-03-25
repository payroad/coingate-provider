<?php

declare(strict_types=1);

namespace Payroad\Provider\CoinGate\Mapper;

use Payroad\Domain\Attempt\AttemptStatus;

/**
 * Maps CoinGate order status strings to domain AttemptStatus values.
 *
 * CoinGate order statuses:
 *   new        – order created, awaiting payment
 *   pending    – payment detected but not yet confirmed
 *   confirming – payment is accumulating block confirmations
 *   paid       – payment fully confirmed and settled
 *   invalid    – payment was invalid (wrong amount, etc.)
 *   expired    – order expired before payment arrived
 *   canceled   – order was canceled
 */
final class CoinGateStatusMapper
{
    /** @return AttemptStatus|null  null = ignore this status update */
    public function mapStatus(string $status): ?AttemptStatus
    {
        return match (strtolower($status)) {
            'new',
            'pending'    => AttemptStatus::PENDING,
            'confirming' => AttemptStatus::PROCESSING,
            'paid'       => AttemptStatus::SUCCEEDED,
            'invalid'    => AttemptStatus::FAILED,
            'expired'    => AttemptStatus::EXPIRED,
            'canceled'   => AttemptStatus::CANCELED,
            default      => null,
        };
    }
}
