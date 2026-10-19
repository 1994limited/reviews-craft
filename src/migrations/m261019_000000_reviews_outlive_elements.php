<?php

namespace nineteenninetyfour\reviews\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table;

/**
 * When a reviewed element is deleted, its reviews' rows stay, with no
 * element, until Reviews deletes them as elements: so their photos, and
 * their rows in Craft's own tables, go too.
 */
class m261019_000000_reviews_outlive_elements extends Migration
{
    public function safeUp(): bool
    {
        $this->dropForeignKeyIfExists(Install::REVIEWS, ['elementId']);
        $this->alterColumn(Install::REVIEWS, 'elementId', $this->integer());
        $this->addForeignKey(null, Install::REVIEWS, ['elementId'], Table::ELEMENTS, ['id'], 'SET NULL');

        // Deleted users' reviews were saved as "Anonymous" in whatever
        // language was active then: now they're left empty, and translated
        // when shown.
        $names = [];

        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $names[] = Craft::t('reviews', 'Anonymous', [], $site->language);
        }

        $this->update(Install::REVIEWS, ['name' => ''], ['name' => array_values(array_unique($names)), 'userId' => null, 'email' => null], [], false);

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261019_000000_reviews_outlive_elements cannot be reverted.\n";

        return false;
    }
}
