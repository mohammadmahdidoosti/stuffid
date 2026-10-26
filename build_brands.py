"""brand_origin counts -> plugin data/brands.json  ([[alias, "I"|"D"], ...] longest alias first).

usage: build_brands.py counts.json out_dir
A brand is imported when >=70% of the specific IDs mentioning it are imported, domestic when <=30%,
otherwise ambiguous (left out, so the plugin falls back to its default origin).
"""
import json
import re
import sys

counts = json.load(open(sys.argv[1], encoding="utf-8"))
aliases = set(json.load(open("brand_aliases.json", encoding="utf-8")))
# words that slipped into the alias list but are not brands
GENERIC = set("""مهندسی استیل چین ایتالیا الومینیوم برنجی بار هدف پاکت پله جریان میل وات چدنی راحت بیت وال
black seal spa rope pin fix quick speed int era mar counter vac ofr msc s.s sda dpl lsm copper
epe xpe bldc mvsc adj pvc upvc""".split())
out, amb, rare = [], [], []
for a in aliases:
    if a in GENERIC:
        continue
    imp, dom = counts.get(a, [0, 0])
    tot = imp + dom
    if tot < 3:
        # too little data: latin-named brands in this catalog are imports; persian ones fall back to the default
        if re.fullmatch(r"[a-z]{3,}", a):
            out.append([a, "I"])
        else:
            rare.append(a)
        continue
    share = imp / tot
    if share >= 0.7:
        out.append([a, "I"])
    elif share <= 0.3:
        out.append([a, "D"])
    else:
        amb.append((a, imp, dom))
out.sort(key=lambda x: -len(x[0]))
json.dump(out, open(f"{sys.argv[2]}/brands.json", "w", encoding="utf-8"), ensure_ascii=False)
print(f"brands: {len(out)} (I={sum(1 for x in out if x[1] == 'I')}, D={sum(1 for x in out if x[1] == 'D')}), "
      f"ambiguous: {len(amb)}, too rare: {len(rare)}")
for a, i, d in sorted(amb, key=lambda x: -(x[1] + x[2]))[:20]:
    print("  ambiguous:", a, i, d)
