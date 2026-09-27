# Contributing to PicDrop

Thanks for helping! This page covers the workflow; coding conventions and the security rules every
change must follow are in [AGENTS.md](AGENTS.md) (written for humans and AI agents alike).

> **Licensing:** the project currently has no open-source license. Please get in touch with the
> maintainer before your first contribution (see [README](README.md#license)).

## Setup

Requirements: PHP 8.5 with `gd`, `exif`, `mysqli`, `zip`, `mbstring`; Composer; Docker; Node.js 22
(only for the end-to-end tests).

```sh
git clone https://github.com/Greidal/picdrop.git && cd picdrop
composer install
docker build -t picdrop:e2e .
cd tests/e2e && npm ci && npx playwright install chromium webkit
npm run stack:up   # app: http://localhost:18080, mails: http://localhost:18025
```

Log in with the admin account from `tests/e2e/e2e.env`. Mails (verification, password reset,
invites) are caught by Mailpit and never leave your machine.

## Workflow

1. Open or pick an issue and say you're working on it; for bigger changes discuss the approach first.
2. Create a branch (`feat/…`, `fix/…`, `docs/…`) – in your fork if you don't have write access.
3. Make focused changes with tests (see [AGENTS.md](AGENTS.md#conventions)).
4. Run the checks locally:
   ```sh
   composer lint && composer analyse && composer test
   cd tests/e2e && npm run typecheck && npm test
   ```
5. Open a pull request against `main` and fill in the template. The **PR title must follow
   [Conventional Commits](https://www.conventionalcommits.org/)** (`feat(gallery): …`, `fix: …`) –
   it becomes the release note.
6. CI must be green and the maintainer reviews the PR. Merged PRs are released automatically.

## Releases

Releases are fully automated with semantic-release: every merge to `main` that contains a `feat`
or `fix` publishes a new version, a GitHub Release with notes and a signed multi-arch image on GHCR.
If operators have to do something when updating, add a `BREAKING CHANGE:` footer to the commit and
describe the steps in [UPGRADING.md](UPGRADING.md).

## Reporting bugs and vulnerabilities

- Bugs and ideas: [GitHub issues](https://github.com/Greidal/picdrop/issues/new/choose).
- Security issues: **never** as a public issue – see [SECURITY.md](SECURITY.md).
