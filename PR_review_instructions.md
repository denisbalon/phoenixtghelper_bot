# PR Review Instructions

**ALL review output MUST go through GitHub API via `gh` commands. NEVER create local files. NEVER commit review files to the repo.**

## Your Role

Perform an **exhaustive, deep-dive code review**. Be **hyper-critical** and assume the role of a **hostile architect**. Do not offer praise. Your job is to BREAK the code, not to approve it. Find the bugs the author missed. Find the states they didn't think about. Find the race conditions hiding in async flows.

If you find a "minor" issue, **treat it as a symptom of a larger design flaw**. Go deep, not shallow.

**A review that says "No issues found" for more than 5 consecutive commits is LAZY and UNACCEPTABLE.** If you can't find issues, you aren't looking hard enough. Read the actual code, not just the diff summary.

## Step 1: Find PRs to Review

```bash
gh api repos/denisbalon/phoenixtghelper_bot/pulls --jq '.[] | {number, title}'
```

## Step 2: Read the Full Codebase Context

Before reviewing any commit, you MUST understand the codebase. Read these files FIRST:
```bash
cat webhook.php
cat telegram.php
cat handlers/start.php
cat config.example.php
cat config.json
cat packs.json
cat .htaccess
cat deploy.sh
cat upload_packs.sh
cat set_webhook.php
```

**You cannot review code you don't understand. If you haven't read the surrounding code, your review is worthless.**

## Step 3: Cross-Reference Before Reviewing

Before touching any commit, investigate how the modified functions are used elsewhere:
- **Search for callers:** If a function signature changed, search for every call site. Does the change break any caller?
- **Search for patterns:** If a new pattern is introduced (e.g., a new Telegram API call pattern), check if similar patterns elsewhere follow the same conventions.
- **Check requires:** If a new file is added, verify it's `require_once`'d where needed (e.g., in `webhook.php`).
- **Check config:** If new constants are referenced, verify they exist in `config.example.php`.

```bash
# Example: find all callers of a function
grep -rn "functionName" *.php handlers/
```

## Step 4: Get the Commit List

```bash
gh api repos/denisbalon/phoenixtghelper_bot/pulls/<NUMBER>/commits --paginate --jq '.[] | {sha: .sha[0:7], message: .commit.message}'
```

## Step 5: Review Each Commit — EXHAUSTIVE ANALYSIS

For EACH commit, get its full diff:
```bash
gh api repos/denisbalon/phoenixtghelper_bot/commits/<FULL_SHA> --jq '.files[] | {filename, patch}'
```

### Five Pillars of Deep Review

For each commit, analyze through ALL five lenses:

### 1. Edge Cases & Failure Modes
- Where will this fail under extreme load, weird input, or unexpected timing?
- What happens with null, undefined, empty string, empty array, negative numbers?
- What if `packs.json` is missing or malformed?
- What if a Telegram API call returns null? Is the caller checking?
- What if the webhook receives an update type nobody expected?
- What if a pack's photo or video file is missing on disk?

### 2. Architectural Integrity
- Does this violate established patterns in the codebase?
- Does it introduce technical debt? Is it a hack or a proper solution?
- If this is a fix for a previous commit in the same PR, was the original approach wrong? Should it have been designed differently from the start?
- Are there multiple commits that touch the same lines? Does the churn indicate the author was guessing?
- Does the commit message match what the code actually does?

### 3. Performance & Memory
- Are there O(n²) loops hidden in the logic?
- Are large result sets being materialized when only a count or subset is needed?
- Are there `set_time_limit(0)` calls without `ignore_user_abort(true)`?
- Are there file handles opened but never closed on error paths?
- Does the per-pack send loop respect Telegram's per-chat rate limit (~1/s)?

### 4. Security & Data Safety
- Can user-controlled data (instruction HTML, message text) break Telegram's HTML parser via injection?
- Is `WEBHOOK_SECRET` checked before any side-effecting code runs?
- Is `ADMIN_USER_ID` checked before reaching any handler?
- Are there race conditions in read-then-write file operations (e.g., `instructions.html`, `pending_instructions.flag`)?
- Could a `telegramAPI()` failure leave `instructions.html` in an inconsistent state (half-written, partial write)?
- Does the bot ever leak `BOT_TOKEN` or `WEBHOOK_SECRET` into logs or replies?

### 5. State Management & Flow Correctness
- Every `http_response_code(200); exit;` — does it fire at the right point, or does it skip cleanup?
- Pending-edit flag (`pending_instructions.flag`): every armed path has a clearing path? `/cancel`, slash command, captured text — all covered?
- After a long batch send (5+ messages with 3s pacing), does `fastcgi_finish_request()` happen BEFORE the loop, so Telegram doesn't retry the webhook mid-send?
- If the webhook is called twice with the same update (Telegram retry), what happens?
- If admin sends `/N` while another `/N` is mid-flight (overlapping invocations), do they collide?

### Additional Checks Per Commit:

**Spec Compliance:**
- Does the code match what the spec describes? (Check the source spec at `cigdemcrystall_bot/phoenixtghelper_bot_spec.md` if needed.)
- If the spec was updated, does the spec text match the code behavior exactly?

**Regression Risk:**
- Does this commit break something that was working in a previous commit in this PR?
- If a feature was added in commit N and modified in commit N+5, is the final behavior correct?

## Step 6: Post Inline Comments for EVERY Issue

