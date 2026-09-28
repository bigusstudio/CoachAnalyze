/*
 * Ekran WGRAJ — blok walidacji z powiadomienia.js uruchomiony na atrapie DOM
 * (golden layout W5, pkt 5).
 *
 * Sprawdza to, czego nie widać bez przeglądarki: że po wybraniu plików
 * powstają PASTYLKI z nazwami (jako tekst, nie HTML), a zły typ pliku jest
 * zgłaszany komunikatem z `data-*` i blokuje wysłanie formularza.
 *
 * Uruchamiany jest CAŁY plik skryptu, nie wycinek — wyjątek w którymkolwiek
 * wcześniejszym bloku zatrzymałby ten na produkcji i test ma to złapać.
 *
 * Uruchomienie:  node test_wgraj.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

let ok = 0;
let fail = 0;
function check(nazwa, warunek, szczegol) {
    if (warunek) { ok++; console.log('  OK   ' + nazwa); }
    else { fail++; console.log('  BŁĄD ' + nazwa + (szczegol ? ' — ' + szczegol : '')); }
}

class El {
    constructor(tag, attrs) {
        this.tagName = tag.toUpperCase();
        this.attrs = Object.assign({}, attrs || {});
        this.children = [];
        this.listeners = {};
        this._text = '';
        this.className = this.attrs.class || '';
        this.hidden = 'hidden' in this.attrs;
        this.parentNode = null;
        const el = this;
        this.classList = {
            add: (c) => { if (!el.classList.contains(c)) { el.className = (el.className + ' ' + c).trim(); } },
            remove: (c) => { el.className = el.className.split(/\s+/).filter((x) => x && x !== c).join(' '); },
            contains: (c) => el.className.split(/\s+/).includes(c),
            toggle: (c, on) => { if (on) { el.classList.add(c); } else { el.classList.remove(c); } },
        };
    }
    set textContent(v) { this._text = String(v); this.children = []; }
    get textContent() { return this._text + this.children.map((c) => c.textContent).join(''); }
    get firstChild() { return this.children[0] || null; }
    getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null; }
    setAttribute(k, v) { this.attrs[k] = String(v); }
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; }
    removeChild(c) { this.children.splice(this.children.indexOf(c), 1); c.parentNode = null; return c; }
    addEventListener(n, f) { (this.listeners[n] = this.listeners[n] || []).push(f); }
    fire(n, ev) { (this.listeners[n] || []).forEach((f) => f(ev || {})); }
    all() { return this.children.reduce((a, c) => a.concat([c], c.all()), []); }
    querySelectorAll(sel) {
        const m = sel.match(/^(\w+)?(?:\[type="(\w+)"\])?(?:\[([\w-]+)\])?$/);
        return this.all().filter((el) => (!m[1] || el.tagName === m[1].toUpperCase())
            && (!m[2] || el.attrs.type === m[2]) && (!m[3] || m[3] in el.attrs));
    }
    querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
}

/* Formularz jak w `import_form.php`. Teksty = prawdziwe `data-*` z pl.php nie są tu
   potrzebne — sprawdzamy, że skrypt bierze komunikat Z ATRYBUTU. */
const form = new El('form', {
    'data-wgraj': '', 'data-limit': '1000',
    'data-blad-typu': 'BLAD-TYPU', 'data-blad-dwa-csv': 'BLAD-DWA-CSV',
    'data-blad-dwa-json': 'BLAD-DWA-JSON', 'data-blad-brak-csv': 'BLAD-BRAK-CSV',
    'data-blad-rozmiar': 'BLAD-ROZMIAR',
    'data-blad-naglowek-csv': 'BLAD-NAGLOWEK-CSV', 'data-blad-naglowek-json': 'BLAD-NAGLOWEK-JSON',
});
const strefa = form.appendChild(new El('label', { 'data-strefa': '', class: 'upuszczenie' }));
const pole = strefa.appendChild(new El('input', { type: 'file', 'data-pliki': '', multiple: '' }));
const lista = strefa.appendChild(new El('ul', { 'data-pastylki': '', hidden: '' }));
const komunikat = form.appendChild(new El('p', { 'data-komunikat': '', hidden: '' }));

