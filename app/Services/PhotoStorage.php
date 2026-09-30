<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Vehicle photo storage.
 *
 * Uploads are validated by sniffing the real content type (never by the client
 * filename or the declared MIME), renamed to a random name, and written OUTSIDE
 * the web root in storage/uploads/cars — they are only ever emitted through a
 * controller that resolves the name from the database.
 */
final class PhotoStorage
{
    /** @var array<string, string> detected mime => file extension */
    private const ALLOWED = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/webp' => '.webp',
    ];

    public function __construct(
        private string $baseDir,
        private int $maxBytes
    ) {
    }

    /** @return string directory photos are written to */
    public function directory(): string
    {
        return $this->baseDir;
    }

    /**
     * @param array{name?:string, error?:int, tmp_name?:string, size?:int} $file
     * @return array{ok:bool, filename?:string, error?:string}
     */
    public function store(array $file): array
    {
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'No photo was selected.'];
        }
        if ($uploadError !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'The photo could not be uploaded. Try a smaller file.'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Invalid upload.'];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size < 1) {
            return ['ok' => false, 'error' => 'The photo is empty.'];
        }
        if ($size > $this->maxBytes) {
            return [
                'ok' => false,
                'error' => 'Photo exceeds the ' . number_format($this->maxBytes / 1048576, 1) . ' MB limit.',
            ];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        if (!isset(self::ALLOWED[$mime])) {
            return ['ok' => false, 'error' => 'Only JPEG, PNG or WebP images are accepted.'];
        }

        // Second opinion from the image parser: rejects polyglot files that
        // carry a JPEG header but are not a decodable image.
        $info = @getimagesize($tmp);
        if ($info === false) {
            return ['ok' => false, 'error' => 'The file is not a valid image.'];
        }
        $real = image_type_to_mime_type($info[2]);
        if (!isset(self::ALLOWED[$real]) || $real !== $mime) {
            return ['ok' => false, 'error' => 'The file content does not match its image type.'];
        }

        if (!is_dir($this->baseDir) && !mkdir($this->baseDir, 0755, true) && !is_dir($this->baseDir)) {
            throw new RuntimeException('Cannot create the upload directory.');
        }

        $filename = bin2hex(random_bytes(16)) . self::ALLOWED[$mime];
        $destination = $this->baseDir . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($tmp, $destination)) {
            return ['ok' => false, 'error' => 'The photo could not be saved.'];
        }
        @chmod($destination, 0644);

        return ['ok' => true, 'filename' => $filename];
    }

    /** Remove a previously stored photo. Silently ignores missing files. */
    public function delete(?string $filename): void
    {
        if ($filename === null || $filename === '') {
            return;
        }
        $path = $this->baseDir . DIRECTORY_SEPARATOR . basename($filename);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
