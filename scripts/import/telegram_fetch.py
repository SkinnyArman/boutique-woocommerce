#!/usr/bin/env python3
"""
Read the shop's public Telegram channel and turn product posts into products.json + photos.

    python3 scripts/import/telegram_fetch.py            # last ~40 product posts
    python3 scripts/import/telegram_fetch.py --count 80
    python3 scripts/import/telegram_fetch.py --channel some_channel   # default: telegram_channel in config/shop.php

Output (next to this file): products.json and photos/<post>-<n>.jpg
Then load into WordPress:
    docker compose --profile cli run --rm wpcli eval-file /scripts/import/import-products.php

Captions look like:  تاپ کبریتی / برند: ONLY / سایز: 42/44/46 / قیمت: 1880T
Anything the parser is unsure about is listed in "problems"; those products are imported as drafts.
"""
import argparse, html, json, os, re, time, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))


def config_channel():
    """telegram_channel from config/shop.php (or the example), without needing PHP."""
    for name in ('shop.php', 'shop.example.php'):
        path = os.path.join(HERE, '..', '..', 'config', name)
        if os.path.exists(path):
            m = re.search(r"'telegram_channel'\s*=>\s*'([^']*)'", open(path, encoding='utf-8').read())
            if m and m.group(1):
                return m.group(1)
    return ''


CHANNEL = config_channel()
UA = {'User-Agent': 'Mozilla/5.0'}

FA_DIGITS = str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789')
ARABIC = str.maketrans({'ي': 'ی', 'ك': 'ک', 'ى': 'ی'})
LETTER_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL']
# How brands are written on the site (keys: lowercase letters only).
BRAND_NAMES = {
    'veromoda': 'Vero Moda', 'veramoda': 'Vero Moda', 'only': 'ONLY', 'yas': 'Y.A.S', 'noisymay': 'Noisy May',
    'cupshe': 'CUPSHE', 'shein': 'SHEIN', 'esmara': 'Esmara', 'pieces': 'Pieces', 'villa': 'VILA', 'vila': 'VILA',
    'mamalicious': 'Mamalicious', 'sinsay': 'Sinsay', 'paularichi': 'Paula Richi', 'kenzel': 'Kenzel',
    'tarikedize': 'Tarik Ediz', 'neon&nylon': 'Neon & Nylon', 'max': 'MAX',
}

# Title keyword -> category slug (first match wins; order matters).
CATEGORY_RULES = [
    (r'لگ\s*ورزشی', 'sport-leggings'),
    (r'(^|\s)لگ(\s|$)', 'leggings'),
    (r'جین|شلوار', 'pants'),
    (r'ترک', 'turkish-dress'),
    (r'مجلسی', 'evening'),
    (r'عروسکی', 'babydoll-dress'),
    (r'سرهمی|اورال', 'jumpsuit'),
    (r'تونیک', 'tunic'),
    (r'هودی', 'hoodie'),
    (r'پلیور|پليور|پولیور', 'pullover'),
    (r'کاردیگان|ژاکت', 'cardigan'),
    (r'تاپ بافت', 'knit-top'),
    (r'تاپ', 'top'),
    (r'شومیز', 'shomiz'),
    (r'بلوز', 'blouse'),
    (r'دامن', 'skirt'),
    (r'مایو', 'swimsuit'),
    (r'بیکینی', 'bikini'),
    (r'(پیراهن|بیراهن)\s*اسپرت', 'sport-dress'),
    (r'پیراهن|بیراهن', 'casual'),
]


def get(url):
    return urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=40).read()


