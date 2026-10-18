<?php

namespace nineteenninetyfour\reviews\tests\unit;

use Craft;
use craft\models\GqlSchema;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\elements\ReviewStatus;
use yii\web\Response;

class GqlAndConsoleTest extends TestCase
{
    /**
     * @param  array<int, string>  $scope
     * @return array<string, mixed>
     */
    protected function graphql(string $query, array $scope): array
    {
        $schema = new GqlSchema(['name' => 'Test', 'scope' => $scope]);
        Craft::$app->getGql()->invalidateCaches();
        Craft::$app->getGql()->setActiveSchema($schema);

        return Craft::$app->getGql()->executeQuery($schema, $query);
    }

    public function test_graphql_gives_approved_reviews_to_schemas_allowed_them(): void
    {
        $entry = $this->entry();
        $this->review($entry, 5, ReviewStatus::Approved, ['title' => 'Shown', 'name' => 'Ada Lovelace']);
        $this->review($entry, 1, ReviewStatus::Pending, ['title' => 'Waiting']);

        $result = $this->graphql('{ reviews { title rating name } reviewCount }', ['reviews:read']);

        $this->assertArrayNotHasKey('errors', $result, json_encode($result));
        $this->assertSame([['title' => 'Shown', 'rating' => 5, 'name' => 'Ada L.']], $result['data']['reviews']);
        $this->assertSame(1, $result['data']['reviewCount']);
    }

    public function test_graphql_without_the_scope_has_no_reviews(): void
    {
        $this->review($this->entry());

        $result = $this->graphql('{ reviews { title } }', []);

        // Either the query isn't there, or it finds nothing.
        $this->assertEmpty($result['data']['reviews'] ?? []);
    }

    /**
     * A console action's exit code, however the test app wraps it.
     *
     * @param  array<int, string>  $params
     */
    protected function console(string $route, array $params): int
    {
        $result = Craft::$app->runAction($route, $params);

        return (int) ($result instanceof Response ? $result->data : $result);
    }

    public function test_export_then_import_round_trips(): void
    {
        $entry = $this->entry('books', 'Round Trip');
        $this->review($entry, 4, ReviewStatus::Approved, ['title' => 'There and back', 'email' => 'trip@example.com']);
        $file = Craft::$app->getPath()->getTempPath().'/reviews-export.csv';

        $this->assertSame(0, $this->console('reviews/export', [$file]));
        $csv = (string) file_get_contents($file);
        $this->assertStringContainsString('There and back', $csv);

        $result = $this->plugin()->imports->read($csv);
        $this->assertSame('reviews', $result->format?->key());
        $this->assertSame(4, $result->reviews[0]->rating);
        $this->assertSame(['id' => (string) $entry->id, 'name' => 'Round Trip'], $result->reviews[0]->product);
    }

    public function test_privacy_export_lists_someone_s_reviews(): void
    {
        $this->review($this->entry(), 3, ReviewStatus::Pending, ['email' => 'who@example.com', 'body' => 'Mine']);

        $file = Craft::$app->getPath()->getTempPath().'/reviews-privacy.json';
        $code = $this->console('reviews/privacy/export', ['who@example.com', $file]);
        $output = (string) file_get_contents($file);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('"body": "Mine"', $output);
        $this->assertSame(1, (int) Review::find()->status(null)->email('who@example.com')->count());
    }
}
