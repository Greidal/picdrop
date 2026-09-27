## What & why

<!-- What does this change and why? Link issues with "Closes #123". -->

## How to test

<!-- Steps for the reviewer, screenshots for UI changes. -->

## Checklist

- [ ] PR title follows Conventional Commits (`feat(scope): …`, `fix: …`) – it becomes the release note
- [ ] Tests added/updated (PHPUnit for `src/lib`, Playwright for user flows)
- [ ] `composer lint`, `composer analyse`, `composer test` pass locally
- [ ] Security rules in [AGENTS.md](../AGENTS.md#security-rules-non-negotiable) followed (escaping, prepared statements, CSRF, access checks)
- [ ] Operators need to act? `BREAKING CHANGE:` footer + `UPGRADING.md` updated
- [ ] New config: documented in README, `example.env` and `docker-compose.yml`
