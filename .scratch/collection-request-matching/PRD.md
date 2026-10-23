# Collection request matching

Status: Implemented locally. Database migrations and live JEV verification remain operational steps.

## Agreed scope

- `IngestCollectionRequestJob` dispatches `MatchCollectionRequestAdultJob` and `MatchCollectionRequestChildJob` independently to identify the requester and requested child.
- Automatically queue ingestion when a CollectionRequest is created, after its database transaction commits, regardless of the creation entry point.
- Initially process new requests only. Do not automatically backfill existing unmatched requests; any backfill must be a separate explicit operation.
- Discover adult and child candidates independently across their respective registers. Retain individual suggestions even when there is no recorded relationship between them; the relationship requirement applies to the overall matched status.
- Candidate discovery must support spelling mistakes, common nicknames, and partial names (for example, "Liz Smith" or "Liz" for "Elizabeth Smith"). These remain subject to the same confidence and ambiguity requirements for automatic acceptance.
- Use PostgreSQL fuzzy matching to find candidates and Laravel AI with TypeSafe's JEV model to evaluate matches.
- Score the adult and child separately and retain both confidence percentages.
- Store a shared automatic matching threshold in Spatie Settings. Its initial value is 95%.
- Both individual scores must meet or exceed the threshold for a pair to qualify for automatic acceptance.
- Overall `Matched` requires an existing `adult_child` relationship; an individual identification does not.
- If multiple related pairs qualify, require staff approval regardless of which scores highest.
- Matches that cannot be accepted automatically need staff approval. The staff approval UI is outside this phase.
- This phase covers ingestion, matching, and persisted results only. No UI work, including staff approval screens, status presentation, or a settings editor.
- `adult_id` and `requested_child_id` represent accepted individual matches and can be populated independently. An adult can be matched while the child remains unresolved, or vice versa. Store proposed candidates and their scores separately.
- Each person with no candidates receives `NoMatchFound`. The overall request has that status only when neither person has candidates. Partial results require approval. Do not create an Adult, Child, or adult-child relationship.
- On a JEV timeout or invalid response, retry that person's job. If retries are exhausted, persist `MatchingFailed` for that person while preserving the other person's accepted link and result. A provider failure is not a `NoMatchFound` result.
- Completed individual matches are never re-evaluated. Queue redelivery or retries must not overwrite accepted matches, including future staff decisions.
- Repeat delivery must also skip completed searches whose outcome is "Needs approval" or "No match found", unless explicitly requested to run again. Provider failures remain eligible for their configured retries.
- Persist matching status on CollectionRequest and cast it to a backed enum.
- Use `CollectionRequestStatus` with cases `Pending`, `Processing`, `Matched`, `NeedsApproval`, `NoMatchFound`, and `MatchingFailed`. `MatchingFailed` means provider retries have been exhausted.
- Track adult and child matching statuses independently, alongside their separate confidence percentages and accepted links.
- An individual can be matched while the other person remains unresolved. The overall request becomes `Matched` only when both people have accepted matches and an existing adult-child relationship connects them. If both are identified but unrelated, retain their individual matches and set the overall status to `NeedsApproval`.
- "Already matched" for skipping the whole job means the overall request is `Matched`; the presence of two person links alone is insufficient.

## Implementation decisions

- Persist each person's candidates as JSON containing the recorded ID, name snapshot, PostgreSQL similarity, and JEV confidence percentage. Retain the highest confidence separately on the request.
- Save a threshold snapshot on the request so both jobs use the same value. The editable source is `CollectionMatchingSettings.automatic_matching_threshold`, initially 95%.
- Require exactly one qualifying candidate for individual automatic acceptance. Multiple qualifying candidates require approval; the higher score does not break a tie.
- Use `pg_trgm` indexed similarity and word similarity searches, with configured common nickname groups to expand search terms before JEV scoring. The retrieval floor is 0.2, distinct from the automatic acceptance threshold.
- Retain up to 25 candidates per person. Detect overflow and prohibit automatic acceptance of a truncated shortlist.
- While either job is pending or processing, overall status is `Processing`. When both finish: any exhausted failure produces `MatchingFailed`; a related accepted pair produces `Matched`; two empty searches produce `NoMatchFound`; otherwise use `NeedsApproval`.
- The matching jobs allow three exceptions with 10- and 60-second backoff within a ten-minute retry window. Overlap releases do not consume the exception budget. Provider timeout is 20 seconds; job timeout is 55 seconds.
- Separate per-person overlap locks prevent duplicate calls. Short database row locks protect result writes and overall status calculation; no provider call holds a database row lock.
- Reprocessing a completed unresolved result requires an explicit reset of that person's matching status and the overall status to `Pending`, then dispatching ingestion. Accepted people remain untouched. No backfill or rerun UI is included.

