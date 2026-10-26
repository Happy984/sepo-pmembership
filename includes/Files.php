<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/**
 * Private files: proofs of payment and member downloads.
 *
 * Files live in a folder under uploads that the web server is told not to
 * serve, with random names. They are only handed out by PHP after a check:
 * proofs to the people who run the membership and to the payer; downloads to
 * members whose plan covers them.
 */
final class Files
{
    public const DIR = 'sepo-membership-private';

    /** @return array<string,string> */
    public static function proofMimes(): array
    {
        return (array) apply_filters('sepo_membership_proof_mimes', [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
            'pdf'          => 'application/pdf',
            'doc'          => 'application/msword',
            'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'odt'          => 'application/vnd.oasis.opendocument.text',
        ]);
    }

    /** What may be uploaded as a member download: WordPress's own list of allowed types. @return array<string,string> */
    public static function resourceMimes(): array
    {
        return (array) apply_filters('sepo_membership_resource_mimes', get_allowed_mime_types());
    }

    /** @param array<string,string> $mimes */
    public static function label(array $mimes): string
    {
        $out = [];
        foreach (array_keys($mimes) as $p) {
            $out[] = strtoupper(explode('|', (string) $p)[0]);
        }
        return implode(', ', array_slice(array_unique($out), 0, 12));
    }

    public static function maxMb(): int
    {
        $server = max(1, (int) floor(wp_max_upload_size() / 1048576));
        return max(1, min($server, (int) (Settings::get('proof_max_mb') ?: 5)));
    }

    public static function baseDir(): string
    {
        $u = wp_upload_dir(null, false);
        return trailingslashit($u['basedir']) . self::DIR;
    }

    public static function protect(): void
    {
        $dir = self::baseDir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $files = [
            '.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'index.php'  => "<?php\n// Silence is golden.\n",
            'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
        ];
        foreach ($files as $name => $body) {
            if (!is_file($dir . '/' . $name)) {
                @file_put_contents($dir . '/' . $name, $body);
            }
        }
    }

    /**
     * Uploaded files under one field as a flat list.
     *
     * @param array<string,mixed> $field
     * @return list<array{name:string,tmp_name:string,error:int,size:int}>
     */
    public static function fromUpload(array $field): array
    {
        if (!isset($field['name'])) {
            return [];
        }
        if (!is_array($field['name'])) {
            $field = ['name' => [$field['name']], 'tmp_name' => [$field['tmp_name']], 'error' => [$field['error']], 'size' => [$field['size']]];
        }
        $out = [];
        foreach ($field['name'] as $i => $name) {
            $out[] = ['name' => (string) $name, 'tmp_name' => (string) ($field['tmp_name'][$i] ?? ''), 'error' => (int) ($field['error'][$i] ?? UPLOAD_ERR_NO_FILE), 'size' => (int) ($field['size'][$i] ?? 0)];
        }
        return array_values(array_filter($out, static fn(array $f): bool => $f['error'] !== UPLOAD_ERR_NO_FILE && $f['name'] !== ''));
    }

    /**
     * Check every file, then store them all under $folder. Nothing is kept
     * unless every file passes.
     *
     * @param list<array{name:string,tmp_name:string,error:int,size:int}> $files
     * @param array<string,string> $mimes
     * @return list<array{file:string,name:string,type:string,size:int,at:int}>
     */
    public static function store(string $folder, array $files, array $mimes, int $maxMb, int $maxFiles = 5): array
    {
        if ($files === []) {
            return [];
        }
        if (count($files) > $maxFiles) {
            /* translators: %d: number of files */
            throw new \InvalidArgumentException(sprintf(__('Attach at most %d files.', 'sepo-membership'), $maxFiles));
        }
        $checked = [];
        foreach ($files as $f) {
            $name = sanitize_file_name($f['name']);
            if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > $maxMb * 1048576) {
                /* translators: 1: file name, 2: size in MB */
                throw new \InvalidArgumentException(sprintf(__('"%1$s" is larger than %2$d MB.', 'sepo-membership'), $name, $maxMb));
            }
            if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                /* translators: %s: file name */
                throw new \InvalidArgumentException(sprintf(__('"%s" did not upload. Please try again.', 'sepo-membership'), $name));
            }
            $type = wp_check_filetype_and_ext($f['tmp_name'], $f['name'], $mimes);
            if (empty($type['ext']) || empty($type['type'])) {
                /* translators: 1: file name, 2: list of file types */
                throw new \InvalidArgumentException(sprintf(__('"%1$s" is not an accepted file type. Use %2$s.', 'sepo-membership'), $name, self::label($mimes)));
            }
            $checked[] = $f + ['ext' => (string) $type['ext'], 'type' => (string) $type['type'], 'clean' => $name];
        }
        self::protect();
        $dir = self::baseDir() . '/' . sanitize_file_name($folder);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $stored = [];
        foreach ($checked as $f) {
            $file = bin2hex(random_bytes(12)) . '.' . $f['ext'];
            if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $file)) {
                self::remove($folder, $stored);
                throw new \RuntimeException(__('A file could not be saved. Please try again.', 'sepo-membership'));
            }
            @chmod($dir . '/' . $file, 0640);
            $stored[] = ['file' => $file, 'name' => $f['clean'], 'type' => $f['type'], 'size' => (int) $f['size'], 'at' => time()];
        }
        return $stored;
    }

    public static function path(string $folder, string $file): string
    {
        return self::baseDir() . '/' . sanitize_file_name($folder) . '/' . basename($file);
    }

    /** @param list<array<string,mixed>> $files */
    public static function remove(string $folder, array $files): void
    {
        foreach ($files as $f) {
            $p = self::path($folder, (string) ($f['file'] ?? ''));
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    /**
     * Send one stored file to the browser and stop.
     *
     * @param array<string,mixed> $f
     */
    public static function send(string $folder, array $f, bool $inlineAllowed = true): void
    {
        self::sendPath(self::path($folder, (string) ($f['file'] ?? '')), (string) ($f['type'] ?? ''), (string) ($f['name'] ?? 'file'), $inlineAllowed);
    }

    /** Send a file from the media library through the same checked route, and stop. */
    public static function sendAttachment(int $attachmentId): void
    {
        $path = (string) get_attached_file($attachmentId);
        $uploads = wp_upload_dir(null, false);
        $base = realpath((string) $uploads['basedir']);
        $real = $path !== '' ? realpath($path) : false;
        // Only files inside the uploads folder are handed out.
        if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            wp_die(esc_html__('The file is no longer available.', 'sepo-membership'), '', ['response' => 404]);
        }
        self::sendPath($real, (string) get_post_mime_type($attachmentId), wp_basename($real), false);
    }

    private static function sendPath(string $path, string $type, string $name, bool $inlineAllowed): void
    {
        if (!is_file($path)) {
            wp_die(esc_html__('The file is no longer available.', 'sepo-membership'), '', ['response' => 404]);
        }
        $type = preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $type) ? $type : 'application/octet-stream';
        $inline = $inlineAllowed && in_array($type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'], true);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Type: ' . $type);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        if (str_starts_with($type, 'image/')) {
            header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
        }
        readfile($path);
        exit;
    }
}
