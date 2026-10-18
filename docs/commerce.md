# Craft Commerce

With Commerce 5, products can take reviews, and Reviews knows who bought what.

Tick the **product types** whose products take reviews under **Settings → What can be reviewed**, and add `{{ craft.reviews.display(product) }}` to the product template.

## Verified buyers

A review is marked **verified** when its writer bought the product, in a completed order:

- on their account, when they're signed in, or
- in the order they opened a review request email for, as a guest.

A guest's typed email address never makes them verified: anyone can type someone else's. `review.verified` is true, the ready-made section shows "Verified buyer", and `craft.reviews.reviews(product).verified()` finds only theirs. The review's page in the control panel links to the order.

## Only buyers

Turn on **Only people who bought it can review a product** to refuse everyone else, with "Only customers who bought this can review it." Entries aren't affected.

## Review request emails

Turn on **Ask customers to review their orders**, and some days after each completed order (**Days after the order**: 14), the customer is emailed a link to review each product they bought. Products they've reviewed already, and ones that don't take reviews, are left out; with nothing left, no email goes.

- **Only once an order is**: tick order statuses (shipped, say) to wait until the order reaches one. Reviews looks again each day for 60 days.
- The links remember the order for that visit, so the review is verified, and only count for 90 days after the email.
- Every email has a link to opt out. Opting out takes a click on a page, so a mail scanner following the link doesn't opt anyone out.
- Change the email under **Utilities → System Messages → When a customer is asked to review their order**. It has `name`, `products` (each with `title`, `url` and `product`) and `optOutUrl`.

The emails are sent from Craft's queue. If your queue is run by cron, `php craft reviews/requests/send` sends any that are due as well.

Every customer is asked, not only the happy ones, and nothing is offered in return: see [Fair reviews](privacy.md#fair-reviews).
