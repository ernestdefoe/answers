# Answers: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects `feat:` / `fix:` (the release drafter bumps from the subject).
- `js/dist` is committed and is what forums load. After any change under `js/src`: `npm ci` then `npm run build` in `js/`, and commit the bundle; CI fails if it is stale.
- Releasing is Ernest's click. Nothing is announced until he approves the wording.

## Design rules that exist for a reason

- **State, counts, score and bounty are columns on `discussions`**, written only by `Question` (set-based UPDATEs; two statements because PostgreSQL's SET reads the old row). Lists must cost nothing extra.
- **Reputation**: `answers_reputation_log` is the truth (and the profile history); `answers_reputation` holds its sums. Taking points back deletes the rows that gave them (`Reputation::takeBack`). `answers:recount` rebuilds the sums.
- **Comments are real posts** with `answers_parent_id` (validated in `Lifecycle::saving`), so mentions, flags, approval and search keep working; they never count as answers.
- **Access decides every right**: tag-scoped `discussion.answers*` permissions, plus reputation in one of the question's Q&A tags for editing others' answers, reviewing and voting to close. History is for the author, trusted editors and moderators.
- Edits made outside the API (rollback, approved suggestion, review hide) must release the model's events (`Revisions::edit`, `Reviews::settle`): `save()` alone doesn't, and Revised/Hidden drive history and counts.
- Post fields batch per request (`Api\Batch`, WeakMap keyed by the request); discussion lists skip them. Discussion `can*` fields only on the discussion's own page.
- Gamification is mirrored through its own `PostResourceFields::vote()`; Best Answer is only read (import).
- extend.php uses string literals outside closures, never class constants. A JS component attribute named `tag` never arrives (use `qaTag`).

## Testing

- `t.sh mysql|pgsql` style: set `DB_DRIVER` (mysql/pgsql), `DB_PREFIX=dev_`, run `composer test:setup` **whenever the driver changes** (the harness reads the driver from setup, not env), then `composer test`. Money tests run on MySQL only.
- `composer analyse:phpstan`.
- Screenshots, e2e and seed: `~/github/scopes/answers/` (`shots.mjs`, `e2e.mjs`, `seed.php`).
