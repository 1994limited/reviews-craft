<?php

namespace nineteenninetyfour\reviews\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Reviews' settings, kept in project config and changed under Settings →
 * Reviews. Anything here can be fixed in config/reviews.php instead, per
 * environment, as with any Craft plugin.
 */
class Settings extends Model
{
    /** Section handles whose entries take reviews. Empty for none. */
    public array $sections = [];

    /** Commerce product type handles whose products take reviews. */
    public array $productTypes = [];

    /**
     * "shared": an entry or product has the same reviews on every site (its
     * translations, say). "separate": each site has its own reviews, average
     * and schema (separate stores, say).
     */
    public string $sites = 'shared';

    /** "anyone", or "users" for signed-in users only. */
    public string $who = 'anyone';

    /** Only people who bought a product may review it (needs Commerce). */
    public bool $buyersOnly = false;

    /** "never", "users" or "verified". Never decided by rating. */
    public string $autoApprove = 'never';

    public bool $requireTitle = false;

    public bool $requireBody = true;

    public int $bodyMinimum = 0;

    public int $bodyLimit = 5000;

    public int $titleLimit = 120;

    public int $nameLimit = 60;

    /** "short" (Jo B.) or "full". */
    public string $nameDisplay = 'short';

    /** Criteria rated besides the overall rating: [['handle' => 'value', 'label' => 'Value for money'], ...]. */
    public array $criteria = [];

    public bool $votesEnabled = true;

    public bool $photosEnabled = false;

    public int $photosMax = 4;

    /** Kilobytes. */
    public int $photosMaxSize = 8192;

    /** The volume approved photos go in (its handle). */
    public string $photosVolume = '';

    public string $photosFolder = 'reviews';

    /** "", turnstile, hcaptcha, recaptcha, recaptcha_v3, or a class implementing Core\Captcha\Captcha. */
    public string $captcha = '';

    public string $captchaSiteKey = '';

    public string $captchaSecretKey = '';

    public float $captchaScore = 0.5;

    /** Forms sent sooner than this after the page loaded are spam. */
    public int $minimumSeconds = 3;

    public int $maxLinks = 2;

    /** Addresses told about each new review. */
    public array $notify = [];

    /** Email reviewers when their review goes up. */
    public bool $notifyApproved = false;

    /** Email reviewers when the site replies to their review. */
    public bool $notifyReplied = false;

    /** Reviews a visitor can send in ten minutes, from one address. */
    public int $throttleSubmit = 5;

    /** "Helpful" votes a visitor can make in a minute. */
    public int $throttleVotes = 30;

    /** When a user is deleted, "anonymise" their reviews (keeping what they wrote), or "delete" them. */
    public string $deletedUsers = 'anonymise';

    /** Review request emails after an order (needs Commerce). */
    public bool $requestsEnabled = false;

    public int $requestsDelayDays = 14;

    /** Order status handles an order must reach before asking. Empty for any completed order. */
    public array $requestsOrderStatuses = [];

    /** schema.org type of entries' reviews, when Reviews adds the JSON-LD. */
    public string $schemaType = 'Product';

    /**
     * "manual": templates add it with craft.reviews.schema(); "auto": Reviews
     * adds it to every reviewed page; "seomatic": into SEOmatic's own
     * JSON-LD; "off".
     */
    public string $schemaOutput = 'manual';

    public int $perPage = 10;

    public function captchaSecret(): string
    {
        return (string) App::parseEnv($this->captchaSecretKey);
    }

    public function captchaSite(): string
    {
        return (string) App::parseEnv($this->captchaSiteKey);
    }

    protected function defineRules(): array
    {
        return [
            [['who'], 'in', 'range' => ['anyone', 'users']],
            [['deletedUsers'], 'in', 'range' => ['anonymise', 'delete']],
            [['throttleSubmit', 'throttleVotes'], 'integer', 'min' => 1],
            [['sites'], 'in', 'range' => ['shared', 'separate']],
            [['autoApprove'], 'in', 'range' => ['never', 'users', 'verified']],
            [['nameDisplay'], 'in', 'range' => ['short', 'full']],
            [['schemaOutput'], 'in', 'range' => ['manual', 'auto', 'seomatic', 'off']],
            [['bodyLimit', 'titleLimit', 'nameLimit', 'photosMaxSize', 'perPage'], 'integer', 'min' => 1],
            [['photosMax'], 'integer', 'min' => 1, 'max' => 10],
            [['bodyMinimum', 'minimumSeconds', 'maxLinks'], 'integer', 'min' => 0],
            [['requestsDelayDays'], 'integer', 'min' => 0, 'max' => 365],
            [['captchaScore'], 'number', 'min' => 0, 'max' => 1],
            [['schemaType'], 'match', 'pattern' => '/^[A-Z][A-Za-z]{1,40}$/'],
            [['notify'], 'each', 'rule' => ['email']],
        ];
    }
}
