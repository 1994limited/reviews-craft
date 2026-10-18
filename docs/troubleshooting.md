# Troubleshooting

**The form says "This can't be reviewed."** The entry's section (or the product's type) isn't ticked under **Settings → What can be reviewed**, the entry isn't live, it has no page on the site, or its Reviews field is switched off.

**Reviews don't appear after sending.** They wait for an editor unless **Publish without checking** says otherwise: look under **Reviews → Waiting for moderation**. Only approved reviews are shown on the site.

**The form keeps saying thank you, but nothing arrives.** It thanks bots, and repeat reviews from the same email address, as it would a person, without keeping anything. If it's you: wait a few seconds before sending (the time check), and check a field you've hidden with CSS isn't the hidden `website` field. A guest can review each thing once per email address.

**"That's a lot at once."** The rate limit: five reviews in ten minutes from one address (`throttleSubmit`). Behind a proxy, make sure Craft sees visitors' own IP addresses (`ipHeaders` in Craft's config).

**The average hasn't changed on a cached page.** Reviews clears `{% cache %}` and Blitz for the element. For another cache (a CDN), clear the page when a review is approved: listen for `Reviews::EVENT_AFTER_APPROVE` ([Events](php-api.md)).

**No stars in search results.** Check the page's JSON-LD with Google's Rich Results Test. Only approved reviews written on this site count. With SEOmatic, its schema type must take reviews ([Search engines](seo.md#with-seomatic)). And Google decides whether to show them.

**Photos aren't offered.** Turn them on under **Settings → Photos**, choose a volume, and check PHP has the GD extension.

**A sync fails.** The Sync page says why. Check the keys, and that the platform's API is part of your plan (Trustpilot's is a paid module). "More than 500 pages of reviews": import the rest from the platform's export.

**Review request emails don't go.** They're sent from Craft's queue, the set number of days after an order is completed: check the queue is running (Utilities → Queue Manager), or run `php craft reviews/requests/send` from cron. With **Only once an order is** set, the order has to reach one of those statuses.
