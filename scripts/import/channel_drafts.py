#!/usr/bin/env python3
"""
Turn the channel history into draft products, the way the shop owner writes them by hand.

    python3 -I scripts/import/channel_drafts.py RAW.json LIVE.json --since 2025-07-09 > scripts/import/drafts.json

RAW.json   posts read from t.me/s/<channel> (parse_page() output of telegram_fetch.py)
LIVE.json  the live site's products (needs "tg" = Telegram post number) -> those posts are skipped

Rules (learned from the products the owner already edited on the site):
- One post = one piece. "سایز: 42/44/46" is the range it fits: it goes to the description
  («مناسب سایز ۴۲ تا ۴۶») and ONE size is selectable (a letter size, or for evening/Turkish dresses
  the largest number). Several letter sizes ("S/M/L") or jeans with listed sizes are separate pieces.
- No brand in the description (the brand stays in the name and the brand field).
- Everything is a draft; whatever needs checking is one line at the top of the description.
"""
import argparse, importlib.util, json, os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location('tf', os.path.join(HERE, 'telegram_fetch.py'))
tf = importlib.util.module_from_spec(spec); spec.loader.exec_module(tf)

FA = str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹')
fa = lambda s: str(s).translate(FA)

# Posts that are already on the site under another number, or repeat another post (checked by photo).
SKIP = {
    3149: 'live #1656 (same photo)', 3150: 'live #1880', 3151: 'live #1885', 1485: 'live #1600',
    3065: 'live #1889', 3068: 'live #1896', 2188: 'same item as live post 3317',
    1103: 'reposted as 2426', 2259: 'reposted as 2295',
    1989: 'photo of 2159 without text', 2109: 'photo of 3059 without text', 2304: 'photo of 2919 without text',
}

# Posts with photos but no text: what the photo shows (name, category). Price/size left for the owner.
NO_TEXT = {
    1086: ('پیراهن', 'casual'), 1099: ('پیراهن خال‌خالی', 'casual'), 1185: ('پیراهن مجلسی بلند', 'evening'),
    1195: ('پیراهن', 'casual'), 1205: ('تاپ', 'top'), 1470: ('تاپ', 'top'),
    1936: ('شال', 'shawl'), 1940: ('شال', 'shawl'), 1950: ('شال', 'shawl'),
    1972: ('پیراهن مجلسی ترک', 'turkish-dress'), 1978: ('پیراهن مجلسی ترک', 'turkish-dress'),
    1993: ('پیراهن مجلسی بلند', 'evening'), 2272: ('پیراهن مجلسی بلند', 'evening'),
    2285: ('پیراهن مجلسی بلند', 'evening'), 2313: ('پیراهن مجلسی بلند', 'evening'),
    2957: ('پیراهن مجلسی کوتاه', 'evening'),
    **{p: ('دامن کوتاه', 'skirt') for p in (2001, 2008, 2012, 2014, 2016, 2021, 2024, 2025, 2029, 2032, 2034, 2037)},
    2252: ('کت', 'jacket'), 2256: ('کت', 'jacket'), 2453: ('تاپ', 'top'), 3227: ('شومیز', 'shomiz'),
    3240: ('شلوار جین', 'pants'), 3309: ('شلوار', 'pants'), 3313: ('لگ', 'pants'),
}

