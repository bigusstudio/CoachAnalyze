<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Przyjęcie eksportu z LiveTag.Pro: CSV wymagany, JSON projektu opcjonalny.
 *
 * Kolejność sprawdzeń jest celowa — od najtańszych do najdroższych, i żadne
 * z nich nie ufa temu, co przysłała przeglądarka:
 *   1. błąd transportu (UPLOAD_ERR_*)
 *   2. rozmiar
 *   3. rozszerzenie z białej listy
 *   4. ZAWARTOŚĆ nagłówka pliku
 *
 * Punkt 4 jest tym, który naprawdę broni: `Content-Type` ustawia klient, a
 * rozszerzenie to trzy znaki w nazwie. Dopiero zajrzenie do pliku rozstrzyga,
 * czy to eksport LiveTag, czy plik wykonywalny z podmienioną nazwą.
 */
final class Upload
{
    /** Rozszerzenia, które w ogóle rozważamy. */
    private const ALLOWED = ['csv' => 'csv', 'json' => 'json'];

    public static function maxBytes(): int
    {
        return Config::int('UPLOAD_MAX_MB', 20) * 1024 * 1024;
    }

    /**
     * @param array<string,mixed>|null $file wpis z $_FILES
     * @return array{ok:bool, error?:string, path?:string, name?:string, sha256?:string}
     */
    public static function accept(?array $file, string $kind, bool $required): array
    {
        $missing = $file === null
            || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE;

        if ($missing) {
            return $required
                ? ['ok' => false, 'error' => 'import.err.required']
                : ['ok' => true];
        }

        $err = (int) $file['error'];
        if ($err !== UPLOAD_ERR_OK) {
            // INI_SIZE/FORM_SIZE to osobny komunikat: „za duży" jest czymś innym
            // niż „coś się zepsuło po drodze" i użytkownik ma to rozróżnić.
            return ['ok' => false, 'error' => in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'import.err.too_big'
                : 'import.err.transport'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            // Bez tego sprawdzenia spreparowane $_FILES wskazałoby dowolny plik
            // na serwerze, a my byśmy go grzecznie skopiowali do storage.
            return ['ok' => false, 'error' => 'import.err.transport'];
        }

        if (filesize($tmp) > self::maxBytes()) {
            return ['ok' => false, 'error' => 'import.err.too_big'];
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ((self::ALLOWED[$ext] ?? null) !== $kind) {
            return ['ok' => false, 'error' => 'import.err.extension'];
        }

        if (!self::looksLike($tmp, $kind)) {
            return ['ok' => false, 'error' => $kind === 'csv'
                ? 'import.err.not_livetag'
                : 'import.err.not_project'];
        }

        $target = Storage::uploadDir() . '/' . Storage::randomName($ext);
        if (!move_uploaded_file($tmp, $target)) {
            error_log("upload: move_uploaded_file nie powiodło się -> {$target}");
            return ['ok' => false, 'error' => 'import.err.save'];
        }
        @chmod($target, 0640);

        return [
            'ok'     => true,
            'path'   => $target,
            'name'   => (string) ($file['name'] ?? ''),
            'sha256' => (string) hash_file('sha256', $target),
        ];
    }

    /**
     * Zaglądamy do pliku. Dla CSV wymagamy kolumn, na których stoi parser
     * (REQUIRED_COLUMNS w silniku); dla JSON — żeby dał się sparsować i miał
     * kształt projektu LiveTag.
     */
    private static function looksLike(string $path, string $kind): bool
    {
        if ($kind === 'csv') {
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                return false;
            }
            $firstLine = (string) fgets($handle, 65536);
            fclose($handle);

            // BOM z Excela nie jest błędem — parser silnika go zdejmuje (utf-8-sig).
            $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? $firstLine;
            $columns = array_map('trim', str_getcsv(rtrim($firstLine, "\r\n"), ',', '"', '\\'));

            // Te trzy kolumny to REQUIRED_COLUMNS parsera. Brak którejkolwiek
            // znaczy, że silnik i tak odrzuci plik — lepiej powiedzieć to teraz.
            foreach (['tag_name', 'begin', 'end'] as $column) {
                if (!in_array($column, $columns, true)) {
                    return false;
                }
            }
            return true;
        }

        // JSON musi się sparsować w CAŁOŚCI — po fragmencie nie da się orzec,
        // czy plik jest poprawny. Rozmiar jest już ograniczony wyżej.
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) && $decoded !== [];
    }
}
