#!/usr/bin/env python3
"""
Génère les fichiers de traduction (.po + .mo) FR et DE des plugins / thème ISPAG.

Usage :  python3 tools/i18n/build.py <dossier_du_plugin> [<autre_dossier> ...]
         python3 tools/i18n/build.py --check <dossier> ...      (aucune écriture : liste les textes sans traduction)

 - extract.py repère les textes __() / _e() / esc_html__() / _x() / _n() … de chaque dossier (par domaine de texte) ;
 - translations.json est la table de référence (FR et DE de chaque texte) ;
 - pour chaque domaine utilisé, <dossier>/languages/<domaine>-fr_FR.po|mo et <domaine>-de_DE.po|mo sont écrits.
Un texte absent de translations.json est listé (à traduire) et laissé vide : WordPress affiche alors l'anglais.
Pas besoin de gettext : le .mo est compilé ici.
"""
import json, os, struct, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from extract import extract

HERE = os.path.dirname(os.path.abspath(__file__))
PLURALS = {'fr': 'nplurals=2; plural=(n > 1);', 'de': 'nplurals=2; plural=(n != 1);'}
LOCALES = {'fr': 'fr_FR', 'de': 'de_DE'}

def esc(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\t', '\\t')

def po_text(domain, lang, entries, project):
    h = ['msgid ""', 'msgstr ""',
         f'"Project-Id-Version: {project}\\n"',
         '"Report-Msgid-Bugs-To: \\n"',
         '"MIME-Version: 1.0\\n"',
         '"Content-Type: text/plain; charset=UTF-8\\n"',
         '"Content-Transfer-Encoding: 8bit\\n"',
         f'"Language: {LOCALES[lang]}\\n"',
         f'"Plural-Forms: {PLURALS[lang]}\\n"',
         f'"X-Domain: {domain}\\n"', '']
    out = ['\n'.join(h)]
    for e in entries:
        lines = [f'#: {e["ref"]}']
        if e['ctx']: lines.append(f'msgctxt "{esc(e["ctx"])}"')
        lines.append(f'msgid "{esc(e["msgid"])}"')
        if e['plural']:
            lines.append(f'msgid_plural "{esc(e["plural"])}"')
            for i, t in enumerate(e[lang]): lines.append(f'msgstr[{i}] "{esc(t)}"')
        else:
            lines.append(f'msgstr "{esc(e[lang])}"')
        out.append('\n'.join(lines) + '\n')
    return '\n'.join(out)

def mo_bytes(lang, entries):
    msgs = {'': ('Project-Id-Version: ISPAG\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n'
                 f'Language: {LOCALES[lang]}\nPlural-Forms: {PLURALS[lang]}\n')}
    for e in entries:
        if not (e[lang] if not e['plural'] else all(e[lang])): continue
        key = (e['ctx'] + '\x04' if e['ctx'] else '') + e['msgid']
        if e['plural']:
            key += '\x00' + e['plural']; val = '\x00'.join(e[lang])
        else:
            val = e[lang]
        msgs[key] = val
    keys = sorted(msgs)
    ids = b''; strs = b''; offs = []
    for k in keys:
        kb, vb = k.encode('utf-8'), msgs[k].encode('utf-8')
        offs.append((len(ids), len(kb), len(strs), len(vb)))
        ids += kb + b'\x00'; strs += vb + b'\x00'
    n = len(keys); keystart = 7 * 4 + 16 * n; valuestart = keystart + len(ids)
    koffsets, voffsets = [], []
    for o1, l1, o2, l2 in offs:
        koffsets += [l1, o1 + keystart]; voffsets += [l2, o2 + valuestart]
    out = struct.pack('Iiiiiii', 0x950412de, 0, n, 7 * 4, 7 * 4 + n * 8, 0, 0)
    out += struct.pack('%di' % len(koffsets + voffsets), *(koffsets + voffsets))
    return out + ids + strs

def main():
    args = sys.argv[1:]
    check = '--check' in args
    dirs = [a for a in args if not a.startswith('--')]
    master = json.load(open(os.path.join(HERE, 'translations.json'), encoding='utf-8'))
    table = {(m['domain'], m['msgctxt'], m['msgid'], m['msgid_plural']): m for m in master}
    for d in dirs:
        d = os.path.abspath(d); project = os.path.basename(d)
        found = extract(d)
        by_domain = {}
        missing = []
        for (dom, ctx, mid, plu), refs in found.items():
            dom = dom.strip()
            m = table.get((dom, ctx, mid, plu))
            if not m:
                missing.append((dom, mid)); continue
            by_domain.setdefault(dom, []).append({'ctx': ctx, 'msgid': mid, 'plural': plu, 'fr': m['fr'], 'de': m['de'], 'ref': refs[0]})
        print(f'{project}: {sum(len(v) for v in by_domain.values())} textes traduits, {len(missing)} sans traduction')
        for dom, mid in missing[:50]: print('   À TRADUIRE', dom, '|', mid[:80])
        if check: continue
        langdir = os.path.join(d, 'languages'); os.makedirs(langdir, exist_ok=True)
        for dom, entries in by_domain.items():
            entries.sort(key=lambda e: (e['ref'], e['msgid']))
            for lang in ('fr', 'de'):
                base = os.path.join(langdir, f'{dom}-{LOCALES[lang]}')
                open(base + '.po', 'w', encoding='utf-8').write(po_text(dom, lang, entries, project))
                open(base + '.mo', 'wb').write(mo_bytes(lang, entries))
                print(f'   {os.path.relpath(base, d)}.po/.mo  ({len(entries)} textes)')

if __name__ == '__main__':
    main()
