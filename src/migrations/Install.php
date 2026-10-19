<?php

namespace nineteenninetyfour\reviews\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Reviews' tables. A review is an element: its title is the element's, and
 * everything else it says lives in reviews_reviews.
 */
class Install extends Migration
{
    public const REVIEWS = '{{%reviews_reviews}}';

    public const VOTES = '{{%reviews_votes}}';

    public const SOURCES = '{{%reviews_sources}}';

    public const REQUESTS = '{{%reviews_requests}}';

    public const OPT_OUTS = '{{%reviews_optouts}}';

    public function safeUp(): bool
    {
        $this->createTable(self::REVIEWS, [
            'id' => $this->integer()->notNull(),
            'elementId' => $this->integer(),
            'reviewSiteId' => $this->integer()->notNull(),
            'rating' => $this->tinyInteger()->unsigned()->notNull(),
            'ratings' => $this->text(),
            'body' => $this->mediumText(),
            'name' => $this->string(255)->notNull()->defaultValue(''),
            'email' => $this->string(255),
            'userId' => $this->integer(),
            'reviewStatus' => $this->string(20)->notNull()->defaultValue('pending'),
            'verified' => $this->boolean()->notNull()->defaultValue(false),
            'orderId' => $this->integer(),
            'reply' => $this->text(),
            'replyById' => $this->integer(),
            'dateReplied' => $this->dateTime(),
            'note' => $this->text(),
            'photos' => $this->text(),
            'helpful' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'dateApproved' => $this->dateTime(),
            'counted' => $this->boolean()->notNull()->defaultValue(true),
            'source' => $this->string(40),
            'sourceId' => $this->integer(),
            'sourceUrl' => $this->string(500),
            'fingerprint' => $this->char(64),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, self::REVIEWS, ['elementId', 'reviewStatus', 'counted']);
        $this->createIndex(null, self::REVIEWS, ['reviewStatus']);
        $this->createIndex(null, self::REVIEWS, ['email']);
        $this->createIndex(null, self::REVIEWS, ['userId']);
        $this->createIndex(null, self::REVIEWS, ['sourceId']);
        $this->createIndex(null, self::REVIEWS, ['fingerprint'], true);
        $this->addForeignKey(null, self::REVIEWS, ['id'], Table::ELEMENTS, ['id'], 'CASCADE');
        // Not cascading: a deleted element's reviews are deleted as elements,
        // with their photos (see Plugin::elementsDeleted()).
        $this->addForeignKey(null, self::REVIEWS, ['elementId'], Table::ELEMENTS, ['id'], 'SET NULL');
        $this->addForeignKey(null, self::REVIEWS, ['reviewSiteId'], Table::SITES, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, self::REVIEWS, ['userId'], Table::USERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, self::REVIEWS, ['replyById'], Table::USERS, ['id'], 'SET NULL');

        $this->createTable(self::VOTES, [
            'id' => $this->primaryKey(),
            'reviewId' => $this->integer()->notNull(),
            'voter' => $this->char(64)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, self::VOTES, ['reviewId', 'voter'], true);
        $this->addForeignKey(null, self::VOTES, ['reviewId'], self::REVIEWS, ['id'], 'CASCADE');

        $this->createTable(self::SOURCES, [
            'id' => $this->primaryKey(),
            'type' => $this->string(40)->notNull(),
            'name' => $this->string(255)->notNull(),
            'settings' => $this->text(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'countInSummary' => $this->boolean()->notNull()->defaultValue(false),
            'businessElementId' => $this->integer(),
            'frequency' => $this->smallInteger()->unsigned()->notNull()->defaultValue(24),
            'lastSynced' => $this->dateTime(),
            'lastError' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey(null, self::SOURCES, ['businessElementId'], Table::ELEMENTS, ['id'], 'SET NULL');
        $this->addForeignKey(null, self::REVIEWS, ['sourceId'], self::SOURCES, ['id'], 'SET NULL');

        $this->createTable(self::REQUESTS, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'email' => $this->string(255)->notNull(),
            'token' => $this->char(40)->notNull(),
            'siteId' => $this->integer(),
            'dateDue' => $this->dateTime()->notNull(),
            'dateSent' => $this->dateTime(),
            'dateOpened' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::REQUESTS, ['orderId'], true);
        $this->createIndex(null, self::REQUESTS, ['token'], true);
        $this->createIndex(null, self::REQUESTS, ['dateDue', 'dateSent']);
        $this->addForeignKey(null, self::REQUESTS, ['siteId'], Table::SITES, ['id'], 'SET NULL');

        // People who asked for no more review request emails, by a hash of
        // their address.
        $this->createTable(self::OPT_OUTS, [
            'id' => $this->primaryKey(),
            'emailHash' => $this->char(64)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, self::OPT_OUTS, ['emailHash'], true);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::OPT_OUTS);
        $this->dropTableIfExists(self::REQUESTS);
        $this->dropTableIfExists(self::VOTES);
        $this->dropTableIfExists(self::REVIEWS);
        $this->dropTableIfExists(self::SOURCES);

        return true;
    }
}
