# Publishing to Packagist

The package is `yesly/yesly-php` (`composer require yesly/yesly-php`).
Packagist does not receive uploads: it reads the GitHub repo, and every
`v*` tag becomes a version. There is no release workflow.

## One-time setup

1. **Make the GitHub repo public.** Packagist.org only serves public repos. A
   private package needs Private Packagist (paid), or a `vcs` repository in
   each customer's `composer.json`.
2. **Packagist account.** Sign in at <https://packagist.org/login/> with
   *Login with GitHub*, using an account with admin rights on `yesly-dev`.
3. **Submit the package.** Go to <https://packagist.org/packages/submit>,
   enter `https://github.com/yesly-dev/yesly-php` and click *Check*, then
   *Submit*. The name comes from `composer.json` (`yesly/yesly-php`). The
   vendor `yesly` becomes yours with this first package; on 2026-11-07 it had
   no packages.
4. **Automatic updates.** Signing in with GitHub lets Packagist install its
   GitHub hook, so new tags show up within a minute. If the package page warns
   that it is not auto-updated:
   - Open <https://packagist.org/profile/> and copy your API token.
   - In the repo, go to *Settings* → *Webhooks* → *Add webhook*.
   - Payload URL: `https://packagist.org/api/github?username=<your packagist user>`.
   - Content type: `application/json`.
   - Secret: the API token.
   - Event: *Just the push event*.
5. **Maintainers.** On the package page, *Maintainers* → add a second person,
   so the package does not depend on one account.

## Each release

1. Bump `VERSION` in `src/Version.php`. It is sent in `User-Agent`;
   `composer.json` has no version field, because the tag is the version.
2. Merge to `main` and wait for CI.
3. Tag and push:

   ```bash
   git tag v0.2.0 && git push origin v0.2.0
   ```

Check <https://packagist.org/packages/yesly/yesly-php> for the new version.

## If something goes wrong

- Deleting a tag removes the version from Packagist at the next sync. Anyone
  who already installed it keeps their copy, so prefer a new patch version.
- *Abandon* on the package page marks the whole package as abandoned. It is
  not for one bad version.
