# Console commands

## `reviews/import`

Import a CSV or JSON file another platform exported ([Import and sync](import-and-sync.md)). For files of any size.

```bash
php craft reviews/import exports/yotpo.csv --dry-run
php craft reviews/import exports/yotpo.csv --counted --business=12
```

| Option | |
| --- | --- |
| `--dry-run` | Say what would be added, and what matched nothing, without adding anything. |
| `--format` | `yotpo`, `judgeme`, `reviewsio`, `shopify`, `trustpilot`, `woocommerce`, `reviews` or `generic`, when it can't be worked out. |
| `--counted` | Count them as this site's own reviews. |
| `--pending` | Hold every review for an editor. |
| `--include-unpublished` | Add reviews the platform wasn't showing, as rejected. |
| `--business` | The entry ID reviews of the business go on. |
| `--site` | The site ID they were written on. |

## `reviews/export`

Every review, as a CSV that Reviews (for Craft or Statamic) imports, safe to open in a spreadsheet ([Moving between sites](import-and-sync.md#moving-between-sites)):

```bash
php craft reviews/export reviews.csv
```

## `reviews/sync`

Sync every source that's due. Run it from cron, hourly say. `reviews/sync 3` syncs one source now; `--force` syncs every enabled source, due or not; `--all` fetches everything again, not just what's changed.

## `reviews/requests/send`

Send the [review request emails](commerce.md#review-request-emails) that are due. They're sent from the queue anyway: this is for sites that would rather use cron, or whose queue lost a job.

## `reviews/privacy/export` and `reviews/privacy/forget`

See [Privacy](privacy.md#requests-for-someones-data).