BRANDS = {  # key: lowercase latin letters / Persian with spaces removed
    'veromoda': 'Vero Moda', 'veramoda': 'Vero Moda', 'vermmoda': 'Vero Moda', 'only': 'ONLY', 'yas': 'Y.A.S',
    'noisymay': 'Noisy May', 'cupshe': 'CUPSHE', 'shein': 'SHEIN', 'esmara': 'Esmara', 'esmar': 'Esmara',
    'rsmara': 'Esmara', 'اسمارا': 'Esmara', 'pieces': 'Pieces', 'vila': 'VILA', 'villa': 'VILA',
    'mamalicious': 'Mamalicious', 'sinsay': 'Sinsay', 'paularichi': 'Paula Richi', 'puallarichi': 'Paula Richi',
    'kenzel': 'Kenzel', 'tarikediz': 'Tarik Ediz', 'tarikedize': 'Tarik Ediz', 'تاریکادیز': 'Tarik Ediz',
    'neon&nylon': 'Neon & Nylon', 'max': 'MAX', 'nelly': 'NELLY', 'nly': 'NLY', 'nlyone': 'NLY One',
    'nlyonr': 'NLY One', 'nlytrend': 'NLY Trend', 'nlytrenf': 'NLY Trend', 'azazie': 'Azazie', 'asos': 'ASOS',
    'jdy': 'JDY', 'crivit': 'Crivit', 'object': 'Object', 'objectدانمارک': 'Object', 'crane': 'CRANE',
    'alfabeta': 'Alfa Beta', 'آلفابتا': 'Alfa Beta', 'الفابتا': 'Alfa Beta', 'rocktrail': 'Rocktrail',
    'iam': 'IAM', 'up2fashen': 'UP2FASHION', 'up2fashion': 'UP2FASHION', 'muzafar': 'Muzafar', 'kiabi': 'KIABI',
    'c&a': 'C&A', 'clockhouse': 'Clockhouse', 'luxedo': 'Luxedo', 'veneno': 'Veneno', 'messina': 'Messina',
}

# Title keyword -> category slug (first match wins).
CATS = [
    (r'ترک', 'turkish-dress'), (r'مجلسی|لباس\s*شب', 'evening'), (r'^(پیراهن|پیرهن)\s*اسپرت', 'sport-dress'), (r'^(پیراهن|پیرهن)', 'casual'),
    (r'کت\s*جین', 'denim-jacket'), (r'لگ\s*ورزشی', 'sport-leggings'), (r'(^|\s)لگ(\s|$)', 'leggings'), (r'شلوار|جین', 'pants'), (r'عروسکی', 'babydoll-dress'), (r'سرهمی|اورال|رامپر', 'jumpsuit'),
    (r'مانتو|پانچو', 'manteau'), (r'^کت|\sکت', 'jacket'), (r'تونیک', 'tunic'), (r'هودی', 'hoodie'),
    (r'دورس|نیم\s*زیپ', 'sweatshirt'), (r'پولو', 'polo-shirt'), (r'پلیور|پليور|پولیور', 'pullover'), (r'کاردیگان|ژاکت', 'cardigan'),
    (r'تاپ\s*بافت', 'knit-top'), (r'تاپ|کراپ', 'top'), (r'شومیز', 'shomiz'), (r'بلوز', 'blouse'),
    (r'دامن', 'skirt'), (r'مایو', 'swimsuit'), (r'بیکینی', 'bikini'), (r'شال', 'shawl'), (r'کلاه', 'hat'), (r'جوراب', 'socks'),
    (r'^ست\s', 'sets'), (r'پیراهن|پیرهن', 'casual'),
]
LETTERS = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL']
NUMERIC_CATS = {'evening', 'turkish-dress'}   # owner keeps these in numbers (picks the larger one)
SKIP_LINE = re.compile(r'جهت|@|09\d|http|دوستان|حراج|موجود است|ایدی زیر|پیغام دهید|طبق روال|حضوری داریم|خرید حضوری|\bآنلاین$|'
                       r'قیمت\s*(تو|در)\s*(سایت|اروپا)|فروخته|شرکت\s*[:：]|^[-_.]+$|^[A-Za-z .]+$')
FIELD = re.compile(r'^(برند|برن|سایز|سايز|سابز|قیمت|قيمت)\s*[:：]?')


