# Ready-made reviews

Don't want to write the markup and styles yourself? Reviews comes with a finished section: the average and star breakdown, the reviews (with sorting, pages and "helpful" buttons), and the form.

```twig
{# In your layout's <head> #}
{{ craft.reviews.styles() }}

{# Wherever the reviews go #}
{{ craft.reviews.display(entry) }}
```

It works without JavaScript, is usable by keyboard and screen readers, and fits phones. The styles take your site's font, size and text colour, and work out borders, muted text and empty stars from that colour, so they suit dark sites too.

## The functions

| Function | |
| --- | --- |
| `craft.reviews.display(element, options)` | The whole section: heading, average and breakdown beside the reviews, then the form. |
| `craft.reviews.summaryHtml(element, options)` | The average, how many of each star, and each criterion's average. |
| `craft.reviews.list(element, options)` | The reviews, with a choice of order, pages and "helpful" buttons. |
| `craft.reviews.form(element, options)` | The form. |
| `craft.reviews.styles()` | A `<link>` to the default stylesheet. `styles(true)` puts it inline, in a `<style>`. |

`element` is the entry or Commerce product. Use the parts separately to lay them out yourself: the summary in a sidebar, say.

Options:

| Option | |
| --- | --- |
| `heading` | The section's heading ("Reviews"). `display` only. |
| `sort` | The order before a visitor chooses one: `helpful` (the default), `newest`, `oldest`, `highest` or `lowest`. |
| `perPage` | Reviews per page (from the settings: 10). |
| `form` | `false` to leave the form out. `display` only. |
| `redirect` | Where the form goes after sending (back to the element's page). |

```twig
{{ craft.reviews.display(product, { heading: 'What readers say', sort: 'newest', perPage: 5 }) }}
```

Visitors choose the order with the menu above the reviews, and see one rating's reviews from the links in the breakdown; both go in the address (`?sort=newest`, `?rating=5`).

## Changing the look

The stylesheet is [`resources/css/reviews.css`](https://github.com/1994limited/reviews-core/blob/main/resources/css/reviews.css) in the `1994/reviews-core` package, which the plugin installs. There are four ways to change it, from least to most work.

### 1. Set the variables

Everything is drawn from `--reviews-*` variables. Set any of them in your own CSS, on `.reviews-ui` for the reviews or `:root` for everywhere:

```css
.reviews-ui {
    --reviews-star: #e11d48;
    --reviews-radius: 0;
    --reviews-button-bg: #111827;
    --reviews-button-text: #fff;
    --reviews-heading-font: "Playfair Display", serif;
}
```

| Variable | Default | |
| --- | --- | --- |
| `--reviews-font` | `inherit` | The font. Your site's, unless you set one. |
| `--reviews-heading-font` | `inherit` | Headings and the big average. |
| `--reviews-font-size` | `inherit` | |
| `--reviews-small` | `0.875em` | Dates, counts, helpful buttons. |
| `--reviews-heading-size` | `1.5em` | The section and form headings. |
| `--reviews-line-height` | `inherit` | |
| `--reviews-text` | `currentColor` | The text colour. Your site's. |
| `--reviews-muted` | the text colour at 65% | Dates, counts and notes. |
| `--reviews-border` | the text colour at 18% | Lines between reviews, around the form and fields. |
| `--reviews-surface` | the text colour at 4% | Behind the form and replies. |
| `--reviews-field` | `transparent` | Behind form fields. |
| `--reviews-button-bg` | `transparent` | Buttons are outlined in the text colour until you give them a fill. |
| `--reviews-button-text` | `inherit` | |
| `--reviews-button-border` | `currentColor` | |
| `--reviews-star` | `#f5a524` | Filled stars and the breakdown's bars. |
| `--reviews-star-empty` | the text colour at 20% | Empty stars and bars. |
| `--reviews-verified` | green, mixed with the text colour | "Verified buyer". |
| `--reviews-error` | red, mixed with the text colour | Form errors. |
| `--reviews-focus` | `Highlight` | The keyboard focus ring. |
| `--reviews-radius` | `0.5rem` | Corners. |
| `--reviews-gap` | `1.5rem` | Space between reviews, and inside the form. |
| `--reviews-star-size` | `1.1em` | Stars in the summary and reviews. |
| `--reviews-star-input-size` | `2rem` | The stars to click in the form. |
| `--reviews-max-width` | `none` | The section's widest. |

### 2. Write your own CSS on top

The default styles are in a cascade layer called `reviews`, with selectors that count for nothing, so **any CSS of yours wins**, without `!important`. Every element has a class starting `reviews-ui__`:

```css
.reviews-ui__review { padding-block: 2rem; }
.reviews-ui__verified { font-weight: 700; }
```

The classes are `reviews-ui__summary`, `__average`, `__average-number`, `__stars`, `__star` (`--full`, `--half`), `__bars`, `__bar-row`, `__bar`, `__bar-fill`, `__criteria`, `__toolbar`, `__select`, `__list`, `__review`, `__review-head`, `__review-title`, `__review-criteria`, `__photos`, `__by`, `__verified`, `__source`, `__reply`, `__reply-label`, `__actions`, `__pages`, `__empty`, `__message` (`--error`), `__form`, `__heading`, `__field`, `__label`, `__row`, `__input`, `__rating`, `__rating-stars`, `__button` (`--quiet`), `__link-button`, `__muted` and `__columns`.

**With Tailwind**, which keeps its styles in layers too, put this line before `@import "tailwindcss";`, so Tailwind's reset doesn't undo the reviews' styles and your utilities still beat them:

```css
@layer theme, base, reviews, components, utilities;
```

### 3. Copy the stylesheet

Copy `vendor/1994/reviews-core/resources/css/reviews.css` into your own CSS build, drop `craft.reviews.styles()`, and change it however you like. It's yours from then on: updates don't change it, so check the [changelog](../CHANGELOG.md) for new classes now and then.

### 4. Change the markup

The templates are in the plugin's `src/templates/_site` folder: `display`, `summary`, `list`, `form` and `_stars`. To change one, copy it to `templates/_reviews/` in your site, with the same name: yours is used instead. Each uses only what's in [Templates](templates.md).

## Dark mode

There's nothing to turn on: the styles follow the text colour of the page, not the visitor's dark-mode setting. On a site that's only light, the reviews are light; on a site with a dark mode, they follow it. Two things to check on a site with a dark mode:

- Browsers draw drop-down menus and the file picker themselves. If your dark theme doesn't set `color-scheme: dark`, add it.
- To use other colours in dark mode, set the variables there:

    ```css
    .dark .reviews-ui { --reviews-star: #fbbf24; }
    ```

## Wording

The templates' words go through Craft's translations, in the `reviews` category. To change the wording or translate it, add `translations/{language}/reviews.php` to your site:

```php
return [
    'Write a review' => 'Tell us what you think',
];
```
