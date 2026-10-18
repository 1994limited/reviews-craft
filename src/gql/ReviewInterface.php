<?php

namespace nineteenninetyfour\reviews\gql;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;

class ReviewInterface extends Element
{
    public static function getTypeGenerator(): string
    {
        return ReviewType::class;
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class.'::getFieldDefinitions',
            'description' => 'This is the interface implemented by all reviews.',
            'resolveType' => self::class.'::resolveElementTypeName',
        ]));

        ReviewType::generateTypes();

        return $type;
    }

    public static function getName(): string
    {
        return 'ReviewInterface';
    }

    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(parent::getFieldDefinitions(), [
            'rating' => ['name' => 'rating', 'type' => Type::nonNull(Type::int()), 'description' => 'The rating, from 1 to 5.'],
            'ratings' => [
                'name' => 'ratings',
                'type' => Type::string(),
                'description' => 'The criteria ratings, as JSON: {"value": 4}.',
                'resolve' => fn ($review) => $review->ratings ? json_encode($review->ratings) : null,
            ],
            'body' => ['name' => 'body', 'type' => Type::string(), 'description' => 'What the reviewer wrote.'],
            'name' => [
                'name' => 'name',
                'type' => Type::string(),
                'description' => 'The reviewer’s name, as the site shows it.',
                'resolve' => fn ($review) => $review->getDisplayName(),
            ],
            'verified' => ['name' => 'verified', 'type' => Type::boolean(), 'description' => 'Whether the reviewer bought the product.'],
            'reply' => ['name' => 'reply', 'type' => Type::string(), 'description' => 'The site’s reply.'],
            'helpful' => ['name' => 'helpful', 'type' => Type::int(), 'description' => 'How many people found it helpful.'],
            'elementId' => ['name' => 'elementId', 'type' => Type::int(), 'description' => 'The ID of the entry or product reviewed.'],
            'source' => ['name' => 'source', 'type' => Type::string(), 'description' => 'The platform it came from, or null when written on this site.'],
            'sourceUrl' => ['name' => 'sourceUrl', 'type' => Type::string(), 'description' => 'Its page on the platform it came from.'],
            'photos' => [
                'name' => 'photos',
                'type' => Type::listOf(Type::string()),
                'description' => 'The addresses of its photos.',
                'resolve' => fn ($review) => array_column($review->getPublicPhotos(), 'url'),
            ],
        ]), self::getName());
    }
}