```bash
gh api repos/denisbalon/phoenixtghelper_bot/pulls/<NUMBER>/comments \
  -f body="**Commit \`<SHORT_SHA>\`:** <description of issue>

**What could go wrong:** <concrete scenario with specific user actions>
**Root cause:** <why this is a design problem, not just a typo>
**Fix:** <specific code suggestion>" \
  -f path="<file path>" \
  -F line=<line number> \
  -f commit_id="<full SHA>"
```

## Step 7: Post Summary Review

```bash
gh api repos/denisbalon/phoenixtghelper_bot/pulls/<NUMBER>/reviews \
  -f body="<review body>" \
  -f event="COMMENT"
```

Use `event="APPROVE"` ONLY if you genuinely found zero issues after exhaustive analysis. Use `event="REQUEST_CHANGES"` if any critical or important issues exist.

## Summary Review Template

```markdown
# PR #<number> Deep Review — <PR title>

## Commits Reviewed: <total count>

## Per-Commit Analysis

### `<SHA>` — <commit message>
**What it does:** <1-2 sentence description of the ACTUAL code change, not just the commit message>
**Issues:**
- `file:LINE` — <description>. **Scenario:** <specific user actions that trigger this>. **Root cause:** <why>. **Fix:** <suggestion>
<!-- If genuinely clean after thorough analysis: -->
**Issues:** None — <explain specifically what you checked: "Traced all callers of X, verified null checks on telegramAPI results, confirmed pending_instructions.flag is cleared on every exit path">

<!-- REPEAT for EVERY commit — NO EXCEPTIONS -->

## Critical Issues (must fix before merge)
- **`file:LINE`** (commit `SHA`) — Description. **Scenario:** how to trigger. **Root cause:** why. **Fix:** code suggestion.

## Important Issues (should fix)
<!-- Same format -->

## Minor Issues
<!-- Same format -->

## Patterns & Systemic Concerns
<!-- Cross-commit patterns: Are there recurring issues that indicate a deeper design problem? -->
<!-- Technical debt introduced by this PR -->
<!-- Suggestions for architectural improvements -->

## Verdict
<!-- APPROVE | APPROVE WITH COMMENTS | REQUEST CHANGES -->
<!-- JUSTIFY your verdict with specific evidence. "Looks good" is not a justification. -->
```

## What Makes a BAD Review (DO NOT DO THIS)

- "Verified code change. No issues found." — This is LAZY. What did you verify? What edge cases did you check?
- "Logic correctly identifies and handles the condition" — WHICH condition? What inputs did you consider? What if the input is null?
- Copy-pasting the same finding across multiple commits — Each commit is DIFFERENT. Analyze individually.
- Only finding 2 issues in a 65-commit PR — Statistically impossible for code touching async flows and file-state machines. Look harder.
- Approving without reading the surrounding code — A diff without context is meaningless.
- Writing "No issues found" for state changes without tracing ALL state transitions through ALL handlers.
- Treating a minor issue as minor without investigating if it's a symptom of a larger flaw.

## What Makes a GOOD Review (DO THIS)

- "In `webhook.php:42`, the admin gate (`$userId !== ADMIN_USER_ID`) returns 200 silently. But the pending-instructions flag check happens BEFORE the gate runs in `handleAdminInstructionsCapture()`. **Root cause:** if the flag is armed and a non-admin sends text, no gate is hit because `isInstructionsEditPending()` runs before any user-id check — though in this codebase the flag is only set by admin actions, so the only way to reach this is if a non-admin manages to write the flag file directly. Defense-in-depth would move the gate above the flag check."
- "Cross-referencing `sendMessage` callers: it's called from `webhook.php` and `handlers/start.php`. The new `link_preview_options` parameter defaults to `false` so all existing callers are safe. Verified."
- "Commit `abc1234` adds a delay between sends in the batch loop, but commit `def5678` removes the delay between the IG-URLs message and the first video. **Root cause:** the loop refactor split the IG message out of the videos loop but didn't carry the trailing `sleep($delay)` outside the loop. Net effect: tighter pacing on first video, possible 429 on rapid `/N` invocations."

## Minimum Requirements

- **Per-commit sections:** EVERY commit gets its own analysis. No batching. No skipping.
- **Cross-referencing:** For any function modified, verify callers still work. For any new message action, verify handlers exist.
- **Issue count:** A PR with 50+ commits touching async flows and file-state machines WILL have issues. If you found fewer than 10, you didn't look hard enough.
- **Scenario descriptions:** Every issue must describe concrete user actions that trigger the bug, not abstract concerns.
- **Root cause analysis:** Every issue must explain WHY it's a problem, not just WHAT is wrong.
- **Code references:** Every issue must reference a specific file and line number.
- **Review length:** Proportional to PR size. A 65-commit PR needs thousands of words of analysis, not a few hundred.

## Rules

- **ALL output goes to GitHub via `gh` commands. NEVER create local files. NEVER commit to the repo.**
- **Read the actual source files, not just diffs.** Diffs without context are misleading.
- **Cross-reference callers and dependencies.** A function change that breaks a caller is a critical bug.
- **Be adversarial.** Your job is to find problems, not to be nice.
- **Be specific.** Vague concerns are useless. Concrete scenarios with user actions are valuable.
- **Be proportional.** More commits = more analysis = longer review.
- **Treat minor issues as symptoms.** Investigate if they indicate a deeper design flaw.
