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

    public function testManuallyAddedInterfaceIsNeverRemoved(): void
    {
        $this->assertFalse(SnipeItSyncService::shouldRemoveStaleInterface(false, 'aa:bb:cc:dd:ee:ff', ['11:22:33:44:55:66']));
    }

    public function testManagedInterfaceMissingFromAssetIsRemoved(): void
    {
        $this->assertTrue(SnipeItSyncService::shouldRemoveStaleInterface(true, 'aa:bb:cc:dd:ee:ff', ['11:22:33:44:55:66']));
    }

    public function testManagedInterfaceStillOnAssetIsKept(): void
    {
        $this->assertFalse(SnipeItSyncService::shouldRemoveStaleInterface(true, 'aa:bb:cc:dd:ee:ff', ['aa:bb:cc:dd:ee:ff']));
    }

    public function testManuallyAddedInterfaceStillOnAssetIsKept(): void
    {
        $this->assertFalse(SnipeItSyncService::shouldRemoveStaleInterface(false, 'aa:bb:cc:dd:ee:ff', ['aa:bb:cc:dd:ee:ff']));
    }
}
