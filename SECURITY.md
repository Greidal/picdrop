# Security Policy

## Supported versions

Only the latest release receives security fixes. Please update to the newest version
(see [UPGRADING.md](UPGRADING.md)) before reporting.

## Reporting a vulnerability

Please **do not open a public issue** for security problems.

Report them privately via GitHub:
[**Security → Report a vulnerability**](https://github.com/Greidal/picdrop/security/advisories/new).

Include what you found, how to reproduce it and the affected version. You'll get an answer as soon
as possible; fixes are released as a new version and credited in the advisory unless you prefer
otherwise.

## Scope

In scope: the PicDrop application, its container image and the provided `docker-compose.yml`.
Out of scope: vulnerabilities in your own reverse proxy, host or infrastructure configuration.

## Verifying images

Release images are signed with build provenance and carry an SBOM:

```sh
gh attestation verify oci://ghcr.io/greidal/picdrop:<version> --owner Greidal
```
