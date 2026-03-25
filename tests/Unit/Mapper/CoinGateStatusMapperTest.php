<?php

declare(strict_types=1);

namespace Tests\Unit\Mapper;

use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Provider\CoinGate\Mapper\CoinGateStatusMapper;
use PHPUnit\Framework\TestCase;

final class CoinGateStatusMapperTest extends TestCase
{
    private CoinGateStatusMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new CoinGateStatusMapper();
    }

    // ── mapStatus ─────────────────────────────────────────────────────────────

    public function testNewMapsToPending(): void
    {
        $this->assertSame(AttemptStatus::PENDING, $this->mapper->mapStatus('new'));
    }

    public function testPendingMapsToPending(): void
    {
        $this->assertSame(AttemptStatus::PENDING, $this->mapper->mapStatus('pending'));
    }

    public function testConfirmingMapsToProcessing(): void
    {
        $this->assertSame(AttemptStatus::PROCESSING, $this->mapper->mapStatus('confirming'));
    }

    public function testPaidMapsToSucceeded(): void
    {
        $this->assertSame(AttemptStatus::SUCCEEDED, $this->mapper->mapStatus('paid'));
    }

    public function testInvalidMapsToFailed(): void
    {
        $this->assertSame(AttemptStatus::FAILED, $this->mapper->mapStatus('invalid'));
    }

    public function testExpiredMapsToExpired(): void
    {
        $this->assertSame(AttemptStatus::EXPIRED, $this->mapper->mapStatus('expired'));
    }

    public function testCanceledMapsToCanceled(): void
    {
        $this->assertSame(AttemptStatus::CANCELED, $this->mapper->mapStatus('canceled'));
    }

    public function testStatusIsCaseInsensitive(): void
    {
        $this->assertSame(AttemptStatus::SUCCEEDED, $this->mapper->mapStatus('PAID'));
        $this->assertSame(AttemptStatus::PENDING,   $this->mapper->mapStatus('NEW'));
        $this->assertSame(AttemptStatus::PROCESSING, $this->mapper->mapStatus('CONFIRMING'));
    }

    public function testUnknownStatusReturnsNull(): void
    {
        $this->assertNull($this->mapper->mapStatus('unknown'));
        $this->assertNull($this->mapper->mapStatus(''));
        $this->assertNull($this->mapper->mapStatus('refunded'));
    }
}
