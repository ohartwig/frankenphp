# Contributing

Thank you for considering a contribution. Issues and pull requests are welcome
at <https://github.com/ohartwig/frankenphp>.

## How changes reach this repository

The GitHub repository is a read-only mirror. The source is maintained in a
private GitLab instance, and every release is published to GitHub by CI: the
history is rewritten to leave out files that only matter to that instance
(CI configuration, agent notes, planning documents) and then force-pushed,
together with the new release tag. Packagist picks the tag up from GitHub.

A pull request is therefore not merged on GitHub. When a change is accepted,
the maintainer applies it upstream with your authorship and sign-off intact,
closes the pull request with a reference to it, and the change appears here
with the next release. Please do not base long-lived work on the GitHub
history: because it is rewritten, commit ids on GitHub are not stable across
changes to the mirror configuration.

## Before you open a pull request

- `composer validate --strict` passes.
- The tests pass (`vendor/bin/phpunit`) and PHPStan reports nothing new
  (`vendor/bin/phpstan analyse`).
- New behaviour comes with a test, and changed behaviour is noted in the
  README where it affects users.
- Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/)
  (`fix: …`, `feat: …`, `docs: …`); the release version is derived from them.
- Code, comments and documentation are written in English.

Some development tooling is resolved from a private package registry, so a
full `composer install` from a GitHub clone may not work yet. If that stops
you from running a check, say so in the pull request; the upstream pipeline
runs the full suite on every change.

## Developer Certificate of Origin

Every commit must be signed off, certifying the
[Developer Certificate of Origin](https://developercertificate.org/):

```sh
git commit -s
```

## Signed commits

Commits must also carry a verifiable signature (SSH or GPG):

```sh
git config commit.gpgsign true
```

## Security

Please do not report security problems in public issues; follow
[SECURITY.md](SECURITY.md) instead.

## Licence

By contributing you agree that your contribution is licensed under the MIT
License, like the rest of the repository.
