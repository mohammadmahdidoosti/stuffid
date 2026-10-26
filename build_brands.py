"""brand_origin counts -> plugin data/brands.json  ([[alias, "I"|"D"], ...] longest alias first).

usage: build_brands.py counts.json out_dir
A brand is imported when >=70% of the specific IDs mentioning it are imported, domestic when <=30%,
otherwise ambiguous (left out, so the plugin falls back to its default origin).
"""
import json
import sys

counts = json.load(open(sys.argv[1], encoding="utf-8"))
aliases = set(json.load(open("brand_aliases.json", encoding="utf-8")))
out, amb, rare = [], [], []
for a, (imp, dom) in counts.items():
    if a not in aliases:
        continue
    tot = imp + dom
    if tot < 3:
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
