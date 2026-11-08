// The typed-date parser of web/js/dates.js (node, no browser): dd/mm/yy and dd/mm/yyyy, ISO, invalid dates refused.
const fs = require('fs'), path = require('path'), vm = require('vm');
const src = fs.readFileSync(path.join(__dirname, '../../web/js/dates.js'), 'utf8');
const win = {flatpickr: function () {}};
const ctx = {window: win, flatpickr: win.flatpickr, document: {querySelectorAll: () => [], body: {}}, MutationObserver: function () { this.observe = () => {}; }, Date};
vm.createContext(ctx); vm.runInContext(src, ctx);
const P = win.MSDates.parse, iso = d => d ? d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0') : null;
let fail = 0;
const eq = (got, want, l) => { if (got !== want) { fail++; console.log('FAIL  ' + l + ': ' + got + ' != ' + want); } };
eq(iso(P('08/11/2026')), '2026-11-08', 'dd/mm/yyyy');
eq(iso(P('8/3/26')), '2026-03-08', 'd/m/yy');
eq(iso(P('01.02.1975')), '1975-02-01', 'dots');
eq(iso(P('01-02-75')), '1975-02-01', 'two-digit year far ahead is 19yy');
eq(iso(P('2026-02-28')), '2026-02-28', 'ISO');
eq(iso(P('31/02/2026')), null, 'no 31 February');
eq(iso(P('29/02/2024')), '2024-02-29', 'leap day');
eq(iso(P('13/13/2026')), null, 'month 13');
eq(iso(P('hello')), null, 'text');
eq(iso(P('')), null, 'empty');
if (fail) { console.log(fail + ' date test(s) failed'); process.exit(1); }
