# Configuration

Change the settings under **Reviews → Settings**. They're kept in Project Config. To fix any of them per environment, add `config/reviews.php`: what's set there can't be changed in the control panel.

```php
<?php

use craft\helpers\App;

return [
    '*' => [
        'sections' => ['books', 'journal'],
        'autoApprove' => 'verified',
    ],
    'production' => [
        'captcha' => 'turnstile',
        'captchaSiteKey' => App::env('TURNSTILE_SITE_KEY'),
        'captchaSecretKey' => App::env('TURNSTILE_SECRET'),
    ],
];
```

| Setting | Default | |
| --- | --- | --- |
| `sections` | `[]` | Section handles whose entries take reviews. |
| `productTypes` | `[]` | Commerce product type handles whose products take reviews. |
| `sites` | `'shared'` | `'shared'` or `'separate'`: see [Multi-site](#multi-site). |
| `who` | `'anyone'` | `'anyone'` (guests give a name and email) or `'users'` (signed in). |
| `buyersOnly` | `false` | Only people who bought a product can review it. |
| `autoApprove` | `'never'` | `'never'`, `'users'` or `'verified'`. Never by rating. |
| `requireTitle` | `false` | |
| `requireBody` | `true` | Off, a star rating on its own is a review. |
| `bodyMinimum` | `0` | Fewest characters in a review's text. |
| `bodyLimit`, `titleLimit`, `nameLimit` | `5000`, `120`, `60` | Most characters. |
| `nameDisplay` | `'short'` | `'short'` ("Jo B.") or `'full'`. |
| `criteria` | `[]` | `[['handle' => 'story', 'label' => 'Story'], …]`: ratings for particular things, as well as the overall rating. Optional for reviewers. |
| `votesEnabled` | `true` | "Helpful" votes. |
| `photosEnabled` | `false` | [Photos](photos.md). |
| `photosVolume`, `photosFolder` | `''`, `'reviews'` | Where approved photos go: a volume handle and folder. |
| `photosMax`, `photosMaxSize` | `4`, `8192` | Photos per review, and the largest in kilobytes. |
| `captcha` | `''` | `'turnstile'`, `'hcaptcha'`, `'recaptcha'`, `'recaptcha_v3'`, or a class: [Spam](spam.md). |
| `captchaSiteKey`, `captchaSecretKey` | `''` | Environment variables (`'$TURNSTILE_SECRET'`) work. |
| `captchaScore` | `0.5` | reCAPTCHA v3's lowest passing score. |
| `minimumSeconds` | `3` | Forms sent sooner are a bot's. `0` turns the time check off. |
| `maxLinks` | `2` | |
| `throttleSubmit`, `throttleVotes` | `5`, `30` | Reviews per visitor in ten minutes, and votes a minute. |
| `notify` | `[]` | Addresses told about each new review. |
| `notifyApproved`, `notifyReplied` | `false` | Email reviewers when their review goes up, or you reply. |
| `requestsEnabled` | `false` | [Review request emails](commerce.md#review-request-emails). |
| `requestsDelayDays` | `14` | |
| `requestsOrderStatuses` | `[]` | Order status handles an order must reach first. |
| `excludedOrderStatuses` | `[]` | Order status handles that don't count as a purchase (cancelled, say), for verified reviews and request emails. Orders refunded in full never count. |
| `schemaOutput` | `'manual'` | `'manual'`, `'auto'`, `'seomatic'` or `'off'`: [Search engines](seo.md). |
| `schemaType` | `'Product'` | The schema type for entries. |
| `perPage` | `10` | Reviews per page in the ready-made list. |
| `deletedUsers` | `'anonymise'` | When a user is deleted: `'anonymise'` their reviews (keeping what they wrote) or `'delete'` them. |

## Multi-site

A review belongs to the site it was written on. **Reviews across sites** says what that means:

- **Shared** (the default): an entry has the same reviews on every site. Right for translations of one site.
- **Separate**: each site has its own reviews, average and schema, and someone can review the same product on each. Right for sites that are their own shops.

With separate sites, `craft.reviews.reviews(entry)` and the numbers are the element's own site's. `craft.reviews.reviews(entry, { reviewSiteId: null })` gives every site's. The control panel always shows every site's reviews.
