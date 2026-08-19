/*
 * Chmurki powiadomień — jedyny skrypt w całym panelu.
 *
 * ODSTĘPSTWO OD ZASADY „ZERO SKRYPTÓW", zatwierdzone i ograniczone do
 * powiadomień. Raport pozostaje samowystarczalnym HTML-em bez ani jednego
 * skryptu — tamta zasada obowiązuje dalej (CLAUDE.md §8).
 *
 * TEN SKRYPT TYLKO PRZYSPIESZA. Chmurki są renderowane przez serwer
 * (app/src/Views/layout.php) i przy wyłączonym JavaScripcie pokazują się przy
 * przeładowaniu strony, a licznik w nagłówku działa jak dotąd. Gdy skrypt się
 * nie wczyta, nic nie przestaje działać — po prostu trzeba odświeżyć stronę.
 *
 * BEZ ZALEŻNOŚCI ZEWNĘTRZNYCH, jeden plik, ładowany z `defer` na końcu.
 */
(function () {
    'use strict';

    var obszar = document.getElementById('chmurki');
    if (!obszar || !window.fetch) {
        // Brak obszaru (strona logowania) albo bardzo stara przeglądarka.
        // Serwerowe chmurki i tak są na miejscu — nie ma czego naprawiać.
        return;
    }

    var PUNKT = '/powiadomienia/nowe';

    /* Odstępy odpytywania, w milisekundach.
     *
     * ODPYTYWANIE JEST KOSZTOWNE I TRZEBA JE MIARKOWAĆ. Stałe 5 s na otwartej
     * karcie to ponad 17 tysięcy żądań na dobę — z czego zdecydowana większość
     * odpowiada „nic nowego". Dlatego trzy niezależne hamulce:
     *   1. karta w tle  -> odpytywanie WSTRZYMANE (Page Visibility)
     *   2. nic się nie dzieje -> odstęp rośnie do minuty
     *   3. coś jest w toku -> odstęp wraca do 5 s
     */
    var ODSTEP_MIN = 5000;
    var ODSTEP_MAX = 60000;
    var MNOZNIK = 1.5;

    /* Po tylu milisekundach chmurka znika sama.
     *
     * Zniknięcie NIE oznacza jej jako odczytanej — to robi dopiero zamknięcie.
     * Powiadomienie, którego ktoś nie zdążył przeczytać, ma zostać w liczniku
     * i na liście, a nie wyparować, bo akurat patrzył w inną stronę.
     */
    var ZYCIE_CHMURKI = 15000;

    var odstep = ODSTEP_MIN;
    var timer = null;
    var wTrakcie = false;

    /* Identyfikatory już pokazane w tej karcie.
     *
     * Bez tego chmurka zniknięta samoczynnie (wciąż nieodczytana) wracałaby
     * przy każdym odpytaniu i mrugała w kółko.
     */
    var pokazane = {};

    // Chmurki wyrenderowane przez serwer są już na ekranie — zapisujemy je,
    // żeby skrypt nie dorysował ich drugi raz.
    Array.prototype.forEach.call(
        obszar.querySelectorAll('.chmurka'),
        function (el) {
            var id = el.getAttribute('data-id');
            if (id) {
                pokazane[id] = true;
                podepnijZamkniecie(el, id);
                zaplanujZniknienie(el);
            }
        }
    );

    /* ------------------------------------------------------------ budowanie */

    /*
     * Chmurka budowana z ELEMENTÓW I `textContent`, nigdy przez `innerHTML`.
     *
     * Tytuł powiadomienia zawiera nazwy klubów, czyli tekst wpisany przez
     * użytkownika. Wstawiony jako HTML byłby kodem do wykonania; wstawiony jako
     * `textContent` jest i pozostaje tekstem. Serwer nie przysyła tu HTML-a
     * w ogóle — punkt końcowy zwraca wyłącznie dane.
     */
    function zbudujChmurke(dane) {
        var box = document.createElement('div');
        box.className = 'chmurka chmurka--' + rodzaj(dane.kind);
        box.setAttribute('data-id', String(dane.id));

        var tresc = document.createElement('div');
        tresc.className = 'chmurka__tresc';

        var tytul = document.createElement('p');
        tytul.className = 'chmurka__tytul';
        tytul.textContent = dane.title;
        tresc.appendChild(tytul);

        if (dane.url) {
            var link = document.createElement('a');
            link.className = 'chmurka__link';
            // Adres tylko własny, zaczynający się od „/”. Bez tego wystarczyłby
            // wpis w bazie, żeby chmurka prowadziła na obcą stronę.
            link.href = bezpiecznyAdres(dane.url);
            link.textContent = obszar.getAttribute('data-tekst-otworz') || 'Otwórz';
            tresc.appendChild(link);
        }

        box.appendChild(tresc);

        var zamknij = document.createElement('button');
        zamknij.type = 'button';
        zamknij.className = 'chmurka__zamknij';
        zamknij.setAttribute('aria-label', obszar.getAttribute('data-tekst-zamknij') || 'Zamknij');
        zamknij.textContent = '×';
        box.appendChild(zamknij);

        podepnijZamkniecie(box, String(dane.id));
        return box;
    }

    /** Tylko znane odmiany trafiają do nazwy klasy CSS. */
    function rodzaj(wartosc) {
        return (wartosc === 'ready' || wartosc === 'failed') ? wartosc : 'pending';
    }

    /** Wyłącznie ścieżki własne. Odrzuca „//obcy.host” i „javascript:”. */
    function bezpiecznyAdres(adres) {
        if (typeof adres !== 'string') { return '/'; }
        if (adres.charAt(0) !== '/' || adres.charAt(1) === '/') { return '/'; }
        return adres;
    }

    /* ------------------------------------------------------------ zamykanie */

    function podepnijZamkniecie(el, id) {
        var przycisk = el.querySelector('button');
        var form = el.querySelector('form');

        if (!przycisk) { return; }

        przycisk.addEventListener('click', function (zdarzenie) {
            // Przejmujemy wysłanie formularza: bez skryptu poszłoby zwykłym
            // POST-em z przeładowaniem strony, co jest poprawne, ale zbędne.
            zdarzenie.preventDefault();
            oznaczOdczytane(id, el);
        });

        if (form) {
            form.addEventListener('submit', function (zdarzenie) {
                zdarzenie.preventDefault();
                oznaczOdczytane(id, el);
            });
        }
    }

    function oznaczOdczytane(id, el) {
        var dane = new FormData();
        dane.append('csrf', obszar.getAttribute('data-csrf') || '');
        dane.append('powrot', obszar.getAttribute('data-powrot') || '/');

        fetch('/powiadomienia/' + encodeURIComponent(id) + '/odczytane', {
            method: 'POST',
            body: dane,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (odp) {
            return odp.ok ? odp.json() : null;
        }).then(function (wynik) {
            if (wynik && typeof wynik.unread === 'number') {
                ustawLicznik(wynik.unread);
            }
        }).catch(function () {
            // Brak sieci nie może zablokować interfejsu. Chmurka i tak znika,
            // a stan „odczytane” zostanie ustawiony przy następnym wejściu
            // na listę powiadomień.
        });

        usun(el);
    }

    function usun(el) {
        if (!el || !el.parentNode) { return; }
        el.classList.add('chmurka--znika');
        // Czekamy na koniec przejścia, ale nie polegamy na nim: przy
        // `prefers-reduced-motion` animacji nie ma i zdarzenie nie przyjdzie.
        window.setTimeout(function () {
            if (el.parentNode) { el.parentNode.removeChild(el); }
        }, 300);
    }

    function zaplanujZniknienie(el) {
        window.setTimeout(function () { usun(el); }, ZYCIE_CHMURKI);
    }

    /* ------------------------------------------------------------ licznik */

    function ustawLicznik(ile) {
        var odnosnik = document.querySelector('a[href="/powiadomienia"]');
        if (!odnosnik) { return; }

        var licznik = odnosnik.querySelector('.licznik');

        if (ile <= 0) {
            if (licznik) { licznik.parentNode.removeChild(licznik); }
            return;
        }

        if (!licznik) {
            licznik = document.createElement('span');
            licznik.className = 'licznik';
            odnosnik.appendChild(licznik);
        }
        licznik.textContent = ile > 99 ? '99+' : String(ile);
        licznik.setAttribute('aria-label', 'nieodczytanych: ' + ile);
    }

    /* ------------------------------------------------------------ odpytywanie */

    function odpytaj() {
        if (wTrakcie || document.hidden) { return; }
        wTrakcie = true;

        fetch(PUNKT, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (odp) {
            // 404 = brak sesji. Kończymy odpytywanie zamiast wracać co 5 s pod
            // trasę, która nas nie zna.
            if (odp.status === 404) { zatrzymaj(); return null; }
            return odp.ok ? odp.json() : null;
        }).then(function (dane) {
            wTrakcie = false;
            if (!dane) { return; }

            ustawLicznik(dane.unread || 0);

            var nowe = 0;
            var items = dane.items || [];
            // Od najstarszego, żeby najnowsza chmurka wylądowała na wierzchu.
            for (var i = items.length - 1; i >= 0; i--) {
                var el = items[i];
                if (pokazane[el.id]) { continue; }
                pokazane[el.id] = true;
                nowe++;
                var box = zbudujChmurke(el);
                obszar.appendChild(box);
                zaplanujZniknienie(box);
            }

            // Odstęp: krótki gdy coś się dzieje, rosnący gdy cisza.
            if (nowe > 0 || dane.working) {
                odstep = ODSTEP_MIN;
            } else {
                odstep = Math.min(ODSTEP_MAX, Math.round(odstep * MNOZNIK));
            }
        }).catch(function () {
            wTrakcie = false;
            // Awaria sieci też wydłuża odstęp — dokładanie żądań do serwera,
            // który nie odpowiada, nie pomaga ani nam, ani jemu.
            odstep = Math.min(ODSTEP_MAX, Math.round(odstep * MNOZNIK));
        });
    }

    function zaplanuj() {
        zatrzymaj();
        timer = window.setTimeout(function () {
            odpytaj();
            zaplanuj();
        }, odstep);
    }

    function zatrzymaj() {
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
    }

    /*
     * KARTA W TLE = ZERO ŻĄDAŃ.
     *
     * To jest ten hamulec, który zamienia „kilkanaście tysięcy żądań dziennie
     * z zapomnianej karty” w zero. Po powrocie odpytujemy od razu, żeby
     * użytkownik nie czekał na cykl, i wracamy do krótkiego odstępu.
     */
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            zatrzymaj();
        } else {
            odstep = ODSTEP_MIN;
            odpytaj();
            zaplanuj();
        }
    });

    zaplanuj();
})();

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * WSKAŹNIK PRACY KOLEJKI — drugi blok TEGO SAMEGO pliku.
 *
 * Nadal jeden skrypt w całym panelu (CLAUDE.md §9). Osobne wyrażenie
 * natychmiastowe, a nie dopisek do bloku wyżej, bo te dwie rzeczy są od siebie
 * niezależne: chmurki są na każdym ekranie panelu, wskaźnik tylko tam, gdzie
 * się czeka. Awaria jednego nie ma prawa wyłączyć drugiego.
 *
 * TEN BLOK TYLKO PRZYSPIESZA. Etapy, czas i komunikat błędu renderuje serwer
 * (app/src/Views/wskaznik.php), a bez skryptu odświeża je `<meta refresh>`
 * z layoutu. Skrypt podmienia to na odpytywanie bez przeładowania.
 *
 * ANI JEDNEGO POLSKIEGO ZDANIA W KODZIE. Wszystkie teksty są już w HTML-u
 * albo przychodzą z punktu końcowego; skrypt przełącza klasy, odsłania gotowe
 * akapity i wstawia liczby.
 * ═══════════════════════════════════════════════════════════════════════════
 */
