# AGENTS.md

Guidance for agents working in the RETD codebase.

## Verification commands
- Run the full automated suite: `php artisan test`
- Code style: `vendor\bin\pint --test` (Pin-Fix: `vendor\bin\pint`)
- Frontend build (from `frontend/`): `& ".\node_modules\.bin\vite.cmd" build`
- Data integrity: `php artisan lra:integrity-check`
- Lint a file: `php -l <file>`
- These are available, not automatic. Run the cheapest command that actually proves the requested change (a filtered test before the full suite; `php -l` or Pint on touched files only) and keep proportional to the change.
- Commands that write to the working tree or the database are not authorized by default: `vite build` rewrites `frontend/dist/`, and migrations, seeders, and reset commands alter state. Announce the side effect and get approval first. Never run one merely because the change sits near it.

## Standing policies (user directives — always follow)
1. **Never remove real/operator-entered data without explicit authorization.**
   The automated suite (`php artisan test`) is isolated (sqlite in-memory + RefreshDatabase) and does NOT touch the dev database. Manual/browser testing and live drills DO create records in the dev DB (`retd_lra`, MySQL) — and the product owner treats those records as real data.
   Do NOT run `php artisan app:reset-dev --force` (or delete/truncate any business, audit, or seeded data) unless the user explicitly authorizes the removal at that moment. Preview with `php artisan app:reset-dev --dry-run` and ask first whenever cleanup is being considered. Do not silently tidy or purge evidence/uploaded files either.
   The ONLY dev data that may be removed as part of an update + test cycle is data the system itself seeded during that cycle (its own test fixtures / tooling-generated drill records) — never records entered by the user or that existed beforehand. If a live drill created fixtures, leave them in place unless the user asks to remove them.
