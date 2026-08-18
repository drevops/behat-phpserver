# Security Policy

## Supported versions

Security fixes are released for the latest `2.x` release. Earlier series are no longer maintained.

| Version | Supported          |
|---------|--------------------|
| 2.x     | :white_check_mark: |
| < 2.0   | :x:                |

## Reporting a vulnerability

Please do not report security vulnerabilities through public GitHub issues.

Report them privately through [GitHub Security Advisories](https://github.com/drevops/behat-phpserver/security/advisories/new). If that is not available to you, email <alex@drevops.com> instead.

Include as much of the following as you can, so the report can be triaged quickly:

- The type of issue and the affected component.
- The full path of the source files involved.
- The version or commit affected.
- Steps to reproduce, including any configuration required.
- The impact, and how an attacker might exploit it.

You can expect an acknowledgement within 5 working days, and an assessment of the report with expected next steps within 10 working days.

## Scope

This package starts PHP's built-in web server and a mock API server to support automated tests. Both are intended for local and CI test environments only, and neither is hardened for use on a public network or with untrusted input. Reports that depend on deliberately exposing these servers to an untrusted network are out of scope.