def brand_of(raw):
    if not raw:
        return None
    raw = re.sub(r'\s*(انگلیس|انگليس|آلمان|اروپا)\s*', ' ', raw).strip()
    key = re.sub(r'[\s.\-]', '', raw.lower())
    key2 = re.sub(r'[^a-z&]', '', raw.lower())
    return BRANDS.get(key) or BRANDS.get(key2) or (raw.upper() if len(raw) <= 5 and raw.isascii() else raw.title() if raw.isascii() else raw)


def letter_for(nums):
    """Fit range -> one letter size, matching what the owner picked (42/44 -> L, 42/44/46 -> XL, 38/40 -> M)."""
    m = (min(nums) + max(nums)) / 2
    for limit, s in ((38, 'S'), (41, 'M'), (44, 'L'), (48, 'XL'), (52, 'XXL')):
        if m < limit:
            return s
    return 'XXXL'


def fit_text(nums):
    nums = sorted(set(nums))
    if len(nums) == 1:
        return f'مناسب سایز {fa(nums[0])}'
    if len(nums) >= 3 and all(b - a == 2 for a, b in zip(nums, nums[1:])):
        return f'مناسب سایز {fa(nums[0])} تا {fa(nums[-1])}'
    return 'مناسب سایز ' + ' و '.join(fa(n) for n in nums)


def nums_in(s):
    s = re.sub(r'(\d{2})\s*تا\s*(\d{2})', lambda m: '/'.join(str(n) for n in range(int(m.group(1)), int(m.group(2)) + 1, 2)), s)
    return [int(n) for n in re.findall(r'(?<!\d)(\d{2})(?!\d)', s) if 30 <= int(n) <= 60]


def letters_in(s):
    s = s.upper().replace('2XL', 'XXL').replace('3XL', 'XXXL').replace('LXL', 'L/XL')
    out = []
    for t in re.findall(r'XXXL|XXL|XL|XS|(?<![A-Z])[SML](?![A-Z])', s):
        if t not in out:
            out.append(t)
    return out


def sizes_from(lines, category, title):
    """-> (selectable sizes, description lines, note about a guessed size or '')"""
    size_lines = [re.sub(r'^(سایز|سايز|سابز)\s*[:：]?\s*', '', l) for l in lines if re.match(r'^(سایز|سايز|سابز)', l)]
    size_lines += [l for l in lines if re.fullmatch(r'\s*\d{2}(\s*/\s*\d{2})+\s*', l)]
    if not size_lines:
        return [], [], ''
    sizes, desc = [], []
    if len(size_lines) > 1 and not any(letters_in(re.split(r'مناسب|به|ولی|که', sl)[0]) for sl in size_lines):
        for sl in size_lines:                             # "38/40" + "42/44/46/48": two pieces
            ns = nums_in(sl)
            if ns:
                pick = letter_for(ns)
                if pick not in sizes:
                    sizes.append(pick)
                desc.append(f'سایز {pick} ' + fit_text(ns))
        return sizes, desc, 'سایزها از روی اعداد کانال گذاشته شد'
    if len(size_lines) > 1:  # "سایز: L مناسب 42 تا 44" / "سایز: XL که مناسب 44/46/48"
        for sl in size_lines:
            ls, ns = letters_in(re.split(r'مناسب|به|ولی|که', sl)[0]), nums_in(sl)
            if ls:
                sizes += [x for x in ls if x not in sizes]
                if ns:
                    desc.append(f'سایز {ls[0]} ' + fit_text(ns))
        return sizes, desc, ''
    raw = size_lines[0]
    head = re.split(r'مناسب|به\s|ولی|که|\(', raw)[0]
    paren = re.findall(r'\(([^)]*)\)', raw)
    ls_head, ls_paren = letters_in(head), letters_in(' '.join(paren))
    ns = nums_in(raw)
    if ls_paren and ns:                                   # 42/44(L)
        return ls_paren[:1], [fit_text(ns)], ''
    if ls_head and len(ls_head) > 1:                      # S/M/L/XL: separate pieces
        return ls_head, [], ''
    if ls_head:                                           # M مناسب 40/42
        return ls_head, [fit_text(ns)] if ns else [], ''
    if re.search(r'فری|free', raw, re.I):
        return ['فری‌سایز'], [fit_text(ns)] if ns else [], ''
    if not ns:
        return [], [], ''
    num_head, num_rest = nums_in(head), nums_in(raw[len(head):])
    if len(num_head) == 1 and num_rest:                   # "36 به 38 هم میخوره" / "36 ولی به 38 و 40 میخوره"
        return [str(num_head[0])], [fit_text(num_rest)], ''
    if len(ns) == 1:                                      # 36
        return [str(ns[0])], [], ''
    if 'pants' == category and 'جین' in title:            # jeans list = separate pieces
        return [str(n) for n in num_head or ns], [], ''
    if max(ns) - min(ns) >= 12:                           # از ۳۶ تا ۵۶: fits almost everyone
        return ['فری‌سایز'], [fit_text(ns)], ''
    if category in NUMERIC_CATS:
        return [str(max(ns))], [fit_text(ns)], f'سایز قابل انتخاب از روی «{fa("/".join(map(str, ns)))}» گذاشته شد: {fa(max(ns))}'
    pick = letter_for(ns)
    return [pick], [fit_text(ns)], f'سایز قابل انتخاب از روی «{fa("/".join(map(str, ns)))}» گذاشته شد: {pick}'


