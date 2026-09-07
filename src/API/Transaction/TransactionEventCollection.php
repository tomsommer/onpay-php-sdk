<?php

declare(strict_types=1);

namespace OnPay\API\Transaction;

/**
 * A page of transaction events, oldest first.
 *
 * Events are paged by cursor rather than by page number. Keep nextCursor and
 * pass it to the next call to carry on from where this page ended; when it is
 * null there is nothing further right now, and the cursor you already hold
 * stays valid for asking again later.
 */
class TransactionEventCollection
{
    /** @var TransactionEvent[] */
    public array $events = [];

    public ?string $nextCursor = null;

    public function hasMore(): bool
    {
        return null !== $this->nextCursor && '' !== $this->nextCursor;
    }
}
