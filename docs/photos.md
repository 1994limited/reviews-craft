# Photos

Off by default. Turn on **Settings → Photos → Reviewers can add photos**, and choose the **volume** (and folder) approved photos go in.

How they're handled:

- Each photo is **saved again as a JPEG**, no bigger than 2,000 pixels across, the right way up. That leaves its metadata behind, including where it was taken.
- Anything that isn't a JPEG, PNG or WebP image, is bigger than the size limit (8 MB), or would be enormous once opened (over 40 megapixels), is refused.
- Until the review is approved, photos are kept in Craft's storage folder (`storage/reviews/pending`), off the site. Editors see them on the review's page.
- When it's approved, they become assets in the volume, with alt text ("Photo from Jo B.'s review"). If it's rejected later, they go back off the site.
- When the review is deleted, its photos go too.

Reviewers can add up to four photos (**Most photos per review**: up to ten), and remove them when changing their review.

Photos need PHP's GD extension. Without it, the form doesn't offer them.

Imported reviews' photos stay where the platform has them: they're shown from its addresses.
