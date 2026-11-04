# Single-use collection entry through a staff-operated display

Require a staff login authorised for the site to open its collection entry display at `/{site:slug}`. Adults scan the displayed QR code to open the collection request form without signing in; making the display public would allow remote visitors to obtain fresh entry links themselves.

Each QR code carries a single-use collection entry token, separate from the submitted collection request's identifier. Opening its link claims entry for one browser and causes the display to show a replacement QR code immediately, allowing the next adult to begin while the first completes the form. If two browsers open the same token concurrently, only one gains entry; the other must scan the replacement. The admitted browser retains form access across refreshes. Token claiming records that a link was opened, not that a camera recognised a QR code, and does not prove physical presence at the venue.

An admitted browser has 15 minutes from opening the form to submit its collection request. Refreshing does not restart this window; after expiry the adult must scan again. Successful submission continues to the existing collection status screen, whose access is separate from this form submission window.

An unclaimed displayed QR code expires after 60 seconds and is automatically replaced, or is replaced immediately when claimed, whichever happens first. Expiry of the displayed token does not shorten an admitted browser's 15-minute submission window. This limits the usefulness of an unused photographed code without treating QR entry as proof of physical presence.

Each claimed entry permits exactly one submitted collection request. Double submissions and retries after a connection problem return the same request's collection status screen rather than creating another request. Additional children continue through the existing add-child flow for that request.

Each collection entry display at a site has its own independent QR code. Claiming or expiring a token replaces the code only on its originating display, so activity at one entrance does not interrupt adults scanning at another entrance.

The existing tokenless `collection-request/{site:slug}` address shows "Please scan the QR code on the screen at your site" instead of the collection request form. Submission requires a valid claimed entry, so old bookmarks and direct submissions cannot bypass QR entry.

When the display detects a lost connection, it hides the QR code and shows "Connection lost. Please speak to a member of staff". After reconnecting, it automatically obtains and shows a fresh QR code rather than reusing the previously displayed one.

Warn the adult two minutes before the 15-minute form submission window expires. After expiry, a fresh scan in the same browser for the same site preserves the entered names, but requires a fresh photo and location attempt under the existing collection evidence rules. Preserving names does not extend or bypass the expired entry's submission window.

The collection entry display fills the available display area and adapts to portrait and landscape. Show the site's logo and name above a large QR code, with "Scan to collect your child" and "Open your phone’s camera and scan the QR code". When no logo is available, retain the site name.

The agreed flow is implemented with database-backed entry claims and browser-bound submissions. Live updates replace a claimed QR code immediately, with a one-second heartbeat as a fallback; loss of confirmed connectivity hides the code. Deployment requires the collection entry tables migration.
