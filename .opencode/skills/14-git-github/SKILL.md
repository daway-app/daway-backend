---
name: 14-git-github
description: Use for git operations, branching, commits, pull requests, merge conflict resolution, GitHub workflows, and repo hygiene in Daway. Trigger on git, commit, branch, push, PR, pull request, merge, conflict, GitHub Actions.
---

# Daway — Git & GitHub Engineering

## Role

Act as a Senior Software Engineer responsible for safe Git workflows on Daway.

## Verified repo state

- Repo: `github.com/daway-app/daway-backend`, default branch is `develop` (protected workflow: CI runs on develop push/PR only); other branches: `new-version`, `origin/main`, `origin/feature/US-02-auth-login`, `origin/feature/US-04-profile-api`, `origin/feature/admin-dashboard`
- Feature branch convention: `feature/US-XX-slug` (user-story based)
- CI: `.github/workflows/laravel.yml` — runs `php artisan test` (sqlite) on push/PR to `develop` only. No lint/format check step (pint is installed but not enforced)
- Known dirty state at audit time: deleted `postman/daway-auth-profile.postman_collection.json` (uncommitted); junk file `langPath())` at repo root not gitignored
- `.gitignore` covers .env, backups, `*.sql`, `daway_backup.sql`, `notif-test.js`, `oracle_vm_setup.sh`, tool dirs

## Before changes

Run `git status`; understand current branch, modified/untracked/staged files. Never overwrite user changes or uncommitted work.

## Commits

Focused, descriptive, conventional style (repo uses feature-style history):
- `feat: add pharmacy medicine availability API`
- `fix: prevent duplicate OTP requests`
- `perf: optimize pharmacy search query`
- `refactor: simplify medicine service`
- `test: add pharmacy authorization tests`
- `chore: clean stale css entries from vite config`

Never include: `.env`, secrets, keys, passwords, debug dumps, temporary files, `node_modules`, `vendor`, logs. Review staged files before committing.

## Branches & PRs

- Prefer focused branches: `feature/<slug>`, `fix/<slug>`, `perf/<slug>`, `refactor/<slug>` (follow the existing `feature/US-*` convention where it applies)
- PR description should cover: problem, solution, files changed, tests run, possible risks
- CI must pass (tests on develop) before merging; do not force-push to shared branches

## Safety rules

Never: force push without explicit permission, reset user work, delete branches automatically, rewrite history without explicit request, merge without tests passing.

## Merge conflicts

Understand BOTH sides before resolving; preserve required functionality from both; do not blindly pick ours/theirs; run tests after resolving.

## Git hygiene

- Watch for junk files: `langPath())` (PsySH stray) is at repo root — propose adding to `.gitignore` or removing with confirmation
- `daway_backup.sql` and other `*.sql` must never be committed (already ignored)
- Keep `.env.example` in sync when new env vars are added (it already has Daway-specific keys: SESSION_SECURE_COOKIE, SANCTUM_EXPIRATION, TRUSTED_PROXIES — keep them)

## Final report

After git-related work report: branch, changed files, commit(s) created, tests run, remaining concerns.
