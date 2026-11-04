# Local PHPUnit testing on WAMP

The runtime package omits development tests. The tests and development support
files were restored from official OpenEMR tag `v8_5_0`, commit
`c1b2b8bcbe91aa81858f7ba8b25c31d9a82639a5`.
The release ZIP also omits these files; they were obtained using a sparse Git clone.

## Verified command

Run in PowerShell from the OpenEMR root:

```powershell
& 'C:\wamp64\bin\php\php8.3.28\php.exe' -d xdebug.mode=off .\vendor\bin\phpunit -c .\phpunit-isolated.xml --testsuite unit-isolated
```

Result on 2026-10-29: 103 tests, 250 assertions, 3 incomplete tests;
zero failures or errors, exit code 0. Incomplete tests are not passes.

The [configuration](phpunit-isolated.xml) sets the test process memory limit to
1 GB because WAMP's 128 MB CLI default exhausted memory during full test discovery.
This setting does not change the web application's PHP configuration.

## Broader suite limitation

Omitting `--testsuite unit-isolated` selects all three configured suites.
The broader run discovered 8,027 tests, but did not complete. Some data providers
(including AssetCacheBusterTest) invoke `git ls-files` and fail because this
installation has no `.git` metadata. The run was interrupted; no full-suite pass
is claimed. Use a complete matching development Git checkout for those checks.
Database-backed and browser tests require their separate development services.

Restored paths: `tests/`, `phpunit-isolated.xml`, `.phpstan/`, `tools/`, `ci/`,
and `.github/` where present in the source. Existing application files, Composer
dependencies, site settings and database contents were not overwritten.
