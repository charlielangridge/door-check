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

## Reference

- [Decide with Jev](https://laravel-news.com/decide-with-jev-laravel-ai-that-answers-with-a-probability)
