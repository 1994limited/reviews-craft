<?php

namespace nineteenninetyfour\reviews\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\models\Volume;
use craft\web\UploadedFile;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\Plugin;
use Throwable;

/**
 * Reviewers' photos. They wait in Craft's storage folder, off the site,
 * while the review is moderated, and move into a volume when it's approved
 * (and back out if it's rejected later). Each is re-saved as a JPEG no
 * bigger than it needs to be, which leaves the camera's metadata, location
 * included, behind.
 */
class Photos extends Component
{
    public const TYPES = ['jpg', 'jpeg', 'png', 'webp'];

    /** The most pixels a photo may have before it's decoded. */
    public const MAX_PIXELS = 40_000_000;

    public const MAX_DIMENSION = 2000;

    public function enabled(): bool
    {
        return Plugin::getInstance()->getSettings()->photosEnabled && extension_loaded('gd');
    }

    public function max(): int
    {
        return max(1, min(10, Plugin::getInstance()->getSettings()->photosMax));
    }

    /**
     * Re-save uploaded photos off the site, against the review. Anything
     * that isn't a readable image is left out.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function store(Review $review, array $files): void
    {
        $review->uid ??= StringHelper::UUID();

        foreach (array_slice($files, 0, max(0, $this->max() - count($review->photos))) as $file) {
            $contents = $this->reencode($file->tempName);

            if ($contents === null) {
                continue;
            }

            $path = 'reviews/pending/'.$review->uid.'/'.StringHelper::randomString(16).'.jpg';

            if ($this->write($path, $contents)) {
                $review->photos[] = ['pending' => $path];
            }
        }
    }

    /**
     * An image as a JPEG within the size limit, the right way up, without
     * its metadata. Null if it can't be read as an image.
     */
    public function reencode(?string $path): ?string
    {
        if (! $path || ! is_file($path) || ! extension_loaded('gd')) {
            return null;
        }

        // A small file can unpack into an enormous image ("decompression
        // bomb"): refuse anything over 40 megapixels before decoding it.
        $size = @getimagesize($path);

        if (! is_array($size) || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }

        if (! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return null;
        }

        try {
            $image = @imagecreatefromstring((string) file_get_contents($path));

            if (! $image) {
                return null;
            }

            $image = $this->orient($image, $path, $size[2]);
            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, self::MAX_DIMENSION / max($width, $height));

            // A white background, for transparent PNGs and WebPs.
            $canvas = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $width, $height);

            ob_start();
            imagejpeg($canvas, null, 85);