def convert(p):
    text = p['text'] or ''
    text = re.sub(r'سلیز|سابز|سايز', 'سایز', text.replace('؛', ':'))      # typos seen in the channel
    text = re.sub(r'(?<=\S)\s*(?=(سایز|سايز)\s*[:：])', '\n', text)
    sold = bool(re.search(r'فروخته\s*شد(?!\s*نی)', text)) and 'فروخته میشود' not in text
    lines = [tf.clean(l) for l in text.split('\n')]
    lines = [l for l in lines if l and (FIELD.match(l) or not SKIP_LINE.search(l))]
    problems = []

    if not text.strip():
        name, cat = NO_TEXT.get(p['post'], ('محصول', 'other'))
        return dict(post=p['post'], date=p['date'], name=name, brand=None, category=cat, categories=[cat], price=0, ask_price=False,
                    sizes=[], colors={}, material='', description='', images=p['images'][:6],
                    problems=['این پست در کانال متن نداشت؛ اسم و دسته از روی عکس گذاشته شد. قیمت و سایز را وارد کنید'],
                    caption='')

    brand = brand_of(tf.field(lines, 'برند') or tf.field(lines, 'برن'))
    # A size list typed into the price field ("قیمت: 36/38") is really the size.
    lines = [re.sub(r'^(قیمت|قيمت)\s*[:：]?\s*(\d{2}(?:\s*/\s*\d{2})+)\s*$', r'سایز:\2', l) for l in lines]
    title_line = next((l for l in lines if not re.match(r'^(برند|سایز|سايز|قیمت|قيمت|رنگ|قد|جنس|مناسب)', l)), '')
    title = title_line
    title = re.sub(r'^(بیراهن|پیرهن)', 'پیراهن', title)
    title = re.sub(r'^بلور\b', 'بلوز', title).strip(' /:')
    if brand and title and brand.lower() in title.lower():
        title = re.sub(re.escape(brand), '', title, flags=re.I).strip()
    cat = next((slug for rx, slug in CATS if re.search(rx, title)), 'other')

    # Per-color sizes: "رنگ سفید:M/L/XL", "طوسی:M/L/XL", "سایز مشکی: XS/S"
    colors, color_lines = {}, set()
    for ln in lines:
        m = re.match(r'^(?:سایز\s+|سايز\s+|رنگ\s*)?([^:：\d]{2,15}?)\s*[:：]\s*([SMLXsmlx/\s]+)$', ln)
        if m and not re.match(r'^(برند|سایز|سايز|قیمت|قيمت|قد|جنس)$', m.group(1).strip()) and 'سایز' not in m.group(1) and letters_in(m.group(2)):
            colors[m.group(1).strip()] = letters_in(m.group(2))
            color_lines.add(ln)
        m = re.match(r'^رنگ\s*بندی\s*[:：]\s*(.+)$', ln) or (color_lines and re.fullmatch(
            r'((?:[^\x00-\x7f/:：]|\s)+?\s*(?:XXXL|XXL|XL|XS|S|M|L)(?:\s*/\s*(?:XXXL|XXL|XL|XS|S|M|L))*)', ln))  # "رنگ بندی: مشکیS/M" + "قرمزS/M/L"
        if m:
            for c, ss in re.findall(r'((?:[^\x00-\x7f/:：]|\s)+?)\s*((?:XXXL|XXL|XL|XS|S|M|L)(?:\s*/\s*(?:XXXL|XXL|XL|XS|S|M|L))*)', m.group(1)):
                colors[c.strip(' /')] = letters_in(ss)
            if colors:
                color_lines.add(ln)

    sizes, desc, size_note = sizes_from([l for l in lines if l not in color_lines], cat, title)
    if colors:
        sizes = []
        for s in colors.values():
            sizes += [x for x in s if x not in sizes]

    price = tf.parse_price(tf.field(lines, 'قیمت', 'قيمت'))
    material = ''
    extra = []
    for ln in lines:
        if ln == title_line or FIELD.match(ln) or ln in color_lines or re.fullmatch(r'\s*\d{2}(\s*/\s*\d{2})+\s*', ln):
            continue
        m = re.match(r'^جنس\s*[:：]?\s*(.+)$', ln)
        if m:
            material = m.group(1).strip(); continue
        m = re.match(r'^رنگ\s*[:：]?\s*(.+)$', ln)
        if m:
            extra.append('رنگ: ' + m.group(1).strip()); continue
        if brand and brand.lower() in ln.lower():
            continue
        m = re.match(r'^قد\s*([^:：\d]*)\s*[:：]?\s*(\d+)', ln)
        if m:
            extra.append(f'قد {m.group(1).strip()}: {fa(m.group(2))} سانتی‌متر'.replace('قد :', 'قد:')); continue
        extra.append(ln.translate(FA))
    desc = [d for d in desc] + extra

    ask = not price and cat in NUMERIC_CATS
    if not title:
        problems.append('اسم ندارد'); title = 'محصول'
    if not price and not ask:
        problems.append('قیمت ندارد')
    if not sizes:
        problems.append('سایز مشخص نیست')
    if cat == 'other':
        problems.append('دسته مشخص نیست')
    if sold:
        problems.append('در کانال نوشته شده «فروخته شد»؛ موجودی صفر گذاشته شد')
    name = (title + (f' {brand}' if brand else '')).strip()
    cats = ['blouse', 'pants'] if re.match(r'^ست\s*بلوز', title) else [cat]   # the owner files sets under both
    return dict(post=p['post'], date=p['date'], name=name, brand=brand, category=cats[0], categories=cats, price=price, ask_price=ask,
                sizes=sizes, colors=colors, material=material, description='\n'.join(desc), images=p['images'][:6],
                problems=problems, size_note=size_note, sold=sold, caption=text)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('raw'); ap.add_argument('live'); ap.add_argument('--since', default='2025-07-09')
    a = ap.parse_args()
    live = {int(p['tg']) for p in json.load(open(a.live)) if p.get('tg')}
    out = []
    for p in json.load(open(a.raw)):
        if p['date'] < a.since or not p['images'] or p['post'] in live or p['post'] in SKIP:
            continue
        out.append(convert(p))
    json.dump(out, sys.stdout, ensure_ascii=False, indent=1)
    print(f'{len(out)} drafts', file=sys.stderr)


if __name__ == '__main__':
    main()
