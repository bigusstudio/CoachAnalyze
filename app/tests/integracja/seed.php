<?php
declare(strict_types=1);
/** Baza testowa: SQLite o kształcie migracji 001–013 + dane przykładowe. */

use CoachAnalyze\Auth;
use CoachAnalyze\Db;
use CoachAnalyze\Stats;

function ca_test_db(string $file, bool $withData = true): PDO
{
    $fresh = !is_file($file);
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Db::setPdo($pdo);
    if (!$fresh) {
        return $pdo;
    }

    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE,
        pass_hash TEXT, display_name TEXT, role TEXT DEFAULT "operator",
        notify_mail_pending INT DEFAULT 1, notify_mail_ready INT DEFAULT 1,
        notify_mail_failed INT DEFAULT 1,
        status TEXT NOT NULL DEFAULT "active", must_change_password INT NOT NULL DEFAULT 0,
        created_by INT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, last_login_at TEXT NULL)');
    $pdo->exec('CREATE TABLE clubs (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_id INT,
        club_key TEXT, name TEXT, short_name TEXT, color_primary TEXT, color_secondary TEXT,
        crest_path TEXT, is_own_team INT DEFAULT 0, aliases_json TEXT,
        details TEXT NULL, updated_at TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE seasons (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_id INT,
        label TEXT, date_from TEXT, date_to TEXT, is_current INT DEFAULT 0)');
    /*
     * `club_id NOT NULL` — TAK SAMO JAK NA PRODUKCJI po migracji 012.
     *
     * Kuszące byłoby zostawić tu NULL-owalną kolumnę, żeby stare wpisy testowe
     * przechodziły bez zmian. Byłby to dokładnie ten sam błąd co przy powtórzonym
     * symbolu nazwanym: schemat testowy łagodniejszy od produkcyjnego przepuszcza
     * kod, który wywala się dopiero u klienta. Wpisy testowe podają klub.
     */
    $pdo->exec('CREATE TABLE matches (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_id INT,
        club_id INT NOT NULL, season_id INT NULL, club_home_id INT NULL, club_away_id INT NULL,
        played_at TEXT NULL, is_home INT NULL,
        -- Migracja 013: wynik w DWOCH kolumnach, nie w napisie „3:1".
        -- NULL znaczy „nie wiemy" i tak zostaje dla calej historii.
        score_us INT NULL, score_them INT NULL,
        competition TEXT NULL, half_split_ms INT NULL, status TEXT DEFAULT "draft",
        created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE imports (id INTEGER PRIMARY KEY AUTOINCREMENT, match_id INT,
        csv_path TEXT, json_path TEXT NULL, checksum_csv TEXT, format_fingerprint TEXT NULL,
        coverage_json TEXT NULL, warnings_json TEXT NULL, engine_version TEXT NULL,
        sections_json TEXT NULL, mapping_profile_id INT NULL,
        -- Migracja 013: znacznik „operator widzial ekran nowych tagow".
        diff_done_at TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INT NOT NULL, type TEXT NOT NULL, title TEXT NOT NULL, body TEXT NULL,
        entity TEXT NULL, entity_id INT NULL, url TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, read_at TEXT NULL,
        mail_status TEXT NOT NULL DEFAULT "none", mail_to TEXT NULL,
        mail_attempts INT NOT NULL DEFAULT 0, mail_error TEXT NULL,
        mail_sent_at TEXT NULL, send_after TEXT NULL)');
    $pdo->exec('CREATE TABLE mapping_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT,
        club_id INT NOT NULL, version INT NOT NULL DEFAULT 1, rules_json TEXT NOT NULL,
        source TEXT NOT NULL DEFAULT "default", created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL, note TEXT NULL, UNIQUE (club_id, version))');
    $pdo->exec('CREATE TABLE concept_requests (id INTEGER PRIMARY KEY AUTOINCREMENT,
        club_id INT NULL, import_id INT NULL, tag TEXT NOT NULL, sample_labels_json TEXT NULL,
        rationale TEXT NULL, requested_by INT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        status TEXT NOT NULL DEFAULT "new", resolution TEXT NULL,
        resolved_by INT NULL, resolved_at TEXT NULL)');
    $pdo->exec('CREATE TABLE reports (id INTEGER PRIMARY KEY AUTOINCREMENT, match_id INT,
        club_id INT NULL, template_version INT NULL,
        html_path TEXT, params_json TEXT, engine_version TEXT,
        generated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    // Migracja 012 — templaty raportów klubu i tagi ignorowane na stałe.
    $pdo->exec('CREATE TABLE club_report_templates (id INTEGER PRIMARY KEY AUTOINCREMENT,
        club_id INT NOT NULL, version INT NOT NULL, config TEXT NOT NULL,
        created_by INT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (club_id, version))');
    $pdo->exec('CREATE TABLE club_ignored_tags (id INTEGER PRIMARY KEY AUTOINCREMENT,
        club_id INT NOT NULL, source_type TEXT NOT NULL, raw_name TEXT NOT NULL,
        created_by INT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (club_id, source_type, raw_name))');
    $pdo->exec('CREATE TABLE share_links (id INTEGER PRIMARY KEY AUTOINCREMENT, report_id INT,
        club_id INT, token TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, expires_at TEXT NULL,
        revoked_at TEXT NULL, views INT DEFAULT 0, last_viewed_at TEXT NULL)');
    $pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT,
        payload_json TEXT, status TEXT DEFAULT "queued", attempts INT DEFAULT 0,
        exit_code INT NULL, error_text TEXT NULL, available_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        started_at TEXT NULL, finished_at TEXT NULL)');
    $pdo->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_id INT,
        scope TEXT, match_id INT NULL, club_id INT NULL, event_ref TEXT NULL, title TEXT NULL,
        body TEXT, tags_json TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NULL)');
    $pdo->exec('CREATE TABLE remember_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT,
        token_hash TEXT UNIQUE, expires_at TEXT, created_at TEXT, last_used_at TEXT NULL,
        user_agent_hash TEXT NULL, ip_hash TEXT NULL, device_label TEXT NULL)');
    $pdo->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT NULL,
        action TEXT, entity TEXT NULL, entity_id INT NULL, meta_json TEXT NULL, ip BLOB NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT NOT NULL,
        token_hash TEXT UNIQUE NOT NULL, expires_at TEXT NOT NULL, used_at TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE xg_manual_shots (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT NOT NULL,
        match_id INT NULL, x REAL NOT NULL, y REAL NOT NULL,
        body_part TEXT NOT NULL DEFAULT "foot", situation TEXT NOT NULL DEFAULT "open",
        xg REAL NOT NULL, note TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE index_terms (id INTEGER PRIMARY KEY AUTOINCREMENT, club_id INT NOT NULL,
        slug TEXT NOT NULL, concept TEXT NOT NULL, name TEXT NOT NULL, definition TEXT NOT NULL,
        formula TEXT NULL, example TEXT NULL, interpretation TEXT NULL, source TEXT NULL,
        estimated_note TEXT NULL, version INT NOT NULL DEFAULT 1, created_by INT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE (club_id, slug, version))');

    Db::run('INSERT INTO users (email, pass_hash, display_name, role) VALUES (?,?,?,?)',
        ['operator@example.com', Auth::hashPassword('bardzo-dlugie-haslo-testowe'), 'Operator', 'operator']);

    if (!$withData) {
        return $pdo;
    }

    Db::run('INSERT INTO seasons (owner_id,label,date_from,date_to,is_current) VALUES (1,?,?,?,1)',
        ['2026/2027', '2026-07-01', '2027-06-30']);
    Db::run('INSERT INTO clubs (owner_id,club_key,name,is_own_team) VALUES (1,?,?,1)',
        ['HUT7K2QX', 'Klub A']);
    Db::run('INSERT INTO clubs (owner_id,club_key,name) VALUES (1,?,?)',
        ['POG4M8ZR', 'Klub B']);
    /*
     * Sesja 2 (przebudowa pod kluby): DRUGI TENANT (id 4) + drugi rywal (id 3),
     * bez meczów przypisanych do żadnego z nich. Bez tego `Clubs::tenants()`
     * i podział „lista klubów w nawigacji = tylko tenanci" byłyby przetestowane
     * tylko na jednym klubie — nierozróżnialne od zwykłego `Clubs::all()`.
     */
    Db::run('INSERT INTO clubs (owner_id,club_key,name,is_own_team) VALUES (1,?,?,0)',
        ['RIV5K2NX', 'Rywal C']);
    Db::run('INSERT INTO clubs (owner_id,club_key,name,is_own_team) VALUES (1,?,?,1)',
        ['STL9R4WQ', 'Klub D']);

    $mecze = [
        ['2026-08-09', 1, 2, 'done'],
        ['2026-08-02', 2, 1, 'done'],
        ['2026-07-26', 1, 2, 'failed'],
        ['2026-07-19', 1, null, 'queued'],   // klub nieprzypisany
        [null,         null, null, 'draft'], // mecz bez daty i klubów
        ['2026-07-05', 1, 2, 'done'],        // szósty — nie powinien wejść do „ostatnich 5"
    ];
    /*
     * `club_id` wypełniane tą samą regułą, co backfill w migracji 012:
     * tenant = strona „nasza", a gdy jej nie ma — klub domyślny (tu: 1).
     * Dzięki temu inwariant `club_id = club_home_id` obowiązuje także w danych
     * testowych i mecz bez przypisanego klubu (czwarty i piąty) nadal ma
     * właściciela, tak jak będzie go miał na produkcji.
     */
    foreach ($mecze as [$d, $h, $a, $st]) {
        Db::run('INSERT INTO matches (owner_id,club_id,season_id,club_home_id,club_away_id,played_at,status)
                 VALUES (1,?,1,?,?,?,?)', [$h ?? 1, $h, $a, $d, $st]);
    }

    Db::run('INSERT INTO reports (match_id,club_id,html_path,engine_version,generated_at) VALUES (1,1,?,?,?)',
        ['/nie/istnieje/raport_1.html', '0.6.0', '2026-08-09 21:14:00']);
    Db::run('INSERT INTO reports (match_id,club_id,html_path,engine_version,generated_at) VALUES (2,2,?,?,?)',
        ['/nie/istnieje/raport_2.html', '0.6.0', '2026-08-02 20:02:00']);

    $teraz = Stats::now();
    $jutro = Stats::now('+30 days');
    $wczoraj = Stats::now('-2 days');
    Db::run('INSERT INTO share_links (report_id,club_id,token,expires_at) VALUES (1,1,?,?)',
        [str_repeat('a', 32), $jutro]);                       // aktywny
    Db::run('INSERT INTO share_links (report_id,club_id,token,expires_at) VALUES (2,1,?,?)',
        [str_repeat('b', 32), $wczoraj]);                     // wygasły
    Db::run('INSERT INTO share_links (report_id,club_id,token,revoked_at) VALUES (2,1,?,?)',
        [str_repeat('c', 32), $teraz]);                       // odwołany

    Db::run('INSERT INTO jobs (type,payload_json,status,attempts,created_at) VALUES (?,?,?,?,?)',
        ['build_report', '{"match_id":4}', 'queued', 0, Stats::now('-10 minutes')]);
    Db::run('INSERT INTO jobs (type,payload_json,status,attempts,created_at,started_at)
             VALUES (?,?,?,?,?,?)',
        ['build_report', '{"match_id":3}', 'running', 1, Stats::now('-5 minutes'), Stats::now('-4 minutes')]);
    Db::run('INSERT INTO jobs (type,payload_json,status,attempts,exit_code,error_text,created_at,started_at,finished_at)
             VALUES (?,?,?,?,?,?,?,?,?)',
        ['build_report', '{"match_id":3}', 'failed', 3, 4,
         "Traceback (most recent call last):\n  File \"cli.py\", line 12\nNotImplementedError: render <script>alert(1)</script>",
         Stats::now('-2 hours'), Stats::now('-2 hours'), Stats::now('-2 hours')]);
    Db::run('INSERT INTO jobs (type,payload_json,status,attempts,exit_code,created_at,finished_at)
             VALUES (?,?,?,?,?,?,?)',
        ['build_report', '{"match_id":1}', 'done', 1, 0, Stats::now('-3 days'), Stats::now('-3 days')]);
    Db::run('INSERT INTO jobs (type,payload_json,status,attempts,exit_code,error_text,created_at)
             VALUES (?,?,?,?,?,?,?)',
        ['build_report', '{"match_id":2}', 'failed', 2, 4, 'stary błąd', Stats::now('-5 days')]);

    return $pdo;
}
