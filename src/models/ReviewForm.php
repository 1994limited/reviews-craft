<?php

namespace nineteenninetyfour\reviews\models;

use Craft;
use craft\base\Model;
use craft\web\UploadedFile;
use nineteenninetyfour\reviews\Plugin;
use nineteenninetyfour\reviews\services\Photos;

/**
 * What a visitor sent from the review form, checked.
 */
class ReviewForm extends Model
{
    public mixed $rating = null;

    /** @var array<string, mixed> */
    public array $ratings = [];

    public ?string $title = null;

    public ?string $body = null;

    public ?string $name = null;

    public ?string $email = null;

    /** @var array<int, UploadedFile> */
    public array $photos = [];

    /** @var array<int, int> */
    public array $removePhotos = [];

    /** Whether the reviewer is signed in (their account gives name and email). */
    public bool $signedIn = false;

    /** Photos the review keeps from before, when it's being changed. */
    public int $keptPhotos = 0;

    public function attributeLabels(): array
    {
        $labels = [
            'rating' => Craft::t('reviews', 'Rating'),
            'title' => Craft::t('reviews', 'Title'),
            'body' => Craft::t('reviews', 'Review'),
            'name' => Craft::t('reviews', 'Name'),
            'email' => Craft::t('reviews', 'Email'),
            'photos' => Craft::t('reviews', 'Photos'),
        ];

        foreach (Plugin::getInstance()->reviews->criteria() as $criterion) {
            $labels['ratings.'.$criterion['handle']] = $criterion['label'];
        }

        return $labels;
    }

    protected function defineRules(): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $rules = [
            [['rating'], 'required', 'message' => Craft::t('reviews', 'Choose a rating.')],
            [['rating'], 'integer', 'min' => 1, 'max' => 5],
            [['title'], 'string', 'max' => max(1, $settings->titleLimit)],
            [['body'], 'string', 'max' => max(1, $settings->bodyLimit), 'min' => max(0, $settings->bodyMinimum)],
            [['name'], 'string', 'max' => max(1, $settings->nameLimit)],
            [['email'], 'string', 'max' => 254],
            [['email'], 'email'],
            [['ratings'], 'validateRatings'],
            [['photos'], 'validatePhotos'],
        ];

        if ($settings->requireTitle) {
            $rules[] = [['title'], 'required'];
        }

        if ($settings->requireBody) {
            $rules[] = [['body'], 'required'];
        }

        if (! $this->signedIn) {
            $rules[] = [['name', 'email'], 'required'];
        }

        $rules[] = [['title', 'body'], 'validateLinks'];

        return $rules;
    }

    public function validateRatings(): void
    {
        foreach (Plugin::getInstance()->reviews->criteria() as $criterion) {
            $value = $this->ratings[$criterion['handle']] ?? null;

            if ($value !== null && $value !== '' && (! is_numeric($value) || (int) $value != $value || $value < 1 || $value > 5)) {
                $this->addError('ratings.'.$criterion['handle'], Craft::t('reviews', '{attribute} must be from 1 to 5.', ['attribute' => $criterion['label']]));
            }
        }
    }

    public function validatePhotos(): void
    {
        $photos = Plugin::getInstance()->photos;

        if (! $photos->enabled() || $this->photos === []) {
            return;
        }

        if (count($this->photos) > max(0, $photos->max() - $this->keptPhotos)) {
            $this->addError('photos', Craft::t('reviews', 'Add no more than {max} photos.', ['max' => $photos->max()]));

            return;
        }

        $maxSize = max(100, Plugin::getInstance()->getSettings()->photosMaxSize) * 1024;

        foreach ($this->photos as $file) {
            if ($file->getHasError() || ! in_array(mb_strtolower($file->getExtension()), Photos::TYPES, true) || $file->size > $maxSize) {
                $this->addError('photos', Craft::t('reviews', 'Photos must be JPEG, PNG or WebP images under {size} MB.', ['size' => round($maxSize / 1048576, 1)]));

                return;
            }

            if (! $photos->readable($file->tempName)) {
                $this->addError('photos', Craft::t('reviews', '“{name}” couldn’t be read as a photo. Try saving it again as a JPEG.', ['name' => $file->name]));

                return;
            }
        }
    }

    public function validateLinks(string $attribute): void
    {
        if ($attribute === 'body' && Plugin::getInstance()->spam->tooManyLinks($this->title, $this->body)) {
            $this->addError('body', Craft::t('reviews', 'Reviews can’t have that many links.'));
        }
    }

    /**
     * The criteria ratings given, as whole numbers.
     *
     * @return array<string, int>
     */
    public function criteriaRatings(): array
    {
        $ratings = [];

        foreach (Plugin::getInstance()->reviews->criteria() as $criterion) {
            $value = $this->ratings[$criterion['handle']] ?? null;

            if (is_numeric($value) && $value >= 1 && $value <= 5) {
                $ratings[$criterion['handle']] = (int) $value;
            }
        }

        return $ratings;
    }
}
