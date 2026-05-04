<?php

namespace App\Entity;

use App\Enum\SchedulableTask;
use App\Repository\ScheduledTaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ScheduledTaskRepository::class)]
#[ORM\Table(name: 'scheduled_task')]
class ScheduledTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name = '';

    #[ORM\Column(length: 50)]
    private string $taskKey = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Please enter a cron expression.')]
    #[Assert\Regex(
        pattern: '/^(\S+\s+){4}\S+$/',
        message: 'Cron expression must have exactly 5 fields.'
    )]
    private string $cronExpression = '0 2 * * *';

    #[ORM\Column]
    private bool $enabled = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $lastRunStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastRunOutput = null;

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getTaskKey(): string { return $this->taskKey; }
    public function setTaskKey(string $taskKey): static { $this->taskKey = $taskKey; return $this; }

    public function getTask(): ?SchedulableTask
    {
        return SchedulableTask::tryFrom($this->taskKey);
    }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getCronExpression(): string { return $this->cronExpression; }
    public function setCronExpression(string $cronExpression): static { $this->cronExpression = $cronExpression; return $this; }

    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): static { $this->enabled = $enabled; return $this; }

    public function getLastRunAt(): ?\DateTimeImmutable { return $this->lastRunAt; }
    public function setLastRunAt(?\DateTimeImmutable $lastRunAt): static { $this->lastRunAt = $lastRunAt; return $this; }

    public function getLastRunStatus(): ?string { return $this->lastRunStatus; }
    public function setLastRunStatus(?string $lastRunStatus): static { $this->lastRunStatus = $lastRunStatus; return $this; }

    public function getLastRunOutput(): ?string { return $this->lastRunOutput; }
    public function setLastRunOutput(?string $lastRunOutput): static { $this->lastRunOutput = $lastRunOutput; return $this; }
}
