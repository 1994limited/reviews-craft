# Caching

Reviews keeps its own numbers cached (each element's average and breakdown) until one of its reviews changes. When a review is written, moderated, replied to or voted on, it also clears what Craft has cached for the element:

- **`{% cache %}` blocks** that use the element are cleared, as when the element is saved.
- **Blitz**: the element's cached pages are refreshed, as when it's saved.
- **SEOmatic**: with the rating in SEOmatic's JSON-LD, its cached meta for the element's section is cleared, as when the element is saved.

A "helpful" vote changes only the count beside its button, so votes refresh the page once for every vote in the next five minutes, not for each.

## Pages cached whole

On a page cached whole (by Blitz, or a CDN), the review form needs to be fresh for each visitor: its CSRF token, the signed-in reviewer's own review, and any errors. Leave the rest of the page cached, and include the form dynamically. With Blitz:

```twig
{{ craft.reviews.summaryHtml(entry) }}
{{ craft.reviews.list(entry) }}
{{ craft.blitz.includeDynamic('_reviews/form', { elementId: entry.id }) }}
```

The list's "Helpful" buttons can stay cached: with Blitz installed, each visitor's CSRF token is fetched when the page loads (Craft's `asyncCsrfInputs` setting does the same for any other cache). That takes JavaScript; without it, the buttons work on pages that aren't cached.

The time check against spam still works: the form carries how long the page was open in the browser, not just when it was drawn.

The "thank you" message after sending is in the session, so it shows on an uncached page, or in the form included dynamically. Or send the form with JavaScript ([Templates](templates.md#with-javascript)) and show the message yourself.
