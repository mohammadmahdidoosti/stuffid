"""Extract a brand key and its search aliases from a product name."""
import re

from matcher import normalize

_LAT = re.compile(r"[a-z][a-z0-9&\.\-]*")
_STOPLAT = {"pu", "pe", "pp", "pvc", "upvc", "led", "smd", "cob", "ac", "dc", "abs", "pex", "ip", "nh", "dn",
            "pn", "sdr", "rgb", "repair", "kit", "for", "manifold", "color", "bottom", "back", "bar", "type",
            "shilang", "with", "and", "the", "of", "mm", "cm", "kw", "kva", "hp", "v", "a", "b", "c", "x", "s",
            "m", "l", "h", "t", "u", "g", "r", "p", "w", "z", "power", "full", "spectrum", "end", "cap",
            "double", "socket", "flange", "adaptor", "barrel", "nipple", "reducing", "brass", "female", "male",
            "cross", "solvent", "valve", "way", "actuation", "standard", "combined", "overflow", "start", "stop",
            "quick", "blind", "pipe", "ring", "tapper", "true", "digital", "dc/ac", "ac/dc", "rtu", "modbus", "usb"}


def norm(s):
    return " ".join(normalize(s).split())


def extract(name):
    """Return (brand_key, aliases) or (None, [])."""
    n = norm(name)
    # 1) explicit "برند X"
    m = re.search(r"برند\s+([^\d\(\)\-،,]+?)(?:\s*\(([^)]*)\))?\s*(?:$|مدل|کد|سایز|\d|-|،|,)", n + " ")
    if m:
        fa = m.group(1).strip()
        fa = re.sub(r"\s+(هر ?متر|شاخه ای|رولی|متری)$", "", fa).strip()
        lat = (m.group(2) or "").strip()
        aliases = [a for a in {fa, lat} if a and len(a) >= 2]
        lat_words = [w for w in _LAT.findall(fa) if w not in _STOPLAT and len(w) >= 3]
        aliases += lat_words
        return fa, sorted(set(aliases))
    # 2) trailing latin brand word (e.g. "... سینری scenery", "... ایرکنترل aircontrol")
    words = n.split()
    for i in range(len(words) - 1, max(-1, len(words) - 5), -1):
        w = words[i].strip(".-()")
        if _LAT.fullmatch(w) and w not in _STOPLAT and len(w) >= 3 and not re.search(r"\d", w):
            fa = words[i - 1] if i > 0 and not _LAT.fullmatch(words[i - 1]) and not re.search(r"\d", words[i - 1]) else ""
            # two-word persian names like "زد بی اف", "ال ام سی", "الفا مک"
            if i > 1 and fa and len(fa) <= 3 and not re.search(r"\d", words[i - 2]) and not _LAT.fullmatch(words[i - 2]):
                fa = words[i - 2] + " " + fa
            aliases = sorted({a for a in (w, fa) if a and len(a) >= 2})
            return w, aliases
    # 3) known suffix brands without برند (e.g. "... روکا گستر", "... پارس فانال")
    m = re.search(r"(پارس فانال|روکا گستر|اف جی|سی پی سی|cpc)$", n)
    if m:
        return m.group(1), [m.group(1)]
    return None, []
