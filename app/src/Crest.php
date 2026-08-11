<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Herb klubu: PNG albo SVG, do 2 MB, zapis do STORAGE_PATH.
 *
 * SVG TRAKTUJEMY JAKO TREŚĆ WROGĄ. To nie obrazek, tylko dokument XML, który
 * może nieść `<script>`, `onload=`, `<foreignObject>` i odwołania zewnętrzne.
 * Otwarty bezpośrednio w przeglądarce wykonuje się w naszym pochodzeniu —
 * czyli z sesją zalogowanego operatora.
 *
 * Stąd trzy warstwy:
 *   1. odrzucenie plików z konstrukcjami wykonywalnymi już przy wgrywaniu,
 *   2. serwowanie wyłącznie przez PHP, z `Content-Security-Policy: default-src 'none'`,
 *   3. osadzanie w panelu przez `<img src>`, nigdy przez wklejenie XML-a do strony
 *      — w `<img>` skrypty w SVG się nie wykonują.
 */
final class Crest
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    /** Konstrukcje, po których odrzucamy SVG bez dalszej dyskusji. */
    private const SVG_FORBIDDEN = [
        '<script', '<foreignobject', '<iframe', '<embed', '<object',
        'javascript:', '<!entity', '<!doctype svg system', 'xlink:href="http',
    ];

    /**
     * @param array<string,mixed>|null $file wpis z $_FILES
     * @return array{ok:bool, error?:string, path?:string}
     */
    public static function accept(?array $file): array
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true];   // herb jest opcjonalny
        }

        $err = (int) $file['error'];
        if ($err !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'club.err.crest_too_big'
                : 'import.err.transport'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'import.err.transport'];
        }

        if (filesize($tmp) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'club.err.crest_too_big'];
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'svg'], true)) {
            return ['ok' => false, 'error' => 'club.err.crest_type'];
        }

        $kind = self::sniff($tmp);
        if ($kind === null || $kind !== $ext) {
            // Rozszerzenie musi zgadzać się z ZAWARTOŚCIĄ. `logo.png` z XML-em
            // w środku zostałoby zserwowane jako obrazek i mogłoby się wykonać.
            return ['ok' => false, 'error' => 'club.err.crest_type'];
        }

        if ($kind === 'svg' && !self::svgLooksSafe($tmp)) {
            return ['ok' => false, 'error' => 'club.err.crest_svg'];
        }

        $target = self::dir() . '/' . Storage::randomName($ext);
        if (!move_uploaded_file($tmp, $target)) {
            error_log("crest: move_uploaded_file nie powiodło się -> {$target}");
            return ['ok' => false, 'error' => 'import.err.save'];
        }
        @chmod($target, 0640);

        return ['ok' => true, 'path' => $target];
    }

    /** Rozpoznanie po zawartości: `png` albo `svg`, null gdy ani jedno. */
    private static function sniff(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = (string) fread($handle, 1024);
        fclose($handle);

        if (str_starts_with($head, self::PNG_MAGIC)) {
            return 'png';
        }

        // SVG bywa poprzedzony deklaracją XML, BOM-em albo komentarzem.
        $probe = strtolower(ltrim($head, "\xEF\xBB\xBF \t\r\n"));
        if (str_contains($probe, '<svg')) {
            return 'svg';
        }
        return null;
    }

    /**
     * SVG bez treści wykonywalnej. Świadomie prosto i restrykcyjnie: szukamy
     * wzorców w całym pliku, a przy trafieniu odrzucamy. Fałszywe odrzucenie
     * herbu jest kosztem znośnym, wykonanie skryptu w sesji operatora nie jest.
     */
    private static function svgLooksSafe(string $path): bool
    {
        $content = strtolower((string) file_get_contents($path));

        foreach (self::SVG_FORBIDDEN as $needle) {
            if (str_contains($content, $needle)) {
                return false;
            }
        }
        // Atrybuty zdarzeń: onload, onclick, onmouseover, …
        return preg_match('/\son[a-z]+\s*=/', $content) !== 1;
    }

    public static function dir(): string
    {
        $dir = Storage::root() . '/crests';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException("Nie mogę utworzyć katalogu herbów: {$dir}");
        }
        return $dir;
    }

    /** Typ MIME do serwowania. Wyłącznie z zamkniętej listy. */
    public static function mime(string $path): string
    {
        return str_ends_with(strtolower($path), '.svg') ? 'image/svg+xml' : 'image/png';
    }
}
