# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in this module, please report it
**privately**. Do **not** open a public GitHub issue for security problems.

- Email: **dev@pigeonexpress.bg** (subject: `SECURITY`)
- Please include: a description, steps to reproduce, the affected version, and
  the potential impact.

We will acknowledge your report within **5 business days** and provide a
remediation timeline after triage. Please give us a reasonable opportunity to
fix the issue before any public disclosure.

## Supported Versions

| Version | Supported |
|---------|-----------|
| 1.x     | ✅         |

## Credentials & secrets

Pigeon Express API credentials (`pk_…` / `sk_…`) are configured at **runtime** —
in the Magento admin (Stores → Configuration → Sales → Shipping Methods → Pigeon
Express) and stored encrypted in the Magento database. **No secrets are stored in
this repository**, and none should ever be committed. The carrier writes API
diagnostics to `var/log/pigeonexpress.log` (outside this repo); avoid pasting full
request/response payloads containing credentials into issues or PRs.
