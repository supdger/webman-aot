# Neutral Webman plugin fixture

Copy the `plugin/neutral` tree into an isolated Webman 2.2.4 test project
with standard Composer `plugin\\` autoloading. Re-run `webman-aot build`,
then inspect `dist-aot/coverage.json`: `PingController.php` must be
`compiled-direct`, and its PHP source must not be in the distribution.
After starting the isolated package, `GET /neutral/ping` must return
`neutral-ok`. Run the same request with ordinary PHP as a regression check.

This fixture has no database, secrets, application-specific identifiers,
or AOT-only branch.
