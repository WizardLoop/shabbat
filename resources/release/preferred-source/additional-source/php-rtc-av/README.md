# PHP AV Libraries

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-BSD-blue.svg)](LICENSE)

PHP AV Libraries provides FFI bindings to AV libraries (including AVCodec and AVFilter) for encoding, transcoding, and manipulating audio/video streams.

## About this fork

This is the `danog/php-rtc-av` fork used by MadelineProto. It targets PHP 8.2+, loads FFI only when transcoding is requested, and rejects FFmpeg libraries whose ABI does not match the bundled FFmpeg 9 headers. Already-encoded media can be packetized without FFI.

All internal Composer dependencies use their `danog/php-rtc-*` package names directly, so installing a component selects the maintained danog packages throughout the dependency graph.

## Requirements

- PHP ≥ 8.2
- FFI and matching FFmpeg 9 shared libraries only when encoding, decoding, or transcoding media
- Linux (Windows and macOS support planned for future releases)
- FFmpeg/libav shared libraries (libavcodec, libavfilter, etc.)
  - Compatible with FFmpeg version 9.x

## Documentation

This package is part of the PHP WebRTC library. For complete documentation, examples, and API reference, visit:

[PHP WebRTC Documentation](https://github.com/danog/php-rtc-av)

## Credits

### Authors

- **Daniil Gentili**
  - Website: [daniil.it](https://daniil.it)
  - GitHub: [danog](https://github.com/danog)
- **Amin Yazdanpanah**
  - Website: [aminyazdanpanah.com](https://www.aminyazdanpanah.com)
  - Email: [contact@aminyazdanpanah.com](mailto:contact@aminyazdanpanah.com)
- **Sana Moniri**
  - GitHub: [sanamoniri](https://github.com/sanamoniri)

## Reporting Issues

Found a bug? Please open an issue on our [GitHub repository](https://github.com/your-repo-here).

## License

BSD 3-Clause License. See [LICENSE](LICENSE) for full details.
