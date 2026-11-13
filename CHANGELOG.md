# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab Open-Meteo: weather series at a point - hourly, every 15 minutes or daily, any variable
  Open-Meteo names, with the units it answers in - from the forecast API (92 days back, 16 ahead)
  and the historical archive (ERA5, from 1940), joined when a period spans both; and the air quality
  at a point (PM2.5, PM10, NO2, O3, the European and US indices, pollens...), hourly or daily, from
  2013 to five days ahead; free for non-commercial use, a key or a self-hosted instance otherwise.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/open-meteo` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
