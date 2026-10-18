<?php

namespace nineteenninetyfour\reviews\gql;

use Craft;
use craft\gql\base\Generator;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;

class ReviewType extends Generator implements GeneratorInterface, SingleGeneratorInterface
{
    public const NAME = 'Review';

    public static function generateTypes(mixed $context = null): array
    {
        $type = static::generateType($context);

        return [$type->name => $type];
    }

    public static function generateType(mixed $context): ReviewObject
    {
        return GqlEntityRegistry::getOrCreate(self::NAME, fn () => new ReviewObject([
            'name' => self::NAME,
            'fields' => fn () => Craft::$app->getGql()->prepareFieldDefinitions(ReviewInterface::getFieldDefinitions(), self::NAME),
        ]));
    }
}
