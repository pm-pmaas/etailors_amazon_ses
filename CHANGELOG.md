Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [1.0.31] - 2026-01-24
### Added
- Mautic 7 compatibility.
### Changed
- Updated `composer.json` to support `mautic/core-lib` version `^7.0`.
- Maintained backward compatibility with Mautic 5 and 6.
- Ensured PHP 8.1 compatibility remains (Note: Mautic 7 itself requires PHP 8.2+).
- Internal alignment with Mautic 7 platform requirements.

## [1.0.30] - 2026-01-24
### Added
- Added Zurich (`eu-central-2`) region support.
### Fixed
- Fixed bug in updating soft bounce DNC entry logic in `CallbackSubscriber.php`.

## [1.0.29] - 2025-12-09
### Added
- Added `CHANGELOG.md` and `SECURITY.md`.
- Improved soft bounce handling with custom channel and labeling.
### Fixed
- Improved resilience in `CallbackSubscriber.php` when processing various SNS payload types.

## [1.0.28] - 2025-12-09
### Security
- Fixed a security issue by validating the Amazon SNS `SubscribeURL` endpoint. This ensures that only legitimate SNS subscription confirmation requests are accepted (mitigates SSRF).
### Added
- Added `eu-west-3` (Paris) region support.
### Changed
- Improved handling of special characters and IDN encoding in the "From" name using Symfony's `Address` class.

## [1.0.27] - 2025-11-20
### Added
- Added `eu-west-2` (London) region support.
### Fixed
- Fixed escaping of quotes in `FromEmailAddress`.

## [1.0.26] - 2025-11-15
### Changed
- Updated `README.MD` with detailed Composer installation instructions (using `-W` flag and VCS repository).

## [1.0.25] - 2025-11-10
### Added
- Added `composer/installers` requirement to ensure correct plugin installation directory.
### Changed
- Improved `composer.json` with `prefer-stable: true`.

## [1.0.24] - 2025-11-05
### Added
- Added example IAM user policy to `README.MD`.

## [1.0.23] - 2025-11-01
### Changed
- Updated `mautic/core-lib` version requirement to support `^6.0`.

## [1.0.22] - 2025-10-25
### Fixed
- Fixed `getAccount` method failure when `ses:GetAccount` action permissions are missing by adding a fallback to `DEFAULT_RATE`.
- Cleaned up default rate limit handling in `AmazonSesTransportFactory.php`.

## [1.0.21] - 2025-10-20
### Added
- Added `us-west-1` (N. California) region support.

---

Older changes may be documented in commit messages and release notes on the repository.
