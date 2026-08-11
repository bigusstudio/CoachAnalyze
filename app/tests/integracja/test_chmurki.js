/*
 * Chmurki powiadomień — wykonanie skryptu na atrapie DOM.
 *
 * `node --check` mówi tylko tyle, że plik się parsuje. Tutaj skrypt naprawdę
 * chodzi: dostaje odpowiedź punktu końcowego i buduje z niej elementy.
 * Sprawdzamy przede wszystkim to, czego nie widać na oko — że tytuł
 * powiadomienia trafia do drzewa jako TEKST, a nie jako kod.
 *
 * Uruchomienie:  node test_chmurki.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

let ok = 0;
let fail = 0;

function check(nazwa, warunek, szczegol) {
    if (warunek) {
        ok++;
        console.log('  OK   ' + nazwa);
    } else {
        fail++;
        console.log('  BŁĄD ' + nazwa + (szczegol ? ' — ' + szczegol : ''));
    }
}

/* ------------------------------------------------------------ atrapa DOM */

class El {
    constructor(tag) {
        this.tagName = tag;
        this.children = [];
        this.attrs = {};
        this.listeners = {};
        this._text = '';
        this.className = '';
        this.parentNode = null;
        this.classList = {
            add: (c) => { this.className += ' ' + c; },
        };
    }
    set textContent(v) { this._text = String(v); this.children = []; }
    get textContent() {
        return this._text + this.children.map((c) => c.textContent).join('');
    }
    setAttribute(k, v) { this.attrs[k] = String(v); }
    // W przegladarce `el.href = x` i `el.setAttribute('href', x)` daja ten sam
    // skutek. Atrapa musi to odwzorowac, inaczej test sprawdza samego siebie.
    set href(v) { this.attrs.href = String(v); }
    get href() { return this.attrs.href; }
    getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null; }
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; }
    removeChild(c) {
        const i = this.children.indexOf(c);
        if (i >= 0) { this.children.splice(i, 1); c.parentNode = null; }
    }
    addEventListener(n, f) { (this.listeners[n] = this.listeners[n] || []).push(f); }
    querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
    querySelectorAll(sel) {
        const wynik = [];
        const pasuje = (el) => {
            if (sel.startsWith('.')) { return (' ' + el.className + ' ').includes(' ' + sel.slice(1) + ' '); }
            return el.tagName === sel;
        };
        const chodz = (el) => {
            el.children.forEach((c) => { if (pasuje(c)) { wynik.push(c); } chodz(c); });
        };
        chodz(this);
        return wynik;
    }
    /** Odtworzenie HTML-a — wyłącznie po to, żeby sprawdzić, co trafiło do drzewa. */
    toHTML() {
        if (this.children.length === 0) {
            return '<' + this.tagName + '>' + this._text + '</' + this.tagName + '>';
        }
        return '<' + this.tagName + '>' + this.children.map((c) => c.toHTML()).join('')
            + '</' + this.tagName + '>';
    }
}

const obszar = new El('div');
obszar.setAttribute('data-csrf', 'token-testowy');
obszar.setAttribute('data-powrot', '/');
obszar.setAttribute('data-tekst-otworz', 'Otwórz');
obszar.setAttribute('data-tekst-zamknij', 'Zamknij powiadomienie');

const naglowek = new El('a');
naglowek.setAttribute('href', '/powiadomienia');

const zadania = [];
let odpowiedz = null;
const zadaneAdresy = [];

global.document = {
    hidden: false,
    getElementById: (id) => (id === 'chmurki' ? obszar : null),
    querySelector: (sel) => (sel === 'a[href="/powiadomienia"]' ? naglowek : null),
    createElement: (tag) => new El(tag),
    addEventListener: () => {},
};

global.window = {
    fetch: () => {},
    setTimeout: (f, ms) => { zadania.push({ f, ms }); return zadania.length; },
    clearTimeout: () => {},
};

global.fetch = (adres, opcje) => {
    zadaneAdresy.push({ adres, opcje });
    return Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve(odpowiedz),
    });
};

/* ------------------------------------------------------------ wykonanie */

// Sciezka wzgledem repozytorium: app/tests/integracja -> korzen.
const KORZEN = path.resolve(__dirname, '..', '..', '..');
const zrodlo = fs.readFileSync(
    path.join(KORZEN, 'app', 'public', 'assets', 'powiadomienia.js'),
    'utf8'
);

// Skrypt jest wyrażeniem natychmiastowym — wykonujemy go tak, jak zrobi to
// przeglądarka, bez żadnych przeróbek.
eval(zrodlo);

console.log('== skrypt wystartował ==');
check('zaplanował pierwsze odpytanie', zadania.length > 0, 'brak zaplanowanego zadania');

/* ------------------------------------------------------------ budowanie chmurki */

