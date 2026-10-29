<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

/**
 * One purpose an owner accepts files for: a closed allowlist of media types (each with its allowed extensions), a byte ceiling that is enforced while the upload
 * streams, whether the file comes from outside the company (it then needs a clean malware scan before it can ever be downloadable, D3) and a pixel ceiling for images.
 * Active or executable content (SVG, HTML, scripts, executables, archives) can never be listed: the constructor refuses it (D4).
 */
final readonly class FilePurpose
{
    /** Media types and extensions that can carry active content or code; closed by construction, whatever an owner asks for. */
    private const FORBIDDEN_TYPES = [
        'image/svg+xml', 'text/html', 'application/xhtml+xml', 'text/xml', 'application/xml', 'text/javascript', 'application/javascript', 'application/x-javascript',
        'application/x-httpd-php', 'text/x-php', 'application/x-sh', 'text/x-shellscript', 'application/x-executable', 'application/x-dosexec', 'application/x-msdownload',
        'application/vnd.microsoft.portable-executable', 'application/x-mach-binary', 'application/zip', 'application/x-zip-compressed', 'application/gzip', 'application/x-gzip',
        'application/x-tar', 'application/x-7z-compressed', 'application/x-rar-compressed', 'application/vnd.rar', 'application/java-archive', 'application/x-bzip2',
    ];

    public const FORBIDDEN_EXTENSIONS = [
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'js', 'mjs', 'php', 'phtml', 'phar', 'exe', 'dll', 'com', 'bat', 'cmd', 'sh', 'bash', 'ps1', 'vbs', 'jar', 'msi', 'scr', 'so',
        'zip', 'gz', 'tgz', 'tar', '7z', 'rar', 'bz2', 'xz', 'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp',
    ];

    /** @param array<string, list<string>> $mediaTypes allowed sniffed media type => allowed lowercase extensions */
    public function __construct(
        public string $key,
        public array $mediaTypes,
        public int $maxBytes,
        public bool $externallySupplied = false,
        public int $maxPixels = 40_000_000,
    ) {
        if (preg_match('/^[a-z0-9]+(\.[a-z0-9_-]+)+$/', $key) !== 1 || strlen($key) > 120) {
            throw new FileException("'{$key}' is not a valid file purpose key.", 'purpose', 'purpose_invalid');
        }
        if ($maxBytes < 1 || $maxPixels < 1 || $mediaTypes === []) {
            throw new FileException("File purpose '{$key}' needs a positive byte ceiling and at least one media type.", 'purpose', 'purpose_invalid');
        }
        foreach ($mediaTypes as $type => $extensions) {
            if (preg_match('#^[a-z]+/[a-z0-9.+-]+$#', (string) $type) !== 1 || in_array($type, self::FORBIDDEN_TYPES, true) || str_contains((string) $type, 'script') || str_contains((string) $type, 'html')) {
                throw new FileException("Media type '{$type}' can carry active content and can never be accepted.", 'purpose', 'media_type_forbidden');
            }
            if ($extensions === []) {
                throw new FileException("Media type '{$type}' needs its allowed extensions.", 'purpose', 'purpose_invalid');
            }
            foreach ($extensions as $extension) {
                if (preg_match('/^[a-z0-9]{1,10}$/', $extension) !== 1 || in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
                    throw new FileException("Extension '{$extension}' can carry active content or code and can never be accepted.", 'purpose', 'extension_forbidden');
                }
            }
        }
    }

    public function isImage(string $mediaType): bool
    {
        return str_starts_with($mediaType, 'image/');
    }
}
