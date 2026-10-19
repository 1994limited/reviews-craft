# Changelog

## 1.0.1 - 2026-10-19

### Fixed
- The "No more emails like this" link in review request emails gave an error, so nobody could opt out ([#1](https://github.com/1994limited/reviews-craft/issues/1)). Its link now carries the request's token, not the customer's email address; links in emails already sent still work.
- With the rating in SEOmatic's JSON-LD, it went stale once SEOmatic had cached the page's meta: SEOmatic's cache is now cleared when an element's reviews change ([#2](https://github.com/1994limited/reviews-craft/issues/2)).
- "Helpful" votes failed on pages cached by Blitz: with Blitz installed, the buttons' CSRF token is fetched for each visitor ([#3](https://github.com/1994limited/reviews-craft/issues/3)).
- Every vote refreshed the element's cached pages: now once for all the votes in five minutes. Someone who drops cookies no longer counts as a new voter each time ([#4](https://github.com/1994limited/reviews-craft/issues/4)).
- A cancelled or refunded order still made its customer a verified buyer. Orders refunded in full no longer count, nor do orders in the statuses ticked under the new **Orders that don't count** setting (`excludedOrderStatuses`); neither gets a review request ([#5](https://github.com/1994limited/reviews-craft/issues/5)).
- A review request waiting for an order status was queued again by every cron run of `reviews/requests/send` ([#6](https://github.com/1994limited/reviews-craft/issues/6)).
- Deleting a reviewed entry or product for good, or Craft's garbage collection doing it, left its reviews behind as elements, and their photos ([#7](https://github.com/1994limited/reviews-craft/issues/7)). Run `php craft up`: a migration lets the reviews outlive the element just long enough to be deleted properly.
- A photo that couldn't be read was dropped without a word: the reviewer is now told. Dates in the ready-made list follow the site's language. A deleted user's reviews show "Anonymous" in the visitor's language, not the one active when they were deleted. Review request and reviewer emails are sent in the language of their site. The form's time check is a script file, not inline, so it runs under a strict Content-Security-Policy ([#8](https://github.com/1994limited/reviews-craft/issues/8)).

## 1.0.0 - 2026-10-18

### Added
- Reviews of entries and Craft Commerce products, as elements: ratings, criteria, titles, text, replies, notes, photos and helpful votes.
- The form, for guests or signed-in users, with spam checks, rate limits and optional captchas (Turnstile, hCaptcha, reCAPTCHA v2 and v3, or your own).
- Moderation: the reviews index, a review's page, bulk actions, permissions, a dashboard widget, a Reviews field, and emails to editors and reviewers.
- Ready-made templates and default styles, with `craft.reviews` for writing your own.
- Review schema, in templates, on every reviewed page, or in SEOmatic's own JSON-LD.
- Craft Commerce: verified buyers, buyers-only reviews and review request emails, with a signed opt-out.
- Import from Yotpo, Judge.me, REVIEWS.io, Shopify Product Reviews, Trustpilot, WooCommerce and Reviews' own export; sync from Feefo, REVIEWS.io, Judge.me, Yotpo and Trustpilot.
- Reviews shared across sites or separate for each, GraphQL, Blitz and `{% cache %}` clearing, and console commands for import, export, sync, request emails and privacy requests.
