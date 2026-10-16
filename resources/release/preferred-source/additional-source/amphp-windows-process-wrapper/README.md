# Windows Process Wrapper for Amp

This application is a helper for [amphp/process](https://github.com/amphp/process) that enables non-blocking communication with a child process on Windows, by passing the data through TCP sockets.

## Installation

The application is bundled with amphp/process does not need to be installed separately.

## Requirements

* Microsoft Windows 7+
* [Visual C++ Redistributable for Visual Studio 2015](https://www.microsoft.com/en-us/download/details.aspx?id=48145) x86 (for `ProcessWrapper.exe`) or x64 (for `ProcessWrapper64.exe`)

## Versioning

`amphp/windows-process-wrapper` follows the [semver](http://semver.org/) semantic versioning specification like all other `amphp` packages.

## Security

If you discover any security related issues, please email [`amphp.security.windows@daverandom.com`](mailto:amphp.security.windows@daverandom.com) instead of using the issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