def parse_page(src):
    out = []
    for m in re.finditer(r'<div class="tgme_widget_message_wrap.*?(?=<div class="tgme_widget_message_wrap|\Z)', src, re.S):
        b = m.group(0)
        pid = re.search(r'data-post="[^/]+/(\d+)"', b)
        txt = re.search(r'<div class="tgme_widget_message_text[^"]*"[^>]*>(.*?)</div>', b, re.S)
        text = html.unescape(re.sub(r'<br\s*/?>', '\n', txt.group(1))) if txt else ''
        text = re.sub(r'<[^>]+>', '', text).strip()
        imgs = re.findall(r"tgme_widget_message_photo_wrap[^>]*background-image:url\('([^']+)'\)", b)
        date = re.search(r'<time datetime="([^"]+)"', b)
        if pid:
            out.append({'post': int(pid.group(1)), 'date': date.group(1)[:10] if date else '', 'images': imgs, 'text': text})
    return out


def clean(s):
    s = s.translate(FA_DIGITS).translate(ARABIC)
    s = re.sub(r'[\U0001F300-\U0001FAFF☀-➿️]', '', s)  # emoji
    return re.sub(r'[ \t‏]+', ' ', s).strip()


def field(lines, *keys):
    for ln in lines:
        m = re.match(r'^(%s)\s*[:：]?\s*(.*)$' % '|'.join(keys), ln)
        if m:
            return m.group(2).strip()
    return None


def parse_sizes(raw):
    """'42/44(L)' -> (['42','44'], 'L'),  'Mمناسب 40/42' -> (['M'], 'مناسب سایز 40 تا 42'),  '38تا46' -> 38..46"""
    if not raw:
        return [], ''
    raw = raw.replace('l', 'L') if re.fullmatch(r'[A-Za-z/ ]+', raw) else raw
    note = ''
    m = re.match(r'^\s*([A-Za-z]{1,4}|\d{2})\s*(?:ولی\s*)?(به|مناسب)\s*(.+)$', raw)
    if m:
        size, rest = m.group(1).upper(), m.group(3)
        rest = re.sub(r'\s*(هم\s*)?میخوره\s*', '', rest)
        rest = re.sub(r'^(مناسب\s*)?(سایز\s*)?', '', rest.strip(' /'))
        rest = re.sub(r'\s*مناسب\s*$', '', rest)
        rest = re.sub(r'(\d)\s*تا\s*(\d)', r'\1 تا \2', rest).replace('/', ' و ')
        return [size], 'مناسب سایز ' + rest.strip()
    m = re.match(r'^\s*(\d{2})\s*تا\s*(\d{2})\s*$', raw)
    if m:
        a, b = int(m.group(1)), int(m.group(2))
        return [str(n) for n in range(a, b + 1, 2)], ''
    paren = re.findall(r'\(([^)]*)\)', raw)
    if paren:
        note = 'معادل ' + ' / '.join(p.strip() for p in paren)
    body = re.sub(r'\([^)]*\)', '', raw)
    tokens = re.findall(r'XXXL|XXL|XL|XS|\d{2}|[SMLsml](?![a-z])', body)
    sizes = []
    for t in tokens:
        t = t.upper()
        if (t in LETTER_SIZES or (t.isdigit() and 30 <= int(t) <= 56)) and t not in sizes:
            sizes.append(t)
    return sizes, note


def parse_price(raw):
    if not raw:
        return 0
    m = re.match(r'^\s*(\d[\d,]*)\s*(T|t|هزار)?', raw)
    if not m or '/' in raw:          # "36/38" typed in the price field
        return 0
    n = int(m.group(1).replace(',', ''))
    return n * 1000 if (m.group(2) or n < 100000) else n


