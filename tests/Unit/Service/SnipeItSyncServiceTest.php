<?php

namespace App\Tests\Unit\Service;

use App\Service\SnipeItSyncService;
use PHPUnit\Framework\TestCase;

class SnipeItSyncServiceTest extends TestCase
{
    public function testNoExistingLinksNeverAborts(): void
    {
        $this->assertFalse(SnipeItSyncService::shouldAbortForDeletionThreshold(0, 0, 25));
    }

    public function testZeroToDeleteNeverAborts(): void
    {
        $this->assertFalse(SnipeItSyncService::shouldAbortForDeletionThreshold(0, 10, 0));
    }

    public function testBelowThresholdDoesNotAbort(): void
    {
        $this->assertFalse(SnipeItSyncService::shouldAbortForDeletionThreshold(2, 10, 25));
    }

    public function testExactlyAtThresholdDoesNotAbort(): void
    {
        $this->assertFalse(SnipeItSyncService::shouldAbortForDeletionThreshold(25, 100, 25));
    }

    public function testAboveThresholdAborts(): void
    {
        $this->assertTrue(SnipeItSyncService::shouldAbortForDeletionThreshold(3, 10, 25));
    }

    public function testThreshold100NeverAborts(): void
    {
        $this->assertFalse(SnipeItSyncService::shouldAbortForDeletionThreshold(10, 10, 100));
    }

    public function testThresholdZeroAbortsOnAnyDeletion(): void
    {
        $this->assertTrue(SnipeItSyncService::shouldAbortForDeletionThreshold(1, 10, 0));
    }

    public function testSingleExistingLinkFullDeletionAbortsBelowFullThreshold(): void
    {
        $this->assertTrue(SnipeItSyncService::shouldAbortForDeletionThreshold(1, 1, 25));
    }
}
