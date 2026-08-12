<?php

declare(strict_types=1);

namespace ErpSync;

/** Every possible classification a synced feed row can land in. */
final class ChangeType
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';
    public const STALE = 'stale';
    public const FLAGGED = 'flagged';
    public const REJECTED = 'rejected';

    public const ALL = [
        self::CREATED,
        self::UPDATED,
        self::UNCHANGED,
        self::STALE,
        self::FLAGGED,
        self::REJECTED,
    ];
}