const plik = (name, size, tresc) => ({ name, size, tresc,
    slice() { return { tresc: tresc }; } });

global.window = {
    fetch: null,
    FileReader: function () {
        const r = this;
        r.readAsText = (kawalek) => { r.result = kawalek.tresc; r.onload(); };
    },
};
global.document = {
    getElementById: () => null,
    querySelectorAll: (sel) => (sel === 'form[data-wgraj]' ? [form] : []),
    querySelector: () => null,
    createElement: (tag) => new El(tag),
    addEventListener: () => {},
};

const kod = fs.readFileSync(path.join(__dirname, '../../public/assets/powiadomienia.js'), 'utf8');
let wyjatek = null;
try { new Function(kod)(); } catch (e) { wyjatek = e; }
check('cały skrypt wykonuje się bez wyjątku (blok Wgraj nie jest zatrzymany)', wyjatek === null, String(wyjatek));
check('blok Wgraj podpiął się pod pole plików', (pole.listeners.change || []).length === 1);

const CSV = 'tag_name,begin,end,team\nSTRZAŁ,1,2,A';

pole.files = [plik('mecz.csv', 100, CSV), plik('projekt.json', 50, '{"a":1}')];
pole.fire('change');
const pastylki = lista.children;
check('pastylki: po jednej na plik', pastylki.length === 2);
check('pastylki niosą nazwy plików jako tekst', pastylki.map((p) => p.textContent).join('|') === 'mecz.csv|projekt.json');
check('pastylki mają odmianę typu', pastylki[0].className.includes('pastylka--csv') && pastylki[1].className.includes('pastylka--json'));
check('lista pastylek odsłonięta', lista.hidden === false);
check('poprawne pliki: bez komunikatu', komunikat.hidden === true && komunikat.textContent === '');

pole.files = [plik('mecz.csv', 100, CSV), plik('notatki.txt', 10, 'x')];
pole.fire('change');
check('zły typ (.txt): komunikat z data-blad-typu', komunikat.textContent === 'BLAD-TYPU' && komunikat.hidden === false);
check('zły typ: strefa oznaczona', strefa.classList.contains('upuszczenie--zle'));
let zablokowano = false;
form.fire('submit', { preventDefault: () => { zablokowano = true; } });
check('zły typ blokuje wysłanie przed wysyłką plików', zablokowano);

pole.files = [plik('a.csv', 100, CSV), plik('b.csv', 100, CSV)];
pole.fire('change');
check('dwa CSV: komunikat z data-blad-dwa-csv', komunikat.textContent === 'BLAD-DWA-CSV');

pole.files = [plik('projekt.json', 10, '{}')];
pole.fire('change');
check('sam JSON bez CSV: komunikat z data-blad-brak-csv', komunikat.textContent === 'BLAD-BRAK-CSV');

pole.files = [plik('eksport.csv', 100, 'kolumna,inna\n1,2')];
pole.fire('change');
check('CSV bez kolumn LiveTag: komunikat z nagłówka', komunikat.textContent === 'BLAD-NAGLOWEK-CSV');

pole.files = [plik('mecz.csv', 5000, CSV)];
pole.fire('change');
check('za duży plik: komunikat z data-blad-rozmiar', komunikat.textContent === 'BLAD-ROZMIAR');

const nazwaZKodem = '<img src=x onerror=alert(1)>.csv';
pole.files = [plik(nazwaZKodem, 10, CSV)];
pole.fire('change');
check('nazwa pliku z kodem zostaje tekstem', lista.children[0].textContent === nazwaZKodem
    && lista.children[0].children.length === 0);

console.log('\n=== OK: ' + ok + ', BŁĘDÓW: ' + fail + ' ===');
process.exit(fail === 0 ? 0 : 1);
