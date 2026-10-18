<?php

namespace nineteenninetyfour\reviews\gql;

use craft\gql\base\ElementArguments;
use craft\gql\types\QueryArgument;
use GraphQL\Type\Definition\Type;

class ReviewArguments extends ElementArguments
{
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), [
            'elementId' => ['name' => 'elementId', 'type' => Type::listOf(QueryArgument::getType()), 'description' => 'Reviews of these entries or products.'],
            'rating' => ['name' => 'rating', 'type' => Type::listOf(QueryArgument::getType()), 'description' => 'Reviews with these ratings, or a comparison (">= 4").'],
            'verified' => ['name' => 'verified', 'type' => Type::boolean(), 'description' => 'Only reviews by verified buyers.'],
            'withPhotos' => ['name' => 'withPhotos', 'type' => Type::boolean(), 'description' => 'Only reviews with photos.'],
        ]);
    }
}
