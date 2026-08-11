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
