"""Writes the data files for the WordPress plugin: rules.json, general_ids.tsv.gz (brands.json separately)."""
import gzip, json, shutil, sys
import matcher, rules

out = sys.argv[1]
d = rules.export()
d["norm"] = {chr(k) if isinstance(k, int) else k: v for k, v in matcher._TBL.items()}
json.dump(d, open(f"{out}/rules.json", "w", encoding="utf-8"), ensure_ascii=False)
if len(sys.argv) > 2:
    with open(sys.argv[2], "rb") as src, gzip.open(f"{out}/general_ids.tsv.gz", "wb", 9) as dst:
        shutil.copyfileobj(src, dst)
print("ok")
