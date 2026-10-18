<?php

namespace nineteenninetyfour\reviews\gql;

use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;

/**
 * Reviews in GraphQL: `reviews`, `review` and `reviewCount`, for schemas
 * allowed to view them.
 */
class ReviewGql
{
    public static function interface(): string
    {
        return ReviewInterface::class;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function queries(): array
    {
        if (! GqlHelper::canSchema('reviews', 'read')) {
            return [];
        }

        return [
            'reviews' => [
                'type' => Type::listOf(ReviewInterface::getType()),
                'args' => ReviewArguments::getArguments(),
                'resolve' => ReviewResolver::class.'::resolve',
                'description' => 'Approved reviews.',
            ],
            'reviewCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => ReviewArguments::getArguments(),
                'resolve' => ReviewResolver::class.'::resolveCount',
                'description' => 'How many approved reviews there are.',
            ],
            'review' => [
                'type' => ReviewInterface::getType(),
                'args' => ReviewArguments::getArguments(),
                'resolve' => ReviewResolver::class.'::resolveOne',
                'description' => 'One approved review.',
            ],
        ];
    }
}
