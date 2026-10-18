# Reviews for Craft

Collect reviews and star ratings on your own Craft site: a review form for entries and Commerce products, moderation in the control panel, and averages, reviews and review schema in your templates.

Other review plugins copy reviews in from Google, Yotpo or Trustpilot. Reviews collects them on your site, from your visitors and customers, as Craft elements. With Craft Commerce, reviews by customers who bought the product are marked **verified**. And if you're moving from another platform, or still use one, Reviews imports its reviews or keeps them in sync.

## Features

- **Review anything.** Entries in the sections you choose, and Commerce products of the types you choose. An overall rating out of five, optional ratings for criteria you set (Story, Value), a title and the review.
- **Guests or signed-in users.** Guests give a name and email (never shown). Signed-in reviewers can change or delete their review.
- **Moderation.** Reviews are elements: an index with a waiting queue, approve, reject, spam, bulk actions, search, notes, and a **public reply** under any review. Permissions for each, a dashboard widget, and a field showing an entry's rating.
- **Fair by design.** No setting publishes or hides reviews by their stars, and editors can't change what reviewers wrote, in line with UK, EU and US rules on fake and suppressed reviews.
- **Averages and breakdowns.** The average, the count, how many of each star, and each criterion's average, cached until a review changes.
- **Sort, filter and paginate.** Newest, highest, lowest or most helpful; by rating, verified buyers or photos. **"Was this helpful?"** votes.
- **Review schema.** JSON-LD with the average and latest reviews, for star ratings in search results: in your templates, on every reviewed page, or inside **SEOmatic**'s own.
- **Spam protection without puzzles.** A hidden field, a time check and a link limit on every form, rate limits, and optionally a captcha: **Cloudflare Turnstile, hCaptcha, reCAPTCHA v2 or v3**, or your own.
- **Photos**, if you want them: saved again without location data, and kept off the site until the review is approved.
- **Craft Commerce.** Verified buyer badges, reviews from buyers only, and emails asking customers to review what they bought, a set time after their order.
- **Import and sync.** Import exports from Yotpo, Judge.me, REVIEWS.io, Shopify Product Reviews, Trustpilot and WooCommerce. Sync from Feefo, REVIEWS.io, Judge.me, Yotpo and Trustpilot on a schedule, each review showing where it came from, and kept out of your schema as Google asks.
- **Emails.** New reviews to editors; approvals and replies to reviewers.
- **Multi-site.** Reviews shared across a site's translations, or separate for each site.
- **GraphQL**, **Blitz** and `{% cache %}` aware, and a console for import, export, sync and privacy requests.
- **Private by default.** No IP addresses kept. Export or forget someone's reviews with one command.
- **Ready-made, or your own markup.** One function gives you a finished reviews section whose styles take your site's fonts and colours, with every colour and size a CSS variable. Or write your own with `craft.reviews`.

The screenshots are from Dog-ear Books, a made-up bookshop. See [the demo](docs/demo.md).

## Requirements

- Craft CMS 5.6 or later, PHP 8.2 or later.
- Optional: Craft Commerce 5, for verified buyers and review requests; SEOmatic, to add review schema to its own; PHP's GD extension, for photos.

## Installation

```bash
composer require 1994/reviews-craft
php craft plugin/install reviews
```

Then choose what takes reviews under **Reviews → Settings**, and add two lines to your templates:

```twig
{{ craft.reviews.styles() }}          {# in the <head> #}
{{ craft.reviews.display(entry) }}   {# where the reviews go #}
```

## Documentation

[docs/README.md](docs/README.md)

## Support

Email [hello@1994.co.uk](mailto:hello@1994.co.uk), or [open an issue](https://github.com/1994limited/reviews-craft/issues).

## Licence

Reviews is commercial software, licensed under the [Craft License](LICENSE.md). Buy it in the Craft Plugin Store.