2. Terminology: the system is RETD — an independent internal task-management/enforcement system. It is NOT "LITAS" and never references it. The external bill/registry source is "the source system". Do not reintroduce LITAS terminology in code, UI, schema, or docs. The only allowed LITAS text is inside executed migration definitions and migration rename `up()/down()` references (required for migration integrity).
3. **Strict change control: fix only what was requested.** A specific instruction authorizes one specific change. Do not expand scope, refactor, redesign, or fix additional bugs without explicit authorization. Full policy and required report format: see [Change-control policy](#change-control-policy) below.

## Change-control policy

> **PRECISION OVER BREADTH. CONTROLLED CHANGE OVER AUTOMATIC REFACTORING. PRESERVATION OVER REWRITING. VERIFICATION OVER ASSUMPTION. AUTHORIZATION BEFORE SCOPE EXPANSION.**

The objective is not to modify as much code as possible. It is to make **the correct change, in the correct place, for the correct reason, while preserving everything that already works.**

### Core rule
Fix the specific issue in the specific section, component, function, workflow, page, or section the user identified. **Do not fix what was not requested.** Code outside the requested scope is assumed intentional and must be preserved.

A specific bug report is **not** authorization to rewrite the application, refactor unrelated components, redesign unrelated pages or workflows, change database structures, APIs, permissions, or validation, remove existing functionality, replace working code with a merely-more-preferred approach, correct unrelated bugs found along the way, or "clean up" surrounding code.

### Required order of work
1. **Identify the requested scope** — what was asked, which page/section/component/function/workflow/API/file is involved, what is currently wrong, what the expected behaviour is, and which related code is genuinely necessary. Do not broaden it.
2. **Inspect before editing** — find the smallest appropriate change. Never rewrite a file, component, module, or section when a smaller correction suffices.
3. **Make the minimal necessary change** — preserve existing functionality, workflows, permissions, UI sections, database relationships, APIs, validation, business rules, navigation, and completed fixes. Do not redesign working functionality.
4. **Verify the requested fix** — test the requested behaviour specifically: original bug resolved, requested behaviour works, surrounding functionality in the affected area intact, no obvious regression in the directly affected component.
5. **Stop.** Do not continue into other areas because further improvements are visible.
6. **Report additional findings separately** (format below) and wait for authorization.

### Protected functionality
Previously completed and tested functionality is protected. Do not delete previously implemented sections, replace working components unnecessarily, or remove fields, buttons, routes, permissions, workflow states, validation, database relationships, or completed features. Do not change already-approved terminology or business rules. If a requested change genuinely requires touching an existing component, make the narrowest possible change and explain why that component had to change.

### Shared components
If the request touches a shared component, service, hook, API, model, or utility: determine why it must change, which parts are relevant, which sections depend on it, and whether those sections are affected. Identify the risk before editing. If broader change is needed, state: **"This requested fix requires modification of a shared component that affects [X, Y, Z]. I will limit the change to [specific functionality] and preserve all unrelated behavior."** A broader redesign that is merely desirable is not authorized.

### No whole-file rewrites
Patch the specific function, component, condition, import, query, authorization check, or UI behaviour; preserve the rest. A full rewrite is allowed only if explicitly requested or technically unavoidable, and if unavoidable, explain why before proceeding.

### No "while I'm here" fixes
Do not change things merely because you notice them: UI inconsistencies, validation issues, naming inconsistencies, code-quality issues, performance opportunities, security improvements outside scope, a design you prefer, outdated components, refactoring opportunities, or a different schema you would choose. Record these as recommendations only.

### Testing respects scope
Test the requested change first. A request to fix one screen is not authorization to redesign or modify unrelated valuation, enforcement, payment, GPS, or reporting functionality. Where the change does affect a shared component, run reasonable regression testing on that dependency only.

### Commands and side effects
Builds, tests, migrations, seed operations, resets, and code generation must be appropriate to the requested change. Do not reset the database, re-seed unrelated data, rewrite generated files, rebuild unrelated modules, change unrelated migrations, alter unrelated fixtures or configuration, or re-run destructive development operations. **If a verification command has side effects, explain them before running it.**

Traceability: one requested issue → one controlled change → one verification → one confirmed result. The user must be able to tell what was asked, what was changed, what was deliberately not changed, what was tested, whether it is resolved, what remains, and what still needs authorization.

### Additional issue found during a task
Do not stop the requested implementation because an unrelated issue surfaced — unless it makes the task technically impossible or creates an immediate security/data-integrity risk. Finish and verify the requested task first, then report using **exactly** this structure and stop:

```
Additional Issue Identified — Authorization Required

Location:      [section/component/function]
Issue:         [description]
Impact:        [potential consequence]
Recommended Fix: [proposed solution]
Scope of Change: [exact files/components/functions expected to be modified]
Authorization: Awaiting approval before implementation.
```

Then wait. Do not implement it until explicitly authorized.

### Security and data-integrity exception
If a credible immediate risk of unauthorized access, data exposure, data or financial-data corruption, authentication or authorization bypass, destructive data loss, duplicate financial transactions, incorrect payment records, or serious integrity failure is discovered: **stop before making unrelated corrective changes and report immediately.** Explain what was found, why it is a security/data-integrity concern, what could happen if left unchanged, the immediate corrective action recommended, and the authorization required. Do not silently expand scope. If emergency containment is absolutely necessary to prevent active damage, label it explicitly as an **emergency containment action** and limit it strictly to preventing the immediate risk.

### Authorization language
When reporting additional work, use: **"The requested [task] fix has been completed and verified. During verification, I identified a separate issue in [area]. I have not modified that section. I recommend [specific fix]. Please authorize this additional change if you want me to proceed."**

Authorization is **task-specific** and may **not** be inferred from silence, from the existence of a bug, from the bug being related or convenient to fix, from a previous authorization for a different section, or from a general instruction to improve the application.

### Final report format
After completing a requested task, report concisely under these headings, then stop and wait:

```
### Completed
### Changed
### Verified
### Not Changed
### Additional Findings — Authorization Required
```

## Notes
- Laravel ~13, PHP 8.3. DB: MySQL `retd_lra` (dev), sqlite `:memory:` (phpunit).
- `DatabaseSeeder` is deliberately non-destructive: it never deletes non-canonical users.