<?php

namespace App\Tests\Unit\Entity\Trait;

use App\Entity\Trait\SoftDeletableTrait;
use PHPUnit\Framework\TestCase;

class SoftDeletableTraitTest extends TestCase
{
    private object $entity;

    protected function setUp(): void
    {
        $this->entity = new class {
            use SoftDeletableTrait;
        };
    }

    public function testNewEntityIsNotDeleted(): void
    {
        $this->assertFalse($this->entity->isDeleted());
        $this->assertNull($this->entity->getDeletedAt());
    }

    public function testSoftDeleteSetsDeletedAt(): void
    {
        $before = new \DateTimeImmutable();
        $this->entity->softDelete();
        $after = new \DateTimeImmutable();

        $this->assertTrue($this->entity->isDeleted());
        $this->assertNotNull($this->entity->getDeletedAt());
        $this->assertGreaterThanOrEqual($before, $this->entity->getDeletedAt());
        $this->assertLessThanOrEqual($after, $this->entity->getDeletedAt());
    }

    public function testRestoreClearsDeletedAt(): void
    {
        $this->entity->softDelete();
        $this->entity->restore();

        $this->assertFalse($this->entity->isDeleted());
        $this->assertNull($this->entity->getDeletedAt());
    }

    public function testSoftDeleteReturnsSelf(): void
    {
        $result = $this->entity->softDelete();
        $this->assertSame($this->entity, $result);
    }

    public function testRestoreReturnsSelf(): void
    {
        $this->entity->softDelete();
        $result = $this->entity->restore();
        $this->assertSame($this->entity, $result);
    }
}
