<?php

namespace nineteenninetyfour\reviews\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use DateTime;
use NineteenNinetyFour\Reviews\Core\Sync\Source as Platform;
use NineteenNinetyFour\Reviews\Core\Sync\Sources;

/**
 * A platform reviews are synced from, with the keys to fetch them.
 */
class Source extends Model
{
    public ?int $id = null;

    public string $type = '';

    public string $name = '';

    /** @var array<string, string> The platform's settings. Secret ones may be environment variables ($FEEFO_KEY). */
    public array $settings = [];

    public bool $enabled = true;

    /**
     * Count its reviews towards averages and schema. Only allowed for a
     * platform that permits it (Feefo); Google's guidelines say reviews
     * gathered from other websites mustn't be marked up.
     */
    public bool $countInSummary = false;

    /** Where its reviews of the business as a whole go. */
    public ?int $businessElementId = null;

    /** Hours between syncs. */
    public int $frequency = 24;

    public ?DateTime $lastSynced = null;

    public ?string $lastError = null;

    public ?string $uid = null;

    public function platform(): ?Platform
    {
        return Sources::find($this->type);
    }

    /**
     * The settings with environment variables filled in, for fetching.
     *
     * @return array<string, string>
     */
    public function resolvedSettings(): array
    {
        return array_map(fn ($value) => (string) App::parseEnv((string) $value), $this->settings);
    }

    public function counts(): bool
    {
        return $this->countInSummary && ($this->platform()?->schemaAllowed() ?? false);
    }

    public function isDue(?DateTime $now = null): bool
    {
        if (! $this->enabled || $this->platform() === null) {
            return false;
        }

        if ($this->lastSynced === null) {
            return true;
        }

        return ($now ?? new DateTime)->getTimestamp() - $this->lastSynced->getTimestamp() >= max(1, $this->frequency) * 3600;
    }

    protected function defineRules(): array
    {
        return [
            [['type', 'name'], 'required'],
            [['type'], 'in', 'range' => array_keys(Sources::all())],
            [['name'], 'string', 'max' => 255],
            [['frequency'], 'integer', 'min' => 1, 'max' => 24 * 30],
            [['settings'], 'validateSettings'],
            [['countInSummary'], 'validateCounting'],
        ];
    }

    public function validateSettings(): void
    {
        foreach ($this->platform()?->fields() ?? [] as $key => $field) {
            if ($field['required'] && trim((string) ($this->settings[$key] ?? '')) === '') {
                $this->addError('settings.'.$key, Craft::t('reviews', '{attribute} cannot be blank.', ['attribute' => $field['label']]));
            }
        }
    }

    public function validateCounting(): void
    {
        if ($this->countInSummary && ! ($this->platform()?->schemaAllowed() ?? false)) {
            $this->addError('countInSummary', Craft::t('reviews', '{platform}’s reviews can’t count towards your ratings: search engines don’t allow marking up reviews from other websites.', ['platform' => $this->platform()?->label() ?? $this->type]));
        }
    }
}
