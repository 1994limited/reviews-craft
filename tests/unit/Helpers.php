<?php

namespace nineteenninetyfour\reviews\tests\unit;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use DateTime;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\elements\ReviewStatus;
use nineteenninetyfour\reviews\Plugin;
use yii\web\Response;

/**
 * What the tests share: settings, and entries, users and reviews to test
 * with.
 */
trait Helpers
{
    protected function setUpReviews(): void
    {
        Craft::$app->getCache()->flush();
        FileHelper::removeDirectory(Craft::$app->getPath()->getStoragePath().'/reviews');

        $this->settings([
            'sections' => ['books', 'journal'],
            'who' => 'anyone',
            'autoApprove' => 'never',
            'minimumSeconds' => 0,
            'captcha' => '',
            'photosEnabled' => false,
            'photosVolume' => 'images',
            'requireBody' => true,
            'requireTitle' => false,
            'criteria' => [],
            'votesEnabled' => true,
            'schemaOutput' => 'manual',
            'notify' => [],
        ]);

        $this->plugin()->sync->client = null;
    }

    protected function plugin(): Plugin
    {
        return Plugin::getInstance();
    }

    /**
     * Set settings for this test, without saving them.
     *
     * @param  array<string, mixed>  $values
     */
    protected function settings(array $values): void
    {
        $settings = $this->plugin()->getSettings();

        foreach ($values as $key => $value) {
            $settings->$key = $value;
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $attributes
     */
    protected function entry(string $section = 'books', string $title = 'The Lighthouse Keeper', array $fields = [], array $attributes = []): Entry
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($section);
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'title' => $title,
            'slug' => $attributes['slug'] ?? StringHelper::slugify($title).'-'.bin2hex(random_bytes(2)),
            'postDate' => new DateTime('2026-10-02 12:00:00'),
            ...$attributes,
        ]);
        $entry->setFieldValues($fields);

        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), json_encode($entry->getErrors()));

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function user(string $name = 'Mira Castellane', array $attributes = []): User
    {
        $user = new User(['username' => strtolower(explode(' ', $name)[0]).bin2hex(random_bytes(2)), 'email' => bin2hex(random_bytes(3)).'@example.com', 'fullName' => $name, ...$attributes]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($user), json_encode($user->getErrors()));

        return $user;
    }

    /**
     * A review, saved.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function review(Entry $entry, int $rating = 5, ReviewStatus $status = ReviewStatus::Approved, array $attributes = []): Review
    {
        $review = new Review([
            'elementId' => $entry->id,
            'reviewSiteId' => $entry->siteId,
            'rating' => $rating,
            'title' => 'Lovely',
            'body' => 'A good read.',
            'name' => 'Jo Bloggs',
            'email' => bin2hex(random_bytes(3)).'@example.com',
            'reviewStatus' => $status,
            ...$attributes,
        ]);

        $this->assertTrue($this->plugin()->reviews->save($review), json_encode($review->getErrors()));

        return $review;
    }

    /**
     * A user with these permissions (an admin with none given), signed in.
     *
     * @param  array<int, string>|null  $permissions
     */
    protected function actingAs(?array $permissions = null): User
    {
        $user = $this->user('Ada Lovelace', ['admin' => $permissions === null]);

        if ($permissions !== null) {
            Craft::$app->getUserPermissions()->saveUserPermissions($user->id, ['accesscp', ...$permissions]);
        }

        Craft::$app->getUser()->setIdentity($user);

        return $user;
    }

    /**
     * Run a controller action as a web request would.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $body
     */
    protected function action(string $route, array $params = [], string $method = 'POST', array $body = [], bool $json = true): ?Response
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['HTTP_ACCEPT'] = $json ? 'application/json' : 'text/html';
        $request = Craft::$app->getRequest();
        $request->setBodyParams($body);
        $request->setQueryParams($params);
        $request->getHeaders()->set('Accept', $json ? 'application/json' : 'text/html');
        Craft::$app->getResponse()->clear();

        $result = Craft::$app->runAction($route, $params);

        return $result instanceof Response ? $result : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(?Response $response): array
    {
        $this->assertNotNull($response);

        return is_array($response->data) ? $response->data : (json_decode((string) $response->content, true) ?? []);
    }
}
