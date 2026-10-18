<?php

namespace nineteenninetyfour\reviews\gql;

use craft\elements\ElementCollection;
use craft\gql\base\ElementResolver;
use craft\helpers\Gql as GqlHelper;
use nineteenninetyfour\reviews\elements\Review;
use yii\base\UnknownMethodException;

/**
 * Approved reviews only, whatever's asked for.
 */
class ReviewResolver extends ElementResolver
{
    public static function prepareQuery(mixed $source, array $arguments, ?string $fieldName = null): mixed
    {
        if (! GqlHelper::canSchema('reviews', 'read')) {
            return ElementCollection::empty();
        }

        $query = Review::find();

        foreach ($arguments as $key => $value) {
            if ($key === 'status') {
                continue;
            }

            try {
                $query->$key($value);
            } catch (UnknownMethodException $e) {
                if ($value !== null) {
                    throw $e;
                }
            }
        }

        return $query->status(Review::STATUS_APPROVED);
    }
}