async function probuj() {
    console.log('\n== chmurka z odpowiedzi punktu końcowego ==');

    odpowiedz = {
        unread: 2,
        working: false,
        items: [
            { id: 1, kind: 'ready', title: 'Raport gotowy: Klub Ą — Klub Ż', url: '/raport/7', at: '2026-03-16 11:01' },
            { id: 2, kind: 'failed', title: 'Przetwarzanie nie powiodło się', url: null, at: '2026-03-16 11:02' },
        ],
    };

    // Wywołujemy zaplanowane odpytanie.
    zadania[0].f();
    await new Promise((r) => setImmediate(r));
    await new Promise((r) => setImmediate(r));

    const chmurki = obszar.querySelectorAll('chmurka');
    const dzieci = obszar.children;
    check('powstały dwie chmurki', dzieci.length === 2, 'jest ' + dzieci.length);

    const html = obszar.children.map((c) => c.toHTML()).join('');
    check('tytuł trafił do drzewa', html.includes('Raport gotowy: Klub Ą — Klub Ż'));
    check('odmiana „gotowe” w nazwie klasy',
        dzieci.some((c) => c.className.includes('chmurka--ready')));
    check('odmiana „błąd” w nazwie klasy',
        dzieci.some((c) => c.className.includes('chmurka--failed')));

    const link = dzieci[0].querySelector('a') || dzieci[1].querySelector('a');
    check('odnośnik prowadzi wprost do raportu',
        link !== null && link.href === '/raport/7',
        link ? link.href : 'brak odnośnika');
    check('powiadomienie bez adresu nie dostaje odnośnika',
        dzieci.filter((c) => c.querySelector('a') !== null).length === 1);

    console.log('\n== licznik w nagłówku ==');
    const licznik = naglowek.querySelector('.licznik');
    check('licznik dorysowany', licznik !== null);
    check('licznik pokazuje liczbę nieodczytanych',
        licznik !== null && licznik.textContent === '2',
        licznik ? licznik.textContent : 'brak');

    console.log('\n== tytuł ze znacznikiem NIE staje się kodem ==');
    odpowiedz = {
        unread: 1,
        working: false,
        items: [{
            id: 99,
            kind: 'ready',
            title: '<img src=x onerror="alert(1)">Klub Ą',
            url: '/raport/1',
            at: '2026-03-16 12:00',
        }],
    };
    zadania[0].f();
    await new Promise((r) => setImmediate(r));
    await new Promise((r) => setImmediate(r));

    const zlosliwa = obszar.children.find((c) => c.getAttribute('data-id') === '99');
    check('chmurka powstała', zlosliwa !== undefined);
    if (zlosliwa) {
        const tytul = zlosliwa.querySelector('p');
        check('znacznik został TEKSTEM, nie elementem',
            tytul !== null && tytul.children.length === 0
            && tytul.textContent.includes('<img src=x onerror='),
            'liczba elementów potomnych: ' + (tytul ? tytul.children.length : '?'));
    }

    console.log('\n== adres z powiadomienia ograniczony do własnych ścieżek ==');
    odpowiedz = {
        unread: 1,
        working: false,
        items: [
            { id: 101, kind: 'ready', title: 'Obcy host', url: '//zly.example.com/x', at: '' },
            { id: 102, kind: 'ready', title: 'Skrypt w adresie', url: 'javascript:alert(1)', at: '' },
            { id: 103, kind: 'ready', title: 'Poprawny', url: '/raport/5', at: '' },
        ],
    };
    zadania[0].f();
    await new Promise((r) => setImmediate(r));
    await new Promise((r) => setImmediate(r));

    const adres = (id) => {
        const el = obszar.children.find((c) => c.getAttribute('data-id') === String(id));
        const a = el ? el.querySelector('a') : null;
        return a ? a.href : null;
    };

    check('adres na obcy host odrzucony', adres(101) === '/', adres(101));
    check('adres „javascript:” odrzucony', adres(102) === '/', adres(102));
    check('poprawny adres przepuszczony', adres(103) === '/raport/5', adres(103));

    console.log('\n== nieznana odmiana nie trafia do nazwy klasy ==');
    odpowiedz = {
        unread: 1,
        working: false,
        items: [{ id: 200, kind: 'zupelnie-inna" onload="zle', title: 'X', url: null, at: '' }],
    };
    zadania[0].f();
    await new Promise((r) => setImmediate(r));
    await new Promise((r) => setImmediate(r));

    const dziwna = obszar.children.find((c) => c.getAttribute('data-id') === '200');
    check('nieznana odmiana sprowadzona do „w toku”',
        dziwna !== undefined && dziwna.className.includes('chmurka--pending')
        && !dziwna.className.includes('onload'),
        dziwna ? dziwna.className : 'brak');

    console.log('\n== zamknięcie wysyła token CSRF ==');
    zadaneAdresy.length = 0;
    const doZamkniecia = obszar.children[0];
    const przycisk = doZamkniecia.querySelector('button');
    check('chmurka ma przycisk zamknięcia', przycisk !== null);
    if (przycisk && przycisk.listeners.click) {
        przycisk.listeners.click[0]({ preventDefault: () => {} });
        await new Promise((r) => setImmediate(r));
        const zad = zadaneAdresy[0];
        check('poszło żądanie POST', zad && zad.opcje.method === 'POST');
        check('pod adres oznaczania odczytu',
            zad && zad.adres.includes('/odczytane'), zad ? zad.adres : 'brak');
        check('z ciasteczkami tej samej domeny',
            zad && zad.opcje.credentials === 'same-origin');
    }

    console.log('\n== karta w tle wstrzymuje odpytywanie ==');
    zadaneAdresy.length = 0;
    global.document.hidden = true;
    zadania[0].f();
    await new Promise((r) => setImmediate(r));
    check('w tle NIE poszło żadne żądanie', zadaneAdresy.length === 0,
        'poszło: ' + zadaneAdresy.length);
    global.document.hidden = false;

    console.log('\n=== OK: ' + ok + ', BŁĘDÓW: ' + fail + ' ===');
    process.exit(fail === 0 ? 0 : 1);
}

probuj();
