// Atrapa DOM dla CAŁEGO skryptu raportu v21 (W7). Uruchamia skrypty strony
// na zdarzeniach z pliku HTML i wypisuje JSON {id elementu: tekst bez znaczników}
// dla nagłówka, Przeglądu i warstwy 1. Bez zależności (CLAUDE.md §3a, §9).
// Użycie: node atrapa_dom_raportu.js RAPORT.html
const fs = require('fs');
const html = fs.readFileSync(process.argv[2], 'utf8');
const out = {};
function el(id) {
  const t = { id, dataset: {}, style: {}, children: [],
    classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } } };
  return new Proxy(t, {
    get(o, k) {
      if (k in o) return o[k];
      if (k === 'innerHTML') return out[id] || '';
      if (k === 'textContent') return '';
      if (k === 'querySelector') return s => el(String(s).replace(/^#/, ''));
      if (k === 'querySelectorAll') return () => [];
      if (k === 'getAttribute') return () => '';
      return () => el(id + '.' + String(k));
    },
    set(o, k, v) { if (k === 'innerHTML') out[id] = v; else o[k] = v; return true; },
  });
}
global.document = {
  documentElement: el('html'), body: el('body'),
  getElementById: id => (html.includes('id="' + id + '"') ? el(id) : null),
  querySelector: () => null, querySelectorAll: () => [],
  createElement: t => el('new-' + t), createTextNode: () => ({}), addEventListener() {},
};
global.window = global;
global.localStorage = { getItem() { return null; }, setItem() {} };
global.matchMedia = () => ({ matches: false });
global.addEventListener = () => {};
const skrypty = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1]);
(0, eval)(skrypty.slice(0, 2).join('\n;\n') + ';renderOverview();');
const tekst = h => (h || '').replace(/<svg[\s\S]*?<\/svg>/g, ' ').replace(/<[^>]+>/g, ' ')
  .replace(/\s+/g, ' ').trim();
console.log(JSON.stringify({ hdr2: tekst(out.hdr2), kpi: tekst(out['ov-kpi']), plik: tekst(out['plik-box']) }));
