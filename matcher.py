"""Text matching between shop product names and stuff-ID descriptions."""
import re

_AR = str.maketrans({
    "ي": "ی", "ى": "ی", "ئ": "ی", "ك": "ک", "ة": "ه", "ؤ": "و", "أ": "ا", "إ": "ا", "آ": "ا",
    "۰": "0", "۱": "1", "۲": "2", "۳": "3", "۴": "4", "۵": "5", "۶": "6", "۷": "7", "۸": "8", "۹": "9",
    "٠": "0", "١": "1", "٢": "2", "٣": "3", "٤": "4", "٥": "5", "٦": "6", "٧": "7", "٨": "8", "٩": "9",
    "‌": " ", "‏": " ", "‎": " ", "ـ": "", "×": " x ", "*": " x ", "–": "-", "،": " ", "؛": " ",
})

STOP = set("""و با در از به برای که یا هر این آن سایز مدل برند نوع سری کد شماره فنی سازنده ایران
عدد عددی دارای بدون طرح رنگ میلی متر اینچ سانت x - the of and""".split())

_SPLIT = re.compile(r"[^\w\-/\.+]+", re.UNICODE)


def normalize(s):
    s = (s or "").translate(_AR).lower()
    s = re.sub(r"[ً-ٰٟ]", "", s)  # harakat
    return s


def tokens(s):
    out = []
    for t in _SPLIT.split(normalize(s)):
        t = t.strip("-./+")
        if not t or t in STOP:
            continue
        out.append(t)
        # model codes like 4v210-06 also contribute their parts
        if re.search(r"[a-z]", t) and re.search(r"\d", t) and re.search(r"[-/.]", t):
            out.extend(p for p in re.split(r"[-/.]", t) if len(p) >= 2)
    return out
