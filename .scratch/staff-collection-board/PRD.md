# Staff collection board

Status: Implemented locally; runtime configuration and kiosk validation pending

The staff collection board and the collecting adult's status screen share one request containing one adult and one or more independently progressing child collections. The priority is a simple touch interface with explicit handover stages and a complete activity trail.

## Confirmed requirements

### Staff area, access, and names

- Provide an always-on staff area separate from Filament admin, designed for a wall-mounted kiosk tablet with a very easy touch interface.
- Use Laravel Reverb for live board updates.
- Introduce a staff role and permission-based access that can accommodate future staff features with different permissions.
- Initially use a staff account per venue, linked to an individual staff member. Support staff login and logout.
- Restrict each kiosk account to one assigned venue/site, including its board, candidate suggestions, and searches. Assign the venue in Filament; do not provide a venue switcher on the kiosk.
- Add an optional list of multiple Also known as full names to each adult and child, such as Davey Jones or David Smith for David Jones. Maintain these in Filament rather than on the staff screen. Use them in automatic matching and staff searches while retaining the primary name.
- Require one staff kiosk login each morning and keep it signed in throughout the normal 06:00–19:00 working day without repeated password prompts. Do not abruptly log staff out at 19:00 while a collection is being handled. The precise daily reauthentication boundary remains an implementation detail to resolve.

### Live board and history

- Show new collection requests on the board, indicating unresolved adult or child matches.
- Show one board card per adult request, with the adult's name, elapsed waiting time, and each child's status clearly displayed. Keep children grouped within their request. Tapping the card opens the large modal with separate actions for each child.
- Order active requests oldest first, adding new requests at the end. Keep cards in place as matching and collection statuses change. Use prominent Needs checking, Reviewed, and Preparing child badges so staff can identify the required action without reordering the board.
- Play a short chime and briefly highlight the affected card for a new request or an added child. Provide a large mute/unmute control on the tablet. Keep ordinary status updates silent.
- Require server confirmation for staff actions; do not support offline collected or cancelled actions. Show an action as successful only after the server saves it. On connectivity loss, show a prominent connection warning and automatically refresh the board when connectivity returns.
- Allow multiple tablets to open the same request without reserving it. The first saved action takes effect. Reject conflicting stale actions with This request has changed—review the latest details and require a refresh before further action; never overwrite the newer state.
- Once all children in a request are collected or cancelled, remove its card from the active board and show it in Today's history. Provide a large Today's history button for staff to view finished requests and their timelines. Older records remain available in Filament.
- Keep unfinished requests from previous days on the active board with a Previous day — needs attention warning. Staff must explicitly resolve or cancel them; do not infer collection or automatically cancel unfinished handovers overnight.
- Finished requests are read-only on the staff screen, including Today's history. Handle corrections to collected or cancelled outcomes in Filament, requiring an explanation and an audit entry.
- When an admin reopens a collected or cancelled child collection, require a correction explanation and return it to Needs checking for full staff review. Preserve the original outcome in the audit trail; do not carry the previous approval into the corrected collection.

### Staff review and child collection

