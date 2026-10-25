"""stuffid - stage 1: inspect the product_good zip files from Google Drive.

Endpoints (all except / require ?token=ADMIN_TOKEN):
  /                      health
  /files                 list configured Drive files
  /inspect?part=7        download one part, list zip contents, show sheets, header and sample rows
"""
import json
import os
import threading
import time
import zipfile

import requests
from flask import Flask, Response, request

app = Flask(__name__)

TOKEN = os.environ.get("ADMIN_TOKEN", "")
WORK = "/tmp/stuffid"
os.makedirs(WORK, exist_ok=True)

# Google Drive file ids, folder "tax"
PARTS = {
    1: "1ELetseHnuh5g_sMY_sJkO0vDlSgVTmjZ",
    2: "1n8dPhscbVBIWgHsri9wQhnqYrIebyEKe",
    3: "1OhGIS6IVVJzDD8cct0PXBwc2TtZmmH_7",
    4: "1eg1WMh-e4pAgsEmn2Sqjo8IU0hQJiJzL",
    5: "1Yu2joZUKRwKB73r5rSwvHvP4H0YPQ-Wb",
    6: "1AoodP9jbSfuqwAEUXp_DHgBmqyonucXM",
    7: "1WA-WMEcXjY6_NH4uAFHYJxyS5xswiEQA",
}

_cache = {}
_lock = threading.Lock()


def as_json(obj, status=200):
    return Response(json.dumps(obj, ensure_ascii=False, indent=1, default=str),
                    status=status, mimetype="application/json; charset=utf-8")


def authorized():
    return TOKEN and request.args.get("token") == TOKEN


def download(part):
    """Download a public Drive file (link sharing on). Returns local path."""
    fid = PARTS[part]
    path = os.path.join(WORK, f"part_{part}.zip")
    if os.path.exists(path) and zipfile.is_zipfile(path):
        return path
    url = "https://drive.usercontent.google.com/download"
    with requests.get(url, params={"id": fid, "export": "download", "confirm": "t"},
                      stream=True, timeout=120) as r:
        r.raise_for_status()
        ctype = r.headers.get("content-type", "")
        if "text/html" in ctype:
            snippet = r.raw.read(600).decode("utf-8", "replace")
            raise RuntimeError(f"Drive returned HTML instead of the file (is link sharing on?): {snippet[:300]}")
        tmp = path + ".part"
        with open(tmp, "wb") as f:
            for chunk in r.iter_content(1 << 20):
                f.write(chunk)
    os.replace(tmp, path)
    return path


def inspect_xlsx(fobj, max_rows=6):
    from openpyxl import load_workbook
    wb = load_workbook(fobj, read_only=True, data_only=True)
    sheets = []
    for ws in wb.worksheets:
        rows = []
        for i, row in enumerate(ws.iter_rows(values_only=True)):
            rows.append(list(row))
            if i + 1 >= max_rows:
                break
        sheets.append({"sheet": ws.title, "max_row": ws.max_row,
                       "max_column": ws.max_column, "rows": rows})
    wb.close()
    return sheets


def inspect_csv(fobj, max_rows=6):
    import io
    raw = fobj.read(200_000)
    for enc in ("utf-8-sig", "cp1256", "utf-16"):
        try:
            text = raw.decode(enc)
            break
        except UnicodeDecodeError:
            continue
    lines = text.splitlines()[:max_rows]
    return [{"encoding": enc, "lines": lines}]


def inspect_part(part):
    t0 = time.time()
    path = download(part)
    out = {"part": part, "zip_bytes": os.path.getsize(path), "download_s": round(time.time() - t0, 1),
           "entries": []}
    with zipfile.ZipFile(path) as z:
        infos = z.infolist()
        out["entry_count"] = len(infos)
        for zi in infos[:50]:
            e = {"name": zi.filename, "size": zi.file_size, "compressed": zi.compress_size}
            out["entries"].append(e)
        # inspect the first two data files in detail
        detailed = 0
        for zi in infos:
            name = zi.filename.lower()
            if detailed >= 2:
                break
            try:
                if name.endswith((".xlsx", ".xlsm")):
                    with z.open(zi) as f:
                        import io
                        data = io.BytesIO(f.read())
                    out.setdefault("details", []).append({"file": zi.filename, "sheets": inspect_xlsx(data)})
                    detailed += 1
                elif name.endswith((".csv", ".txt")):
                    with z.open(zi) as f:
                        out.setdefault("details", []).append({"file": zi.filename, "csv": inspect_csv(f)})
                    detailed += 1
            except Exception as ex:  # keep going, report error
                out.setdefault("details", []).append({"file": zi.filename, "error": repr(ex)})
                detailed += 1
    out["total_s"] = round(time.time() - t0, 1)
    return out


@app.get("/")
def health():
    return as_json({"ok": True, "service": "stuffid", "stage": "inspect"})


@app.get("/files")
def files():
    if not authorized():
        return as_json({"error": "unauthorized"}, 401)
    return as_json(PARTS)


@app.get("/inspect")
def inspect():
    if not authorized():
        return as_json({"error": "unauthorized"}, 401)
    part = int(request.args.get("part", "7"))
    if part not in PARTS:
        return as_json({"error": "unknown part"}, 400)
    with _lock:
        if part in _cache and not request.args.get("refresh"):
            return as_json(_cache[part])
    try:
        res = inspect_part(part)
    except Exception as ex:
        return as_json({"part": part, "error": repr(ex)}, 500)
    with _lock:
        _cache[part] = res
    return as_json(res)


def log_result(tag, obj):
    """Write a result to stdout in chunks so it can be read back from Render logs."""
    text = json.dumps(obj, ensure_ascii=False, default=str)
    size = 2500
    chunks = [text[i:i + size] for i in range(0, len(text), size)] or [""]
    for n, c in enumerate(chunks, 1):
        print(f"RESULT {tag} {n}/{len(chunks)} {c}", flush=True)


def keepalive():
    """Free Render services sleep after ~15 min without traffic; ping ourselves while a job runs."""
    url = os.environ.get("RENDER_EXTERNAL_URL")
    if url:
        try:
            requests.get(url, timeout=20)
        except Exception:
            pass


def run_job():
    job = os.environ.get("JOB", "").strip()
    if not job:
        return
    print(f"JOB start {job}", flush=True)
    for step in job.split(";"):
        kind, _, arg = step.partition(":")
        try:
            if kind == "inspect":
                log_result(step, inspect_part(int(arg)))
        except Exception as ex:
            log_result(step, {"error": repr(ex)})
        keepalive()
    print(f"JOB done {job}", flush=True)


threading.Thread(target=run_job, daemon=True).start()


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.environ.get("PORT", 10000)))
