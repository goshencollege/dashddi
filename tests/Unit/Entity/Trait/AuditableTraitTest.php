<?php

namespace App\Tests\Unit\Entity\Trait;

use App\Entity\Trait\AuditableTrait;
use PHPUnit\Framework\TestCase;

class AuditableTraitTest extends TestCase
{
    private object $entity;

    protected function setUp(): void
    {
        $this->entity = new class {
            use AuditableTrait;
        };
    }

    public function testSetAndGetCreatedAt(): void
    {
        $dt = new \DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->entity->setCreatedAt($dt);

        $this->assertSame($dt, $this->entity->getCreatedAt());
        $this->assertSame($this->entity, $result);
    }

    public function testSetAndGetUpdatedAt(): void
    {
        $dt = new \DateTimeImmutable('2024-06-01 12:30:00');
        $result = $this->entity->setUpdatedAt($dt);

        $this->assertSame($dt, $this->entity->getUpdatedAt());
        $this->assertSame($this->entity, $result);
    }

    public function testSetAndGetCreatedBy(): void
    {
        $result = $this->entity->setCreatedBy('admin@example.com');

        $this->assertSame('admin@example.com', $this->entity->getCreatedBy());
        $this->assertSame($this->entity, $result);
    }

    public function testSetAndGetUpdatedBy(): void
    {
        $result = $this->entity->setUpdatedBy('editor@example.com');

        $this->assertSame('editor@example.com', $this->entity->getUpdatedBy());
        $this->assertSame($this->entity, $result);
    }

    public function testCreatedByDefaultsToNull(): void
    {
        $this->assertNull($this->entity->getCreatedBy());
    }

    public function testUpdatedByDefaultsToNull(): void
    {
        $this->assertNull($this->entity->getUpdatedBy());
    }

    public function testSetCreatedByAcceptsNull(): void
    {
        $this->entity->setCreatedBy('user');
        $this->entity->setCreatedBy(null);

        $this->assertNull($this->entity->getCreatedBy());
    }

    public function testSetUpdatedByAcceptsNull(): void
    {
        $this->entity->setUpdatedBy('user');
        $this->entity->setUpdatedBy(null);

        $this->assertNull($this->entity->getUpdatedBy());
    }
}
