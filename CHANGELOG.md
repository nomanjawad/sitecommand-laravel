# Changelog

## 0.1.1

Laravel 12 and 13 only: every Laravel 11 release now carries security advisories, so Composer refuses to install them.

## 0.1.0

First release: signed `info`, `health`, `errors`, `deps`, `actions` routes (same contract as `@sitecommand/node`), built-in health checks for Laravel (database, cache, storage, disk, queue, maintenance, config), errors read from the app's own log, Composer and npm dependencies with their ecosystem, registered health checks and actions, masking, and the `sitecommand:connect`, `sitecommand:verify` and `sitecommand:check` commands.
