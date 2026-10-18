# Import and sync

Bring reviews from another platform: once, from a file it exported (moving to Reviews, say), or on a schedule, from its API (keeping a platform you still use in step).

## Importing a file

Under **Reviews → Import**, upload a CSV or JSON file. Reviews reads:

| Platform | Its export | Matched to products by |
| --- | --- | --- |
| Reviews (Craft or Statamic) | `php craft reviews/export`, or Statamic's `php please reviews:export-all` | ID, or exact title |
| Yotpo | Moderation → Export | SKU, handle or page address |
| Judge.me | Its export, or its import template | Shopify handle |
| REVIEWS.io | Its export, or its import template | SKU |
| Shopify Product Reviews | Its export | Shopify handle |
| Trustpilot | Business → Reviews → Export | SKU, if given |
| WooCommerce | A review export plugin, or WP All Export | SKU, page address or post ID |
| Anything else | A CSV or JSON file with a rating column | Whatever product column it has |

The format is worked out from the columns. Reviews are matched to Commerce products by SKU (or slug), and to entries by slug, page address or exact title.

**Preview** shows how many would be added, how many are here already, and which matched nothing, before anything is added. Then **Import**. A review is never added twice: importing the same file again adds nothing.

Options:

- **Count them as this site's own reviews.** For reviews your customers wrote for you on a platform you're leaving. They then count towards ratings and go in your schema. Leave it off for reviews gathered elsewhere.
- **Status.** Approved, if the platform showed them, or waiting for an editor.
- **Include reviews the platform wasn't showing.** Added as rejected, so they're on record.
- **Reviews of the business go on.** Company reviews aren't of a product: choose the entry they belong to (a reviews page, say), or they're left out.

For files over 20 MB, use the [console](commands.md#reviewsimport).

### Moving between sites

`php craft reviews/export reviews.csv` writes every review as a CSV that Reviews (for Craft or Statamic) imports: what each says, who wrote it, its status, the reply and dates, and where an imported one came from, with its link there. Photos stay on the old site, as assets. Reviews synced from a platform are best synced again on the new site, from the same source, rather than moved.

Every cell is safe to open in a spreadsheet: one starting with `=`, `+`, `-` or `@` gets a `'` in front, so nothing a reviewer wrote can run as a formula. Reviews takes it off again when it imports the file.

## Syncing

Under **Reviews → Sync**, add a source:

| Platform | Settings | |
| --- | --- | --- |
| Feefo | Merchant identifier | No key needed. A review rating the service and products becomes one review each. |
| REVIEWS.io | Store ID, API key (optional) | Company and product reviews. |
| Judge.me | Shop domain, private API token | |
| Yotpo | App key, secret key | Only reviews changed since the last sync are fetched. |
| Trustpilot | Business unit ID, API key, profile address, SKUs | Needs Trustpilot's paid API module. |

Keys are kept in the database, encrypted, not in Project Config. Or use environment variables (`$JUDGEME_TOKEN`).

Each source syncs every 24 hours (**Hours between syncs**), from Craft's queue, while someone's using the control panel. To keep them up to date otherwise, run the sync from cron:

```
0 * * * * php /path/to/craft reviews/sync
```

A sync adds new reviews and follows what the platform changes (a reply, a name, a verified badge, photos). When the platform takes a review down, so does the sync; when an editor here rejects one, the sync leaves it rejected.

A sync that fails says why on the Sync page, and is tried again next time. So is one where a review couldn't be saved: it isn't counted as done, so that review is fetched again rather than missed. A source syncs once at a time, however it's started: **Sync now** while it's already syncing says so.

### Not synced: Google

Google's terms don't allow keeping its reviews: the Business Profile API only for 30 days, and the Places API not at all. Show Google reviews with Google's own widget.

## Third-party reviews and schema

Google's guidelines say not to mark up reviews gathered from other websites. So imported and synced reviews:

- show where they came from ("From Trustpilot"), with a link to them there, as most platforms ask;
- don't count towards the average or go in your schema, unless you count an import as your own, or a source is from a platform that allows it (only Feefo does: **Count towards ratings and schema** on its source).

Trustpilot asks that its reviews are shown with its logo and a link to your profile there: add those to your template beside them.
