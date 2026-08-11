<?php
declare(strict_types=1);

/**
 * Zakłada konto operatora. W wersji BAZA jest jedno konto (README, Etap 3).
 *
 * Użycie:
 *   php app/bin/create_user.php jan@example.com "Jan Kowalski" [rola]
 *
 * Hasła NIE podajemy w argumencie — trafiłoby do historii powłoki i do listy
 * procesów. Skrypt pyta o nie interaktywnie, z wyłączonym echem.
 */

use CoachAnalyze\Audit;
use CoachAnalyze\Auth;
use CoachAnalyze\Db;

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$email = $argv[1] ?? null;
$name  = $argv[2] ?? null;
$role  = $argv[3] ?? 'operator';

if ($email === null || $name === null) {
    fwrite(STDERR, "Użycie: php app/bin/create_user.php <email> <nazwa> [admin|operator|viewer]\n");
    exit(2);
}

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Niepoprawny adres e-mail: {$email}\n");
    exit(2);
}

if (!in_array($role, ['admin', 'operator', 'viewer'], true)) {
    fwrite(STDERR, "Nieznana rola: {$role}\n");
    exit(2);
}

$password = prompt_password('Hasło: ');
$minLength = Auth::minPasswordLength();
// Liczymy ZNAKI, nie bajty: „ćma" ma 3 znaki i 6 bajtów, a `strlen` liczyłby bajty
// i przepuszczał krótsze hasła z polskimi literami niż z samych ASCII.
if (mb_strlen($password, 'UTF-8') < $minLength) {
    fwrite(STDERR, "Hasło musi mieć co najmniej {$minLength} znaków.\n");
    exit(2);
}
if ($password !== prompt_password('Powtórz hasło: ')) {
    fwrite(STDERR, "Hasła się różnią.\n");
    exit(2);
}

$existing = Db::one('SELECT id FROM users WHERE email = :email', ['email' => $email]);
if ($existing !== null) {
    Db::run('UPDATE users SET pass_hash = :hash, display_name = :name, role = :role WHERE id = :id', [
        'hash' => Auth::hashPassword($password),
        'name' => $name,
        'role' => $role,
        'id'   => $existing['id'],
    ]);
    // Reset hasła z konsoli też musi odciąć zapamiętane urządzenia.
    \CoachAnalyze\Remember::forgetAll((int) $existing['id'], 'reset hasła z konsoli');
    Audit::log('user.password_reset', null, 'user', (int) $existing['id']);
    echo "Zaktualizowano konto {$email} (id {$existing['id']}).\n";
    exit(0);
}

Db::run(
    'INSERT INTO users (email, pass_hash, display_name, role) VALUES (:email, :hash, :name, :role)',
    [
        'email' => $email,
        'hash'  => Auth::hashPassword($password),
        'name'  => $name,
        'role'  => $role,
    ]
);
$id = (int) Db::pdo()->lastInsertId();
Audit::log('user.created', null, 'user', $id, ['role' => $role]);
echo "Utworzono konto {$email} (id {$id}, rola {$role}).\n";

/** Odczyt hasła bez wyświetlania go na ekranie. */
function prompt_password(string $label): string
{
    fwrite(STDOUT, $label);
    // `stty` bywa niedostępne (np. w potoku) — wtedy czytamy jawnie, ale mówimy o tym.
    $hasStty = shell_exec('command -v stty 2>/dev/null') !== null;
    if ($hasStty) {
        shell_exec('stty -echo 2>/dev/null');
    } else {
        fwrite(STDOUT, "\n[uwaga] brak `stty` — hasło będzie widoczne na ekranie\n");
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($hasStty) {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    }
    return $value;
}
