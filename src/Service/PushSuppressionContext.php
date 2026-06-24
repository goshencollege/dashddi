<?php

namespace App\Service;

/**
 * Tracks whether bulk import operations have suppressed individual ClearPass push messages.
 * When suppressed, EntityPushListener skips per-MAC dispatches; the import is responsible
 * for queuing a full PushClearpassAllMessage after committing.
 */
class PushSuppressionContext
{
    private bool $clearpassSuppressed = false;

    public function suppressClearpass(): void
    {
        $this->clearpassSuppressed = true;
    }

    public function resumeClearpass(): void
    {
        $this->clearpassSuppressed = false;
    }

    public function isClearpassSuppressed(): bool
    {
        return $this->clearpassSuppressed;
    }
}