- Open requests in a large Inertia Modal. When adult, child, and relationship checks are confirmed, show who is collecting whom, the child photo, stored adult photo, and current requester photo.
- If the child photo, stored adult photo, or current requester photo is missing, show a clear Photo unavailable warning. Staff may proceed after confirming identity in person and recording a reason before Confirm checks becomes available.
- Missing-photo verification reasons: Adult/child personally known to staff; Identity checked in person; Other. Notes are optional for preset reasons and required for Other. Record which photos were unavailable and audit the selected reason and supporting notes.
- Allow closing without changes, completing collection, or cancelling with a structured reason and supporting notes.
- Cancellation reasons: Adult identity could not be confirmed; Child identity could not be confirmed; Adult not authorised to collect; Adult left / no longer collecting; Duplicate request; Request submitted in error; Other. Notes are optional for preset reasons and required for Other. Audit the reason and notes.
- For a cancelled child collection, show the adult Collection cancelled — please speak to staff. Keep detailed cancellation reasons, notes, identity-check information, override explanations, and matching scores in the staff/admin area rather than the public status screen.
- For unresolved people, provide large, simple selection controls showing the top five closest candidates with clear photos, plus search for others.
- If automatic matching fails or is unavailable, show Automatic matching unavailable and allow staff to search for and select the adult and children manually. Require the normal identity, photo, and relationship checks before Confirm checks.
- After both people are resolved, proceed to the collection review screen subject to the relationship decision below.
- When the adult and a requested child are identified but no adult-child relationship is recorded, any authorized staff-screen user can explicitly override the missing relationship for that child. Require an explanation and audit the override before allowing completion; no separate staff level or override permission is required at this stage.
- A relationship override applies only to that child within the current collection request and does not create or change a permanent adult-child relationship. Permanent relationships remain managed in Filament.
- Distinguish preparing the child from completing collection: use a Preparing child stage and a Child collected outcome. Child collected means the child has physically been handed over to the adult, rather than merely approving the request.
- Require staff to resolve the adult and child identifications, review photos or record the missing-photo exception, and confirm the recorded relationship or explain a request-only override before tapping Confirm checks for that child. This explicit action records review and displays Request reviewed to the adult. Opening and closing the modal does not change progress.
- Require the per-child sequence Confirm checks, then Prepare child, then Child collected. Preparing child is mandatory before Child collected. Audit all three transitions with the acting account and timestamp.
- Keep manual person selections and override explanations as drafts until staff tap Confirm checks. Closing discards unsaved changes, with a warning if staff have edited anything. Confirm checks saves the selections, explanations, and review action together in the audit trail.
- Before Confirm checks, allow staff to correct an automatic adult or child identification even when it exceeded the matching threshold. Provide a secondary Change match action, require a reason, and preserve the original automatic result in the audit trail.
- Once staff confirm checks for the first child, lock the adult identity for the entire request. Before that point, correcting the adult must recheck every child's relationship. Afterwards, staff cancel affected child collections and use Filament for corrections, keeping the request tied to one verified adult.
- Allow only one active Preparing child entry for the same child at a time, including across requests and tablets. Keep conflicting entries visible with a warning and block their preparation until staff cancel the active preparation.
- If a child is already marked collected at the request's venue that day, block later requests for that child from entering preparation. Show the earlier collection time and let staff cancel the duplicate. If the earlier collected outcome was mistaken, an admin must correct it in Filament.

### Adult status and additional children

- After public submission, show the collecting adult a status screen instead of resetting to the submission form. Use simple progress messages including Request received, Request reviewed, Preparing child, and Child collected for each child. Request reviewed means staff explicitly confirmed that child's checks, rather than merely opening the request or automatic matching finishing.
- Collecting adults submit requests and watch their status on their own devices, rather than a shared entrance tablet.
- Keep public status access private to the submitting browser, surviving refresh or reopening without an adult login. Show only that request's names and simple progress messages. Access from another browser or device requires a new request or staff help.
- Allow the collecting adult to add another child they are related to to the same request. A request can therefore cover multiple children at one site.
- Children progress independently within a request, with separate checks, any required relationship override, preparation, collection, and cancellation states. One child may be collected while another is still being prepared or has been cancelled.
- When adding another child, retain the accepted adult match: the adult is locked for this flow and must not be re-entered or rematched. Use the same submitted child-name matching flow as the initial child, scoped to the request's venue.
- Show Add another child only after the adult is matched and has recorded relationships with multiple children at that venue. Do not show it while the adult is unresolved or has only one eligible linked child. Do not expose a list of linked children through this option.
- Keep Add another child available while at least one child is awaiting review, awaiting preparation, or being prepared, subject to the matched-adult and multiple-related-children rules. Close additions once every child is collected or cancelled. Each addition has its own checks.
- Block the same child appearing twice within one request, including when different submitted names or aliases resolve to the same child. Direct the adult or staff to the existing child entry. If a duplicate is discovered during staff matching, flag it and prevent both entries progressing.

### Audit and retention

- Use Laravel auditing across the collection workflow: public submissions, automatic matching, staff actions, and Filament changes to people, aliases, relationships, photos, and venue assignments. Also record staff login/logout and denied or failed actions. Package/version compatibility remains to be verified during implementation planning.
- Show relevant audit events in each request's timeline and provide a searchable audit view in Filament.
- Audit staff viewing a request and discarding edited drafts even when collection data is unchanged. Record the account, venue, request, timestamp, and action; distinguish discarded edits from confirmed changes so abandoned selections are not treated as accepted matches.
- Support separate configurable retention periods for request photos/evidence and audit history. Until those periods are chosen, preserve current storage behavior and do not introduce automatic deletion.

## Child collection transitions

