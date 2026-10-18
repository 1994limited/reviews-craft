# GraphQL

Approved reviews are in Craft's GraphQL API, for schemas allowed them: under **GraphQL → Schemas**, tick **View approved reviews** under Reviews.

```graphql
{
  reviews(elementId: 42, rating: [4, 5], limit: 10) {
    id
    title
    body
    rating
    name
    verified
    reply
    helpful
    photos
    dateCreated @formatDateTime(format: "Y-m-d")
  }
  reviewCount(elementId: 42)
}
```

`review` returns one. Arguments: Craft's element arguments (`limit`, `offset`, `orderBy`, `search`…), and `elementId`, `rating`, `verified` and `withPhotos`.

Only approved reviews are ever returned, whatever's asked for. `name` is the name as the site shows it ("Jo B."); email addresses aren't in the API. `ratings` is the criteria's ratings as JSON. With [separate sites](configuration.md#multi-site), the site of the request's.
