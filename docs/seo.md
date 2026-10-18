# Search engines

Reviews can mark up an entry's or product's rating and latest reviews as [review schema](https://developers.google.com/search/docs/appearance/structured-data/review-snippet), for star ratings in search results. Choose how under **Settings → Search engines → Review schema**:

| | |
| --- | --- |
| **In my templates** (the default) | Add it where you like with `{{ craft.reviews.schema(entry) }}`. |
| **On every reviewed page** | Reviews adds it to every page showing an entry or product that takes reviews. |
| **In SEOmatic's own JSON-LD** | Added to SEOmatic's main entity, so there's one Product, not two. With SEOmatic installed. |
| **Off** | |

Commerce products are always a `Product`. For entries, choose the type under **Schema type for entries**: `Product`, `Book`, `Course`, `LocalBusiness`, `Recipe`… one that Google shows review snippets for.

## What goes in

The average and count of approved reviews, and the ten latest, with the reviewer's name as the site shows it. With no approved reviews, nothing: an empty rating isn't allowed.

Only reviews **written on this site**, and imports you've counted as your own, go in. Google's guidelines say not to mark up reviews gathered from other websites, so synced reviews (from Trustpilot, say) never do, unless the platform allows it ([Import and sync](import-and-sync.md#third-party-reviews-and-schema)).

## With SEOmatic

Choose **In SEOmatic's own JSON-LD**. Reviews adds `aggregateRating` and `review` to the page's main entity. Its schema type, set in SEOmatic, has to be one that takes reviews (Product or a kind of product, Book, Course, Recipe, LocalBusiness and so on). If it's a `WebPage`, Reviews leaves it alone and writes why in the logs: change the type in SEOmatic for that section.

## Writing your own JSON-LD

`craft.reviews.schemaData(entry)` is the rating and reviews as an array. `craft.reviews.schemaProperties(entry)` is the same as JSON properties, each followed by a comma, to put straight after the `{` of JSON-LD you write yourself; it's empty when there are no reviews, so the JSON stays valid either way:

```twig
<script type="application/ld+json">
{ {{ craft.reviews.schemaProperties(product) }} "@context": "https://schema.org", "@type": "Product", "name": {{ product.title|json_encode|raw }} }
</script>
```