(function () {
    'use strict';

    // Atrapa DOM w testach nie ma `querySelectorAll`, a bardzo stara
    // przeglądarka nie ma `fetch`. W obu przypadkach zostaje wariant serwerowy,
    // który jest kompletny — nie ma czego ratować.
    if (!window.fetch || typeof document.querySelectorAll !== 'function') {
        return;
    }

    var wskazniki = document.querySelectorAll('[data-zadanie]');
    var partie = document.querySelectorAll('[data-partia]');

    if (wskazniki.length === 0 && partie.length === 0) {
        return;
    }

    /* Odpytywanie co 4 s — w widełkach 3–5 s z zadania.
     *
     * Tutaj NIE MA rosnącego odstępu jak przy chmurkach i to jest różnica
     * zamierzona: chmurki chodzą na każdym ekranie godzinami, a wskaźnik żyje
     * tylko dopóki zadanie trwa i sam się wyłącza, gdy jest po wszystkim.
     */
    var ODSTEP = 4000;

    /* Ile czekać z przejściem do wyniku po „Gotowe".
     *
     * Zero byłoby skokiem w połowie zdania — operator nie zdążyłby zobaczyć,
     * że się udało, i nie wiedziałby, czemu nagle jest gdzie indziej.
     */
    var ZWLOKA_PRZEJSCIA = 1200;

    /* Po tylu sekundach w kolejce odsłaniamy „trwa dłużej niż zwykle".
     * Ta sama wartość co `Jobs::PROG_WOLNO` po stronie serwera — serwer
     * przysyła gotowe `slow`, a to jest wyłącznie zapas na tykanie lokalne
     * między jednym odpytaniem a drugim. */
    var PROG_WOLNO = 180;

    /** Wyłącznie ścieżki własne. Odrzuca „//obcy.host” i „javascript:”. */
    function bezpiecznyAdres(adres) {
        if (typeof adres !== 'string') { return null; }
        if (adres.charAt(0) !== '/' || adres.charAt(1) === '/') { return null; }
        return adres;
    }

    /*
     * ODWOŁANIE `<meta http-equiv="refresh">`.
     *
     * Layout wstawia je dla wariantu bez skryptu. Gdyby zostało, przeładowanie
     * co kilka–kilkanaście sekund przerywałoby odpytywanie w połowie i kasowało
     * odliczany czas — dwa mechanizmy robiące to samo, przeszkadzające sobie.
     *
     * Usunięcie węzła anuluje zaplanowane przeładowanie. Gdyby jakaś
     * przeglądarka tego nie uszanowała, najgorsze, co się stanie, to
     * przeładowanie strony — czyli dokładnie zachowanie bez skryptu.
     */
    function odwolajPrzeladowanie() {
        var meta = document.querySelector('meta[http-equiv="refresh"]');
        if (meta && meta.parentNode) {
            meta.parentNode.removeChild(meta);
        }
    }

    /** Sekundy jako „m:ss”. Liczba, nie zdanie — etykieta stoi w HTML-u. */
    function mmss(sekundy) {
        var m = Math.floor(sekundy / 60);
        var s = sekundy % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function pokaz(el, czy) {
        if (!el) { return; }
        if (czy) { el.removeAttribute('hidden'); } else { el.setAttribute('hidden', 'hidden'); }
    }

    /* ------------------------------------------------ pojedyncze zadanie */

    function uruchomWskaznik(root) {
        var punkt = bezpiecznyAdres(root.getAttribute('data-zadanie-punkt'));
        if (!punkt) { return; }

        var sekundy = parseInt(root.getAttribute('data-zadanie-czas'), 10);
        if (isNaN(sekundy) || sekundy < 0) { sekundy = 0; }

        var auto = root.getAttribute('data-zadanie-auto') === '1';
        var wynikUrl = bezpiecznyAdres(root.getAttribute('data-zadanie-wynik'));

        var poleCzasu = root.querySelector('[data-rola="czas"]');
        var poleUwagi = root.querySelector('[data-rola="uwaga"]');

        var etap = 'queued';
        var zakonczone = false;
        var wTrakcie = false;

        // Etapy odczytujemy z DRZEWA, nie z tablicy w skrypcie: kolejność
        // i zestaw ustala serwer i to on jest jedynym źródłem prawdy.
        var pozycje = root.querySelectorAll('[data-etap]');

        function ustawEtap(nowy) {
            etap = nowy;
            root.className = 'wskaznik wskaznik--' + nowy;

            var trafiony = false;
            Array.prototype.forEach.call(pozycje, function (li) {
                var klucz = li.getAttribute('data-etap');
                li.className = 'wskaznik__etap';
                if (klucz === nowy) {
                    trafiony = true;
                    li.className += ' is-teraz';
                } else if (!trafiony) {
                    li.className += ' is-za-nami';
                }
            });
        }

        function tyknij() {
            if (!zakonczone) { sekundy++; }
            if (poleCzasu) { poleCzasu.textContent = mmss(sekundy); }
            // Zapas na wypadek, gdyby próg minął między jednym odpytaniem
            // a drugim — serwer i tak przyśle `slow` przy najbliższym.
            if (etap === 'queued' && sekundy > PROG_WOLNO) { pokaz(poleUwagi, true); }
            if (!zakonczone) { window.setTimeout(tyknij, 1000); }
        }

        /*
         * STAN KOŃCOWY ODDAJEMY SERWEROWI.
         *
         * Skrypt nie buduje ani komunikatu błędu, ani przycisku „Ponów":
         * renderuje je `wskaznik.php`, tak samo jak przy wyłączonym skrypcie.
         * Tutaj zostaje sama decyzja DOKĄD — do wyniku albo z powrotem na tę
         * stronę, żeby serwer dorysował stan końcowy.
         *
         * Zwłoka jest po to, żeby „Gotowe" dało się zobaczyć. Natychmiastowy
         * skok wyglądałby jak przypadkowe przeniesienie w inne miejsce panelu.
         */
        function skonczono(dane) {
            zakonczone = true;
            pokaz(poleUwagi, false);

            if (!window.location) { return; }

            var cel = dane.stage === 'done'
                ? (bezpiecznyAdres(dane.result_url) || wynikUrl)
                : null;

            window.setTimeout(function () {
                if (auto && cel) {
                    window.location.href = cel;
                } else if (typeof window.location.reload === 'function') {
                    window.location.reload();
                }
            }, ZWLOKA_PRZEJSCIA);
        }

        function odpytaj() {
            if (wTrakcie || zakonczone || document.hidden) { return; }
            wTrakcie = true;

            fetch(punkt, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (odp) {
                // 404 = brak sesji albo zadanie zniknęło. Przestajemy pytać
                // zamiast wracać co cztery sekundy pod adres, pod którym nic nie ma.
                if (odp.status === 404) { zakonczone = true; return null; }
                return odp.ok ? odp.json() : null;
            }).then(function (dane) {
                wTrakcie = false;
                if (!dane || !dane.stage) { return; }

                if (typeof dane.elapsed === 'number' && dane.elapsed >= 0) {
                    sekundy = dane.elapsed;
                    if (poleCzasu) { poleCzasu.textContent = mmss(sekundy); }
                }

                ustawEtap(dane.stage);
                pokaz(poleUwagi, dane.slow === true);

                if (dane.stage === 'done' || dane.stage === 'failed') {
                    skonczono(dane);
                }
            }).catch(function () {
                // Awaria sieci nie kończy wskaźnika: kolejna próba za chwilę,
                // a serwerowy stan i tak jest już na ekranie.
                wTrakcie = false;
            });
        }

        function petla() {
            if (zakonczone) { return; }
            odpytaj();
            window.setTimeout(petla, ODSTEP);
        }

        var startowy = root.className.indexOf('wskaznik--') >= 0
            ? root.className.split('wskaznik--')[1].split(' ')[0]
            : 'queued';
        etap = startowy;

        if (startowy === 'done' || startowy === 'failed') {
            // Zadanie było już po wszystkim, gdy serwer renderował stronę —
            // nie ma czego odpytywać ani czego odliczać.
            return;
        }

        odwolajPrzeladowanie();
        tyknij();
        petla();
    }

    /* ------------------------------------------------ partia (X z N) */

    function uruchomPartie(root) {
        var punkt = bezpiecznyAdres(root.getAttribute('data-partia-punkt'));
        if (!punkt) { return; }

        var poleGotowych = root.querySelector('[data-rola="gotowe"]');
        var poleNieudanych = root.querySelector('[data-rola="nieudane"]');

        var wTrakcie = false;
        var zakonczone = false;
        var bylyBledy = parseInt(root.getAttribute('data-partia-bledy'), 10);
        if (isNaN(bylyBledy)) { bylyBledy = 0; }

        function odpytaj() {
            if (wTrakcie || zakonczone || document.hidden) { return; }
            wTrakcie = true;

            fetch(punkt, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (odp) {
                if (odp.status === 404) { zakonczone = true; return null; }
                return odp.ok ? odp.json() : null;
            }).then(function (dane) {
                wTrakcie = false;
                if (!dane) { return; }

                if (poleGotowych) { poleGotowych.textContent = String(dane.done); }
                if (poleNieudanych) { poleNieudanych.textContent = String(dane.failed); }

                /*
                 * PRZEŁADOWANIE, A NIE BUDOWANIE TABELI W SKRYPCIE.
                 *
                 * Listę błędów per mecz renderuje serwer i to on zna nazwy
                 * meczów, odsyłacze i stan raportów. Druga implementacja tej
                 * tabeli w JavaScripcie byłaby drugim miejscem, w którym te
                 * dane mogą się rozjechać — a przeładowanie i tak jest tym,
                 * co robi wariant bez skryptu.
                 *
                 * Robimy je, gdy partia się domknie ALBO gdy przybyło błędów:
                 * nowy błąd ma być widoczny od razu, z powodem i nazwą meczu.
                 */
                if (dane.finished || dane.failed > bylyBledy) {
                    zakonczone = true;
                    if (window.location && typeof window.location.reload === 'function') {
                        window.location.reload();
                    }
                }
            }).catch(function () {
                wTrakcie = false;
            });
        }

        function petla() {
            if (zakonczone) { return; }
            odpytaj();
            window.setTimeout(petla, ODSTEP);
        }

        if (root.getAttribute('data-partia-trwa') !== '1') {
            return;   // Partia zamknięta — nie ma czego odpytywać.
        }

        odwolajPrzeladowanie();
        petla();
    }

    Array.prototype.forEach.call(wskazniki, uruchomWskaznik);
    Array.prototype.forEach.call(partie, uruchomPartie);
})();
