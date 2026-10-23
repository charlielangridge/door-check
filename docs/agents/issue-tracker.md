# Issue tracker: Local Markdown

Issues and PRDs live as markdown files in `.scratch/`.

## Conventions

- One feature per directory: `.scratch/<feature-slug>/`.
- PRD: `.scratch/<feature-slug>/PRD.md`.
- Implementation issues: `.scratch/<feature-slug>/issues/<NN>-<slug>.md`,
  numbered from `01`.
- Record triage state as a `Status:` line near the top of each issue.
  Use the strings in `triage-labels.md`.
- Append comments and conversation history under `## Comments`.

## Publishing work

When a skill says "publish to the issue tracker", create the appropriate
file under `.scratch/<feature-slug>/`, creating directories as needed.

## Fetching work

When a skill says "fetch the relevant ticket", read the referenced file.
If an issue number is ambiguous across features, ask which feature it belongs to.