## Validation and operational steps

- Affected tests run against a newly created disposable PostgreSQL database, including simultaneous matching in separate processes. Provider responses are faked; no live JEV call is made.
- Verification: 37 affected PostgreSQL tests passed with 135 assertions. Targeted PHPStan and Rector checks passed; Pint was applied to the changed PHP files. Composer validation and `git diff --check` passed. The disposable database was removed.
- Set `TYPESAFE_API_KEY` securely and run the migrations before processing new requests. A queue worker is required.
- PostgreSQL needs the `pg_trgm` extension; the migration enables it and adds name indexes. Rollback removes those indexes and leaves the potentially shared extension installed.
- Validate the provisional 95% threshold against representative real examples; automated fake-response tests do not establish JEV's real-world accuracy.

## Site-specific requests: implemented locally

These confirmed decisions extend the original matching scope and are implemented locally. The application database still requires the user's planned fresh migration and seed.

- Each new collection request belongs to exactly one site.
- Adult and child candidate discovery is restricted to people attached to the request's site. This replaces the original whole-register search scope; a perfect name match at another site is not eligible for automatic matching.
- The eventual public entry point will be a site-specific collection link or QR code that fixes the request's site automatically.
- Public collection UI is deferred. For this phase, the Filament create form is for manual testing and must require a site selection.
- The request's site is fixed at creation. Testing a different site requires a new request; existing matching results must not be carried to another site.
- The user will run a fresh migration and seed after implementation. Original create migrations may be edited directly; no legacy-request backfill or compatibility path is required. This does not authorize the agent to run a destructive database reset.
- Adult–child relationships remain shared across sites. The existing relationship counts at any site where both people are members; no site-specific adult–child relationship is introduced.
- Recheck site membership before accepting matching results. If a candidate was detached while matching was running, do not accept that identification automatically; require staff review and display the membership change as the reason.
- Requests whose matching has already completed retain their recorded results when memberships subsequently change.
- Filament staff continue to see requests across all sites. Add a Site column and site filter to the collection request table; this phase does not introduce staff-to-site assignments or site-based staff access restrictions.
- The automatic matching threshold remains a shared global setting, with the value used retained on each request. No site-specific threshold overrides are introduced.
- Block deletion of a site that has any collection requests, preserving the requests' site and history. Sites without collection requests may still be deleted.

### Site-specific implementation and validation

- Moved the Sites create migration before Collection Requests, added the required site foreign key with restricted deletion, and added unique, cascading site membership constraints. The original create migrations are used for the agreed fresh installation.
- Site selection is required on the testing create form and disabled on edit; normal model updates also reject changing the site. Requests display their site and can be filtered by it across the shared staff list.
- Candidate discovery applies site membership before shortlist limits and JEV scoring. Acceptance and overall finalisation recheck memberships under database locks in a consistent adult-then-child order.
- Membership loss before completion clears the affected identification and records an explicit review reason. That warning remains visible even if the other matching job fails. Completed requests are not reprocessed when membership changes.
- Validation: the full suite passed against a newly created, isolated PostgreSQL test database: 142 tests, 659 assertions, no skips. JEV responses were faked, including the concurrent matching test. Targeted PHPStan and Pint passed. Spec and standards reviews found no unresolved material issues after fixes.
- No application database reset, live JEV call, public collection UI, or deployment was performed.

## Reference

- [Decide with Jev](https://laravel-news.com/decide-with-jev-laravel-ai-that-answers-with-a-probability)
