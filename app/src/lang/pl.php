<?php
declare(strict_types=1);

/**
 * Teksty interfejsu. Trzymamy je w jednym miejscu, nie w szablonach — na wypadek
 * wersji anglojęzycznej (CLAUDE.md §6).
 *
 * Terminologia klienta jest częścią produktu i NIE PODLEGA tłumaczeniu:
 * SBZ, III strefa, pressing, transformacja, bilans, fragmentator, odbiór, strata.
 */

return [
    'app.name'            => 'CoachAnalyze',
    'app.tagline'         => 'Analityka meczowa',

    // --- logowanie ---
    'login.title'         => 'Logowanie',
    'login.heading'       => 'Zaloguj się',
    'login.email'         => 'Adres e-mail',
    'login.password'      => 'Hasło',
    'login.submit'        => 'Zaloguj',
    'login.logout'        => 'Wyloguj',
    'login.logged_out'    => 'Wylogowano.',

    // Jeden komunikat dla złego hasła i nieistniejącego konta — rozróżnienie
    // pozwoliłoby sprawdzić, które adresy są zarejestrowane.
    'login.err.invalid_credentials' => 'Nieprawidłowy adres e-mail lub hasło.',
    'login.err.rate_limited'        => 'Zbyt wiele prób logowania. Spróbuj ponownie za %s.',
    'login.err.login_unavailable'   => 'Logowanie jest chwilowo niedostępne. Spróbuj ponownie za chwilę.',
    'login.err.csrf'                => 'Formularz stracił ważność. Spróbuj jeszcze raz.',
    'login.err.empty'               => 'Podaj adres e-mail i hasło.',

    // --- nawigacja ---
    'nav.dashboard'       => 'Pulpit',
    'nav.matches'         => 'Mecze',
    'nav.clubs'           => 'Kluby',
    'nav.notes'           => 'Notatki',
    'nav.soon_hint'       => 'Wkrótce',
    'nav.theme.to_dark'   => 'Włącz motyw ciemny',
    'nav.theme.to_light'  => 'Włącz motyw jasny',
    'nav.menu'            => 'Nawigacja',

    // --- pulpit ---
    'dash.title'          => 'Pulpit',
    'dash.all_seasons'    => 'wszystkie sezony',
    'dash.c.matches'      => 'Mecze',
    'dash.c.reports'      => 'Wygenerowane raporty',
    'dash.c.links'        => 'Aktywne linki',
    'dash.c.queued'       => 'Zadania w kolejce',
    'dash.recent'         => 'Ostatnie mecze',
    'dash.jobs'           => 'Zadania',
    'dash.jobs.hint'      => 'Uruchomione oraz nieudane z ostatniej doby',
    'dash.empty.matches'  => 'Brak meczów — wgraj pierwszy eksport z LiveTag.Pro.',
    'dash.empty.jobs'     => 'Brak zadań wymagających uwagi.',

    'match.date'          => 'Data',
    // NIE „Gospodarz"/„Gość". Eksport LiveTag nie niesie stron boiska, więc taka
    // kolumna kłamałaby przy każdym meczu wyjazdowym. Kolumny w bazie nazywają się
    // club_home_id/club_away_id ze względu na zgodność wstecz — patrz migracja 001.
    'match.us'            => 'Nasza drużyna',
    'match.them'          => 'Rywal',
    'match.status'        => 'Status',
    'match.action'        => 'Akcja',
    'match.no_date'       => 'bez daty',
    'match.no_teams'      => 'kluby nieprzypisane',
    'match.no_club'       => 'nieprzypisany',

    // --- zadania ---
    'job.title'           => 'Zadanie #%d',
    'job.type'            => 'Typ',
    'job.status'          => 'Status',
    'job.attempts'        => 'Liczba prób',
    'job.started'         => 'Start',
    'job.finished'        => 'Koniec',
    'job.exit_code'       => 'Kod wyjścia',
    'job.error'           => 'Treść błędu',
    'job.no_error'        => 'Bez zapisanego błędu.',
    'job.retry'           => 'Ponów',
    'job.retry.done'      => 'Zadanie wróciło do kolejki.',
    'job.retry.refused'   => 'Nie można ponowić zadania w tym stanie.',
    'job.report'          => 'Otwórz raport',
    'job.report.meta'     => 'Wygenerowano %s, silnik %s',
    'job.not_found'       => 'Nie znaleziono zadania.',
    'job.launch_failed'   => 'Nie udało się uruchomić silnika w tle. Zadanie czeka w kolejce.',
    'job.preview'         => 'Podgląd',
    'report.missing'      => 'Raport nie istnieje albo plik został usunięty ze schowka.',

    'status.draft'        => 'szkic',
    'status.queued'       => 'w kolejce',
    'status.running'      => 'w toku',
    'status.done'         => 'gotowe',
    'status.failed'       => 'błąd',

    // --- import eksportu (Etap 5) ---
    'import.title'        => 'Wgraj eksport z LiveTag.Pro',
    'import.csv'          => 'Plik CSV z tabelą zdarzeń',
    'import.csv.hint'     => 'Wymagany. Kolumny tag_name, begin, end.',
    'import.json'         => 'Plik projektu LiveTag (JSON)',
    'import.json.hint'    => 'Opcjonalny. Bez niego oś czasu użyje barw klubu zamiast palety tablicy kodowej.',
    'import.limit'        => 'Maksymalny rozmiar pojedynczego pliku: %d MB.',
    'import.submit'       => 'Wgraj i sprawdź pokrycie',
    'import.nav'          => 'Wgraj eksport',

    'import.err.required'    => 'Wybierz plik CSV z eksportem.',
    'import.err.too_big'     => 'Plik jest za duży.',
    'import.err.transport'   => 'Nie udało się odebrać pliku. Spróbuj ponownie.',
    'import.err.extension'   => 'Dozwolone są wyłącznie pliki .csv oraz .json.',
    'import.err.not_livetag' => 'Ten plik nie wygląda na eksport z LiveTag.Pro — brak kolumn tag_name, begin, end.',
    'import.err.not_project' => 'Plik JSON nie wygląda na projekt LiveTag.',
    'import.err.save'        => 'Nie udało się zapisać pliku na serwerze.',
    'import.err.storage'     => 'Katalog na pliki jest niedostępny do zapisu. Sprawdź STORAGE_PATH i uprawnienia — wgrywanie jest wyłączone.',
    'import.err.engine'      => 'Silnik nie zdołał odczytać pliku: %s',

    // --- raport pokrycia ---
    'coverage.title'      => 'Raport pokrycia',
    'coverage.lead'       => 'Tak silnik odczytał ten eksport. Sprawdź liczby przed wygenerowaniem raportu.',
    'coverage.numbers'    => 'Co znalazł silnik',
    'coverage.warnings'   => 'Ostrzeżenia',
    'coverage.no_warnings' => 'Bez ostrzeżeń.',
    'coverage.sections'   => 'Sekcje raportu',
    'coverage.all_sections' => 'Wszystkie sekcje są dostępne.',
    'coverage.available'  => 'Dostępne sekcje: %s',
    'coverage.count'      => '%d razy',
    'coverage.generate'   => 'Generuj raport',
    'coverage.regenerate' => 'Generuj ponownie',
    'coverage.queued'     => 'Raport trafił do kolejki. Strona odświeży się sama.',

    'cov.events'          => 'Zdarzenia',
    'cov.shots'           => 'Strzały',
    'cov.xg'              => 'Strzały z xG',
    'cov.sbz'             => 'Zdobycia SBZ',
    'cov.sbz_vector'      => 'Zdobycia SBZ z wektorem',
    'cov.third'           => 'Wejścia w III strefę',
    'cov.third_pos'       => 'III strefa ze współrzędnymi',
    'cov.duels'           => 'Pojedynki',
    'cov.no_team'         => 'Zdarzenia bez przypisanej drużyny',
    'cov.players'         => 'Zdarzenia z zawodnikiem',
    'cov.teams'           => 'Drużyny wykryte w danych',
    'cov.teams.none'      => 'żadnej',
    'cov.team.matched'  => 'dopasowany',
    'cov.team.create'   => 'Załóż klub z tą nazwą',
    'cov.json'            => 'Plik projektu LiveTag',

    // --- kluby (Etap 4b) ---
    'club.list'           => 'Kluby',
    'club.new'            => 'Nowy klub',
    'club.edit'           => 'Edytuj',
    'club.edit_title'     => 'Klub: %s',
    'club.empty'          => 'Brak klubów — dodaj pierwszy albo wgraj eksport, a nazwy podpowiedzą się z danych.',
    'club.name'           => 'Nazwa klubu',
    'club.short'          => 'Skrót',
    'club.short.hint'     => 'Trzy–cztery znaki, używane w ciasnych miejscach raportu.',
    'club.key'            => 'Klucz klubu',
    'club.key.hint'       => 'Stały i niezmienny — stoi w publicznych adresach raportów. Odwoływalny jest token, nie klucz.',
    'club.colors'         => 'Barwy',
    'club.color_primary'  => 'Barwa podstawowa',
    'club.color_secondary' => 'Barwa dodatkowa',
    'club.contrast'       => 'Popraw kontrast pod ciemne tło raportu',
    'club.contrast.hint'  => 'Korektę liczy silnik tą samą funkcją, którą przelicza paletę z pliku projektu.',
    'club.aliases'        => 'Nazwy w eksportach',
    'club.aliases.hint'   => 'Jedna na linię. Po dopasowaniu kolejne importy rozpoznają klub same.',
    'club.aliases_short'  => 'W eksportach: %s',
    'club.own_team'       => 'To mój klub',
    'club.own'            => 'mój klub',
    'club.matches'        => 'Mecze',
    'club.crest'          => 'Herb',
    'club.crest.hint'     => 'PNG albo SVG, do 2 MB.',
    'club.create'         => 'Utwórz klub',
    'club.save'           => 'Zapisz',
    'club.saved'          => 'Zapisano klub.',
    'club.saved_no_contrast' => 'Zapisano klub, ale silnik nie odpowiedział — barwy zostały bez korekty kontrastu.',
    'club.delete'         => 'Usuń klub',
    'club.delete.yes'     => 'Tak, usuń',
    'club.delete.confirm' => 'Usunąć klub „%s”?',
    'club.delete.hint'    => 'Herb zostanie skasowany z dysku. Operacji nie da się cofnąć.',
    'club.deleted'        => 'Klub usunięty.',
    'club.suggested'      => 'Nazwa „%s” pochodzi z eksportu. Popraw ją, jeśli klub nazywa się inaczej.',
    'club.not_found'      => 'Nie znaleziono klubu.',
    'club.err.name'       => 'Podaj nazwę klubu.',
    'club.err.in_use'     => 'Nie można usunąć klubu przypisanego do meczu.',
    'club.err.crest_too_big' => 'Herb jest za duży — limit to 2 MB.',
    'club.err.crest_type' => 'Herb musi być plikiem PNG albo SVG, a zawartość musi zgadzać się z rozszerzeniem.',
    'club.err.crest_svg'  => 'Ten plik SVG zawiera treść wykonywalną (skrypt, zdarzenie albo odwołanie zewnętrzne) i został odrzucony.',

    // --- strona zapowiedzi ---
    'soon.title'          => 'Wkrótce',
    'soon.body'           => 'Ta część panelu powstaje w kolejnym etapie prac.',

    // --- ogólne ---
    'common.error'        => 'Coś poszło nie tak.',
    'common.not_found'    => 'Nie znaleziono strony.',
    'common.back'         => 'Wróć',
    'common.unknown'      => 'nieznana',
    'common.yes'          => 'jest',
    'common.no'           => 'brak',
    'common.engine'       => 'Silnik %s',
    'common.dash'         => '—',
    'time.minutes'        => '%d min',
    'time.seconds'        => '%d s',
];
