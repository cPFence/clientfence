# Security Policy

## Reporting a vulnerability

Report vulnerabilities privately, never in a public issue:

- through [GitHub's private vulnerability reporting](https://github.com/cPFence/clientfence/security/advisories/new), or
- by email to support@cpfence.app.

Include the affected files or pages, how to reproduce the issue and what an attacker could
do with it. A proof of concept helps.

## Supported versions

Security fixes go into the latest ClientFence release only: update with System → Update (or
the release archive at clientfence.com) to get them. Before 1.0.0, older pre-releases
(1.0.0-alpha, -beta and -rc versions) get no fixes of their own.

| Version | Security fixes |
| --- | --- |
| The latest release | Yes |
| Any older release | No: update to the latest |

## Out of scope

- Reports from automated scanners without a demonstrated impact.
- Vulnerabilities in third-party libraries: report them to the library first.
- Social engineering, physical attacks and attacks that need an already compromised device
  or network.
- Anything under `tests/`.