            return (string) ob_get_clean();
        } catch (Throwable) {
            return null;
        }
    }

    protected function orient(\GdImage $image, string $path, int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };

        $rotated = $rotated ?: $image;

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
        }

        return $rotated;
    }

    /**
     * Copy waiting photos into the volume, and point the review at the
     * copies. The private files stay until finish(); undo() removes the
     * copies if the review can't be saved. A photo that can't be copied
     * stays waiting, as it was.
     *
     * @return array{created: array<int, array<string, int|string>>, remove: array<int, array<string, int|string>>}
     */
    public function publish(Review $review): array
    {
        $done = ['created' => [], 'remove' => []];
        $volume = $review->hasPendingPhotos() ? $this->volume() : null;

        if (! $volume) {
            return $done;
        }

        $settings = Plugin::getInstance()->getSettings();

        foreach ($review->photos as $i => $photo) {
            if (! isset($photo['pending'])) {
                continue;
            }

            try {
                $contents = $this->read((string) $photo['pending']);

                if ($contents === null) {
                    continue;
                }

                $temp = Craft::$app->getPath()->getTempPath().'/'.StringHelper::randomString(12).'.jpg';
                file_put_contents($temp, $contents);

                $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume(trim($settings->photosFolder, '/').'/', $volume);

                $asset = new Asset;
                $asset->tempFilePath = $temp;
                $asset->setFilename('review-'.$review->id.'-'.($i + 1).'.jpg');
                $asset->newFolderId = $folder->id;
                $asset->setVolumeId($volume->id);
                $asset->avoidFilenameConflicts = true;
                $asset->alt = Craft::t('reviews', 'Photo from {name}’s review', ['name' => $review->getDisplayName()]);
                $asset->setScenario(Asset::SCENARIO_CREATE);

                if (! Craft::$app->getElements()->saveElement($asset)) {
                    FileHelper::unlink($temp);

                    continue;
                }

                $review->photos[$i] = ['asset' => (int) $asset->id];
                $done['created'][] = ['asset' => (int) $asset->id];
                $done['remove'][] = $photo;
            } catch (Throwable $e) {
                Craft::error('Reviews couldn’t publish a photo: '.$e->getMessage(), 'reviews');
            }
        }

        return $done;
    }

    /**
     * Copy published photos back off the site, and point the review at the
     * copies. The assets are deleted by finish(), once the review is saved.
     *
     * @return array{created: array<int, array<string, int|string>>, remove: array<int, array<string, int|string>>}
     */
    public function unpublish(Review $review): array
    {
        $done = ['created' => [], 'remove' => []];
        $review->uid ??= StringHelper::UUID();

        foreach ($review->photos as $i => $photo) {
            if (! isset($photo['asset'])) {
                continue;
            }

            $asset = Asset::find()->id((int) $photo['asset'])->one();

            if (! $asset) {
                unset($review->photos[$i]);

                continue;
            }

            try {
                $path = 'reviews/pending/'.$review->uid.'/'.StringHelper::randomString(16).'.jpg';

                if (! $this->write($path, $asset->getContents())) {
                    continue;
                }

                $review->photos[$i] = ['pending' => $path];
                $done['created'][] = ['pending' => $path];
                $done['remove'][] = $photo;
            } catch (Throwable $e) {
                Craft::error('Reviews couldn’t take a photo off the site: '.$e->getMessage(), 'reviews');
            }
        }

        $review->photos = array_values($review->photos);

        return $done;
    }

    /**
     * @param  array{created: array<int, array<string, int|string>>, remove: array<int, array<string, int|string>>}  $done
     */
    public function finish(array $done): void
    {
        $this->deleteFiles($done['remove']);
    }

    /**
     * @param  array{created: array<int, array<string, int|string>>, remove: array<int, array<string, int|string>>}  $done
     */
    public function undo(array $done): void
    {
        $this->deleteFiles($done['created']);
    }

    /**
     * Remove a review's photos, wherever they are.
     */
    public function delete(Review $review): void
    {
        $this->deleteFiles($review->photos);
        $review->photos = [];
    }

    /**
     * @param  array<int, array<string, int|string>>  $photos
     */
    public function deleteFiles(array $photos): void
    {
        foreach ($photos as $photo) {
            try {
                if (isset($photo['asset'])) {
                    $asset = Asset::find()->id((int) $photo['asset'])->one();

                    if ($asset) {
                        Craft::$app->getElements()->deleteElement($asset, true);
                    }
                } elseif (isset($photo['pending'])) {
                    $file = $this->path((string) $photo['pending']);

                    if ($file && is_file($file)) {
                        FileHelper::unlink($file);
                    }
                }
            } catch (Throwable $e) {
                Craft::warning('Reviews couldn’t remove a photo: '.$e->getMessage(), 'reviews');
            }
        }
    }

    /**
     * A waiting photo's contents, for the control panel.
     */
    public function pendingContents(Review $review, int $index): ?string
    {
        $photo = $review->photos[$index] ?? null;

        return is_array($photo) && isset($photo['pending']) ? $this->read((string) $photo['pending']) : null;
    }

    public function volume(): ?Volume
    {
        $handle = Plugin::getInstance()->getSettings()->photosVolume;

        return $handle !== '' ? Craft::$app->getVolumes()->getVolumeByHandle($handle) : null;
    }

    /**
     * A waiting photo's place in the storage folder. Null for anything
     * outside reviews/pending.
     */
    protected function path(string $relative): ?string
    {
        if (! preg_match('~^reviews/pending/[A-Za-z0-9-]+/[A-Za-z0-9]+\.jpg$~', $relative)) {
            return null;
        }

        return Craft::$app->getPath()->getStoragePath().'/'.$relative;
    }

    protected function read(string $relative): ?string
    {
        $file = $this->path($relative);

        return $file && is_file($file) ? (string) file_get_contents($file) : null;
    }

    protected function write(string $relative, string $contents): bool
    {
        $file = $this->path($relative);

        if (! $file) {
            return false;
        }

        FileHelper::createDirectory(dirname($file));

        return file_put_contents($file, $contents) !== false;
    }
}
