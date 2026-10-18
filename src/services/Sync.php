<?php

namespace nineteenninetyfour\reviews\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use NineteenNinetyFour\Reviews\Core\Http\Client;
use NineteenNinetyFour\Reviews\Core\Sync\SyncException;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\http\CraftClient;
use nineteenninetyfour\reviews\jobs\SyncSource;
use nineteenninetyfour\reviews\migrations\Install;
use nineteenninetyfour\reviews\models\ImportOptions;
use nineteenninetyfour\reviews\models\ImportReport;
use nineteenninetyfour\reviews\models\Source;
use nineteenninetyfour\reviews\Plugin;
use Throwable;

/**
 * Fetching reviews from other platforms on a schedule. Keys are kept in the
 * database, not project config, and secret ones are encrypted; they can be
 * environment variables instead.
 */
class Sync extends Component
{
    /** How far back each sync looks again, for reviews changed late. */
    public const OVERLAP_DAYS = 2;

    /** The HTTP client fetches go through: Craft's own, unless set (for tests). */
    public ?Client $client = null;

    /**
     * @return array<int, Source>
     */
    public function all(): array
    {
        return array_map(fn (array $row) => $this->fromRow($row), (new Query)->from(Install::SOURCES)->orderBy(['name' => SORT_ASC])->all());
    }

    public function find(int $id): ?Source
    {
        $row = (new Query)->from(Install::SOURCES)->where(['id' => $id])->one();

        return $row ? $this->fromRow($row) : null;
    }

    public function save(Source $source): bool
    {
        if (! $source->validate()) {
            return false;
        }

        $columns = [
            'type' => $source->type,
            'name' => $source->name,
            'settings' => $this->encodeSettings($source),
            'enabled' => $source->enabled,
            'countInSummary' => $source->countInSummary,
            'businessElementId' => $source->businessElementId,
            'frequency' => $source->frequency,
        ];

        if ($source->id) {
            $before = $this->find($source->id);
            Db::update(Install::SOURCES, $columns, ['id' => $source->id]);

            // Its reviews follow the source's choice on counting.
            if ($before && $before->counts() !== $source->counts()) {
                Db::update(Install::REVIEWS, ['counted' => $source->counts()], ['sourceId' => $source->id]);
                $this->forgetSummaries($source->id);
            }
        } else {
            $source->uid ??= StringHelper::UUID();
            Db::insert(Install::SOURCES, $columns + ['uid' => $source->uid]);
            $source->id = (int) Craft::$app->getDb()->getLastInsertID(Install::SOURCES);
        }

        return true;
    }

    /**
     * Remove a source. Its reviews stay, or go too.
     */
    public function delete(Source $source, bool $withReviews = false): void
    {
        if ($withReviews) {
            foreach (Review::find()->sourceId($source->id)->status(null)->all() as $review) {
                Plugin::getInstance()->reviews->delete($review);
            }
        }

        Db::delete(Install::SOURCES, ['id' => $source->id]);
    }

    /**
     * Fetch a source's reviews now. Everything changed since its last sync
     * (and a little before) is fetched again; the first sync fetches all.
     */
    public function run(Source $source, bool $all = false): ImportReport
    {
        $platform = $source->platform();
        $report = new ImportReport;

        if (! $platform) {
            throw new SyncException(Craft::t('reviews', 'Reviews doesn’t sync from “{type}”.', ['type' => $source->type]));
        }

        $since = ! $all && $source->lastSynced ? DateTimeImmutable::createFromMutable($source->lastSynced)->sub(new DateInterval('P'.self::OVERLAP_DAYS.'D')) : null;
        $started = new DateTime;

        $options = new ImportOptions([
            'counted' => $source->counts(),
            'businessElementId' => $source->businessElementId,
            'sourceId' => $source->id,
            'update' => true,
            'includeUnpublished' => false,
        ]);

        try {
            Plugin::getInstance()->imports->import($platform->fetch($this->client ?? new CraftClient(30), $source->resolvedSettings(), $since), $options, $report);
        } catch (Throwable $e) {
            $this->record($source, null, $e->getMessage());

            throw $e instanceof SyncException ? $e : new SyncException($e->getMessage(), 0, $e);
        }

        $this->record($source, $started, null);

        return $report;
    }

    /**
     * Queue a sync of every source that's due. Safe to call often: a source
     * already queued isn't queued again.
     */
    public function queueDue(): int
    {
        $queued = 0;

        foreach ($this->all() as $source) {
            $key = 'reviews:sync-queued:'.$source->id;

            if ($source->isDue() && Craft::$app->getCache()->add($key, true, 3600)) {
                Craft::$app->getQueue()->push(new SyncSource(['sourceId' => $source->id]));
                $queued++;
            }
        }

        return $queued;
    }

    protected function record(Source $source, ?DateTime $synced, ?string $error): void
    {
        $columns = ['lastError' => $error !== null ? mb_substr($error, 0, 2000) : null];

        if ($synced) {
            $columns['lastSynced'] = Db::prepareDateForDb($synced);
            $source->lastSynced = $synced;
        }

        $source->lastError = $columns['lastError'];
        Db::update(Install::SOURCES, $columns, ['id' => $source->id]);
        Craft::$app->getCache()->delete('reviews:sync-queued:'.$source->id);
    }

    protected function forgetSummaries(int $sourceId): void
    {
        $elements = (new Query)->from(Install::REVIEWS)->select('elementId')->distinct()->where(['sourceId' => $sourceId])->column();

        foreach ($elements as $elementId) {
            Plugin::getInstance()->reviews->changed((int) $elementId);
        }
    }

    protected function encodeSettings(Source $source): string
    {
        $fields = $source->platform()?->fields() ?? [];
        $security = Craft::$app->getSecurity();
        $settings = [];

        foreach ($source->settings as $key => $value) {
            $value = (string) $value;

            // A secret is encrypted, unless it's an environment variable.
            if (($fields[$key]['secret'] ?? false) && $value !== '' && ! str_starts_with($value, '$')) {
                $value = 'enc:'.base64_encode($security->encryptByKey($value));
            }

            $settings[$key] = $value;
        }

        return Json::encode($settings);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function fromRow(array $row): Source
    {
        $settings = [];
        $security = Craft::$app->getSecurity();

        foreach ((array) Json::decodeIfJson((string) ($row['settings'] ?? '')) as $key => $value) {
            $value = (string) $value;

            if (str_starts_with($value, 'enc:')) {
                $decrypted = $security->decryptByKey((string) base64_decode(substr($value, 4)));
                $value = is_string($decrypted) ? $decrypted : '';
            }

            $settings[(string) $key] = $value;
        }

        return new Source([
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'name' => (string) $row['name'],
            'settings' => $settings,
            'enabled' => (bool) $row['enabled'],
            'countInSummary' => (bool) $row['countInSummary'],
            'businessElementId' => $row['businessElementId'] !== null ? (int) $row['businessElementId'] : null,
            'frequency' => (int) $row['frequency'],
            'lastSynced' => $row['lastSynced'] ? new DateTime($row['lastSynced'], new \DateTimeZone('UTC')) : null,
            'lastError' => $row['lastError'],
            'uid' => $row['uid'],
        ]);
    }
}
