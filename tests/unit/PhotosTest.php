<?php

namespace nineteenninetyfour\reviews\tests\unit;

use craft\elements\Asset;
use craft\web\UploadedFile;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\elements\ReviewStatus;

class PhotosTest extends TestCase
{
    protected function _before(): void
    {
        parent::_before();

        $this->settings(['photosEnabled' => true, 'photosVolume' => 'images', 'photosFolder' => 'reviews']);
    }

    protected function upload(int $width = 300, int $height = 200): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
        $path = sys_get_temp_dir().'/reviews-test-'.bin2hex(random_bytes(4)).'.png';
        imagepng($image, $path);

        return new UploadedFile(['name' => 'photo.png', 'tempName' => $path, 'type' => 'image/png', 'size' => filesize($path), 'error' => UPLOAD_ERR_OK]);
    }

    public function test_photos_wait_off_the_site_then_move_into_the_volume(): void
    {
        $review = $this->review($this->entry(), 5, ReviewStatus::Pending);
        $photos = $this->plugin()->photos;

        $photos->store($review, [$this->upload()]);
        $this->plugin()->reviews->save($review);

        $this->assertCount(1, $review->photos);
        $this->assertArrayHasKey('pending', $review->photos[0]);
        $this->assertSame([], $review->getPublicPhotos());
        $this->assertStringStartsWith("\xFF\xD8", (string) $photos->pendingContents($review, 0));

        $this->plugin()->reviews->approve($review);

        $loaded = Review::find()->id($review->id)->one();
        $this->assertArrayHasKey('asset', $loaded->photos[0]);
        $asset = Asset::find()->id($loaded->photos[0]['asset'])->one();
        $this->assertSame('jpg', $asset->getExtension());
        $this->assertStringContainsString('Jo B.', (string) $asset->alt);
        $this->assertCount(1, $loaded->getPublicPhotos());

        // Rejected: off the site again.
        $this->plugin()->reviews->reject($loaded);
        $this->assertArrayHasKey('pending', Review::find()->id($review->id)->status(null)->one()->photos[0]);
        $this->assertNull(Asset::find()->id($asset->id)->one());
    }

    public function test_big_photos_are_made_smaller_and_lose_their_metadata(): void
    {
        $upload = $this->upload(3000, 1000);
        $jpeg = (string) $this->plugin()->photos->reencode($upload->tempName);

        $size = getimagesizefromstring($jpeg);
        $this->assertSame(2000, $size[0]);
        $this->assertStringNotContainsString('Exif', $jpeg);
    }

    public function test_anything_but_an_image_is_left_out(): void
    {
        $path = sys_get_temp_dir().'/reviews-test.php.png';
        file_put_contents($path, '<?php echo "hi";');

        $this->assertNull($this->plugin()->photos->reencode($path));
    }

    public function test_deleting_a_review_deletes_its_photos(): void
    {
        $review = $this->review($this->entry(), 5, ReviewStatus::Pending);
        $this->plugin()->photos->store($review, [$this->upload()]);
        $this->plugin()->reviews->save($review);
        $this->plugin()->reviews->approve($review);
        $assetId = $review->photos[0]['asset'];

        $this->plugin()->reviews->delete($review);

        $this->assertNull(Asset::find()->id($assetId)->one());
    }
}
