<?php

namespace nineteenninetyfour\reviews\gql;

use craft\gql\types\elements\Element;

class ReviewObject extends Element
{
    public function __construct(array $config)
    {
        $config['interfaces'] = [ReviewInterface::getType()];

        parent::__construct($config);
    }
}
