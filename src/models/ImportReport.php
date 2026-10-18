<?php

namespace nineteenninetyfour\reviews\models;

/**
 * What an import or sync did.
 */
class ImportReport
{
    public int $added = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    /** @var array<int, string> Reviews no entry or product could be found for, described. */
    public array $unmatched = [];

    /** @var array<int, array{row: int, reason: string}> */
    public array $skipped = [];

    public int $failed = 0;

    public function total(): int
    {
        return $this->added + $this->updated + $this->unchanged;
    }
}