| Current stage                     | Staff action                                                        | Result                                           |
| --------------------------------- | ------------------------------------------------------------------- | ------------------------------------------------ |
| Needs checking / Request received | Confirm checks, saving required selections, reasons, and exceptions | Reviewed / Request reviewed                      |
| Reviewed                          | Prepare child, subject to duplicate and prior-collection checks     | Preparing child                                  |
| Preparing child                   | Child collected, after physical handover                            | Child collected                                  |
| Any unfinished stage              | Cancel, with a preset reason or Other with notes                    | Cancelled; public message directs adult to staff |
| Collected or cancelled            | Admin correction in Filament, with an explanation                   | Needs checking; original outcome remains audited |

Matching outcomes and collection progress are separate concepts: an automatic match does not mean staff reviewed a request or handed over a child. Cancellation, review, preparation, and collection apply independently to each child. Confirming the first child's checks locks the shared adult identity.

## Outstanding policy values

- Actual photo/evidence and audit retention durations have not been chosen. Configurability is agreed; automatic deletion is not part of the initial behavior.

## Implementation decisions

- Preserve the original singular `collection_request` table and each existing request's matching evidence. The original row represents the first child and the shared adult; added children have their own rows referencing `parent_request_id`. Collection progress is separate from automatic matching status. Legacy rows start at Needs checking; historical handovers are never inferred.
- Lock the root request for staff actions and compare its revision. Keep independent matching jobs, threshold snapshots, ambiguity and truncated-shortlist safeguards. Alternative names participate in discovery and classification.
- Require daily reauthentication at 06:00 Europe/London, with a 24-hour staff session cookie and no 19:00 cutoff. Role and assigned venue are checked on every staff request and private channel authorization.
- Use Reverb 1.12, Inertia Modal 3.1 for Inertia 3, and Laravel Auditing 14. Preserve older person photos as evidence; staff media delivery is private and venue scoped.
- Use private invalidation events and authoritative reloads, with 15-second polling as a fallback. Staff modals and the board share a venue subscription without disconnecting each other; public status subscriptions are authorized by an encrypted HttpOnly browser cookie.
- Manual candidate selections remain drafts. An explicit Flag duplicate for staff action records conflict evidence and blocks both unfinished entries without accepting an identity. Cancelling or correcting the duplicate clears the conflict. Preparation and physical handover recheck current venue eligibility, relationship or override, and photo exceptions.
- Staff timelines include only relevant participants and the request's venue context. The full searchable audit view remains in Filament.

## Local validation and operational setup

- The additive migration has been applied to the local Herd development database. No production deployment or production migration was performed.
- Affected feature, authentication, Filament and matching tests pass against an isolated PostgreSQL database with provider calls faked. PHPStan, Vue type checking, changed frontend lint and the production build pass. Whole-repository formatting still reports existing issues in `.codex/config.toml` and `docs/agents/triage-labels.md`.
- Browser verification reached the staff login redirect. Authenticated tablet UX, sound, camera and a real Reverb socket connection require operator/device validation.
- Before enabling live updates, configure `BROADCAST_CONNECTION=reverb`, matching `REVERB_*` and `VITE_REVERB_*` settings, an origin limited to the application, and the WebSocket endpoint/TLS appropriate to the host. Rebuild frontend assets after changing Vite values. Run the Reverb service (`php artisan reverb:start`) and a queue worker; keep existing matching provider configuration in place.
- In Filament Users, assign the staff role and the account's venue. The account must have a verified email. No real staff accounts or credentials were created during implementation.
- For another environment, install the locked PHP/JS dependencies, run migrations, build assets, and restart persistent application/queue/Reverb processes as appropriate. The complete test suite remains an operator follow-up using `php artisan test --compact` against an isolated test database.

## Review scenarios

- Two children in one request can reach different outcomes without hiding unfinished work.
- Opening and closing the modal leaves collection data unchanged; discarded edits are recorded as activity rather than accepted selections.
- A missing relationship can be overridden by any staff-screen user with an explanation, only for the current child collection.
- An unavailable photo or matching service leaves an explicit, audited manual review route.
- An alias that identifies the same child cannot create a second progressing entry in the request.
- Concurrent preparation for the same child and later preparation after a same-day collection are blocked.
- A stale tablet cannot overwrite another tablet's saved action; a disconnected tablet cannot report an unsaved handover as successful.
- The adult sees only their request's simple status messages, with no internal notes or matching evidence.
- An unfinished previous-day request remains visible, and a reopened finished collection requires a fresh review.
- No request evidence or audit history is automatically deleted without a chosen retention policy.

## Audit attribution

The signed-in account is linked to a staff member. Without an additional identity check, audit records establish the account used, rather than the physical operator of the tablet.
