<?php

namespace nineteenninetyfour\reviews\models;

use craft\base\Model;

/**
 * How imported or synced reviews are added.
 */
class ImportOptions extends Model
{
    /** Count them towards averages and schema, as this site's own. */
    public bool $counted = false;

    /** "keep" what the platform had on show (approved), or hold every one as "pending". */
    public string $status = 'keep';

    /** Add reviews the platform wasn't showing, as rejected, so they're on record. */
    public bool $includeUnpublished = false;

    /** The element reviews of the business as a whole go on (a reviews page, say). Without one, they're skipped. */
    public ?int $businessElementId = null;

    public ?int $siteId = null;

    /** The sync source they come from. */
    public ?int $sourceId = null;

    /** Update reviews added before (a sync), rather than leaving them. */
    public bool $update = false;

    protected function defineRules(): array
    {
        return [
            [['status'], 'in', 'range' => ['keep', 'pending']],
        ];
    }
}
