# Reviews for Craft

**Reviews and star ratings, collected on your own site.** A review form for entries and Commerce products, moderation in the control panel, and averages, reviews and review schema in your templates.

Most review plugins copy reviews in from Google, Yotpo or Trustpilot. Reviews collects them on your site, from your visitors and customers, as Craft elements. With Craft Commerce, reviews by customers who bought the product are marked as verified. Moving from another platform, or still using one? Reviews imports and syncs those reviews too.

## Collect them

- **Review anything.** Entries in the sections you choose, and Commerce products of the types you choose. An overall rating out of five, optional ratings for criteria you set (Story, Value), a title and the review.
- **Guests or signed-in users.** Guests give a name and email, never shown. Signed-in reviewers can change or delete their review.
- **Photos, if you want them.** Saved again without their location data, and kept off the site until the review is approved.
- **Spam protection without puzzles.** A hidden field, a time check and a link limit on every form, and rate limits. Add a captcha if you like: Cloudflare Turnstile, hCaptcha, reCAPTCHA v2 or v3, or your own.

## Check them

- **Reviews are elements.** An index with a waiting queue, approve, reject and spam (one at a time or in bulk), search, sorting, and a public reply under any review. Notes for editors only.
- **Permissions** for moderating, deleting and importing, a dashboard widget for what's waiting, and a Reviews field that shows an entry's rating and can switch reviews off for one entry.
- **Emails** to editors about new reviews, and to reviewers when theirs goes up or gets a reply. Change them in System Messages.
- **Fair by design.** No setting publishes or hides reviews by their stars, and editors can't change what reviewers wrote, in line with UK, EU and US rules on fake and suppressed reviews.

## Show them

- **Ready-made, in one line.** `{{ craft.reviews.display(entry) }}` gives you the average and star breakdown, the reviews with sorting, pages and "helpful" votes, and the form. Its styles take your site's fonts and colours, follow its dark mode, and every colour, size and gap is a CSS variable.
- **Or your own markup.** `craft.reviews` gives you review queries, the numbers, stars and the form's fields, for templates of your own. Works without JavaScript.
- **Review schema** for star ratings in search results: in your templates, on every reviewed page, or added to **SEOmatic**'s own JSON-LD, so search engines see one product, not two.
- **GraphQL**, and caches kept fresh: `{% cache %}` blocks and **Blitz** pages are refreshed when an element's reviews change.

## Craft Commerce

- **Verified buyers.** A review is marked verified when its writer bought the product, on their account or from the order's review request email.
- **Buyers only,** if you like: everyone else is told only customers can review it.
- **Review request emails** a set number of days after an order, or once it reaches a status such as shipped. Every customer is asked, nothing is offered in return, and every email has a link to opt out.

## Import and sync

- **Import** another platform's export: Yotpo, Judge.me, REVIEWS.io, Shopify Product Reviews, Trustpilot or WooCommerce, or any CSV or JSON with a rating column. See what would be added, and what matches nothing, before anything is.
- **Sync** from Feefo, REVIEWS.io, Judge.me, Yotpo and Trustpilot on a schedule. Keys are kept encrypted, out of Project Config.
- **Kept honest.** Each imported review shows where it came from. Only your own reviews (and imports you count as your own) go in your average and schema, as Google's guidelines ask.

## Multi-site and privacy

- **Reviews across sites:** shared across a site's translations, or separate for each site.
- **Private by default.** No IP addresses kept. Export or forget someone's reviews with one command, and a deleted user's reviews lose their name and email.

## Requirements

- Craft CMS 5.6 or later, PHP 8.2 or later.
- Optional: Craft Commerce 5, for verified buyers and review requests; SEOmatic, to add review schema to its own; PHP's GD extension, for photos.

## Documentation

Everything is covered in [the documentation](https://github.com/1994limited/reviews-craft/blob/main/docs/README.md), and there's a demo shop, Dog-ear Books, to try it on: [reviews-craft-demo](https://github.com/1994limited/reviews-craft-demo).