def to_product(p):
    lines = [clean(l) for l in p['text'].split('\n')]
    lines = [l for l in lines if l]
    problems = []

    brand = field(lines, 'برند')
    brand = re.sub(r'\s*انگلیس\s*', '', brand or '').strip() or None
    if brand:
        brand = BRAND_NAMES.get(re.sub(r'[^a-z&]', '', brand.lower()), brand.upper() if len(brand) <= 5 else brand.title())

    # Per-color sizes: "سایز مشکی: XS/S/M", "رنگ صورتی: XS/S/M"
    colors = {}
    for ln in lines:
        m = re.match(r'^(?:سایز|رنگ)\s+([^:]+?)\s*:\s*(.+)$', ln)
        if m and not m.group(1).startswith(('برند',)):
            s, _ = parse_sizes(m.group(2))
            if s:
                colors[m.group(1).strip()] = s

    size_raw = field(lines, 'سایز', 'سايز')
    sizes, note = parse_sizes(size_raw)
    if colors:
        sizes = []
        for s in colors.values():
            sizes += [x for x in s if x not in sizes]

    price = parse_price(field(lines, 'قیمت', 'قيمت'))
    extra = []
    for ln in lines:
        m = re.match(r'^قد\s*(\S+)\s*[:：]\s*(\d+)', ln)
        if m:
            extra.append(f'قد {m.group(1)}: {m.group(2)} سانتی‌متر')

    title = next((l for l in lines if not re.match(r'^(برند|سایز|سايز|قیمت|قيمت|رنگ|قد|http|@|09|جهت|دوستان)', l)), '')
    title = re.sub(r'^بیراهن', 'پیراهن', title).strip(' /')
    category = next((slug for rx, slug in CATEGORY_RULES if re.search(rx, title)), None)

    if not title:
        problems.append('اسم ندارد')
    ask_price = not price and category in ('evening', 'turkish-dress')
    if not price and not ask_price:
        problems.append('قیمت ندارد یا اشتباه نوشته شده')
    if not sizes:
        problems.append('سایز مشخص نیست')
    if not category:
        problems.append('دسته مشخص نیست')
    if not p['images']:
        problems.append('عکس ندارد')

    name = (title or 'محصول') + (f' {brand}' if brand and brand.lower() not in title.lower() else '')
    desc = []
    if brand:
        desc.append(f'برند: {brand} (اورجینال)')
    if note:
        desc.append(note)
    desc += extra
    return {
        'post': p['post'], 'date': p['date'], 'name': name.strip(), 'brand': brand, 'category': category or 'other',
        'price': price, 'ask_price': ask_price, 'sizes': sizes, 'colors': colors, 'description': '\n'.join(desc),
        'images': p['images'], 'problems': problems, 'caption': p['text'],
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--count', type=int, default=40, help='how many product posts to collect')
    ap.add_argument('--channel', default=CHANNEL, help='public channel username (default: config/shop.php)')
    args = ap.parse_args()
    if not args.channel:
        raise SystemExit('No channel: set telegram_channel in config/shop.php or pass --channel')

    posts, before = {}, None
    for _ in range(40):
        url = f'https://t.me/s/{args.channel}' + (f'?before={before}' if before else '')
        page = parse_page(get(url).decode('utf-8'))
        if not page:
            break
        for p in page:
            posts[p['post']] = p
        before = min(p['post'] for p in page)
        found = [p for p in posts.values() if p['images'] and re.search(r'قیمت|قيمت|سایز|سايز', p['text'])]
        if len(found) >= args.count:
            break
        time.sleep(1.5)

    candidates = sorted((p for p in posts.values() if p['images'] and re.search(r'قیمت|قيمت|سایز|سايز', p['text'])), key=lambda p: -p['post'])[: args.count]
    products = [to_product(p) for p in candidates]

    os.makedirs(os.path.join(HERE, 'photos'), exist_ok=True)
    for prod in products:
        local = []
        for i, url in enumerate(prod['images'][:6]):
            fn = f"{prod['post']}-{i + 1}.jpg"
            path = os.path.join(HERE, 'photos', fn)
            if not os.path.exists(path):
                with open(path, 'wb') as f:
                    f.write(get(url))
                time.sleep(0.3)
            local.append(fn)
        prod['photos'] = local

    with open(os.path.join(HERE, 'products.json'), 'w', encoding='utf-8') as f:
        json.dump(products, f, ensure_ascii=False, indent=1)
    ok = [p for p in products if not p['problems']]
    print(f'{len(products)} products ({len(ok)} complete, {len(products) - len(ok)} need checking) -> {HERE}/products.json')


if __name__ == '__main__':
    main()
