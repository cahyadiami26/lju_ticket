import re, os, glob


def strip_php(src):
    """Hapus komentar & string literal agar analisis tidak menangkap teks."""
    out = []
    i = 0
    n = len(src)
    while i < n:
        c = src[i]
        if c == '/' and i + 1 < n and src[i+1] == '*':
            j = src.find('*/', i + 2)
            j = n if j < 0 else j + 2
            out.append('\n' * src[i:j].count('\n'))
            i = j
        elif c == '/' and i + 1 < n and src[i+1] == '/':
            j = src.find('\n', i)
            j = n if j < 0 else j
            i = j
        elif c == '#':
            j = src.find('\n', i)
            j = n if j < 0 else j
            i = j
        elif c in ('"', "'"):
            q = c
            i += 1
            while i < n:
                if src[i] == '\\':
                    i += 2
                    continue
                if src[i] == q:
                    i += 1
                    break
                i += 1
        else:
            out.append(c)
            i += 1
    return ''.join(out)

ROOT = os.getcwd()
inc_re = re.compile(r"require(?:_once)?\s+__DIR__\s*\.\s*'(/[^']+)'")

def resolve(base_file, path):
    return os.path.normpath(os.path.join(os.path.dirname(base_file), path.lstrip('/')))

# graph
graph, provided, funcs = {}, {}, {}
for f in glob.glob('includes/*.php') + glob.glob('config/*.php'):
    src = open(f).read()
    graph[f] = [t for m in inc_re.finditer(src) for t in [resolve(f, m.group(1))] if os.path.exists(t)]
    provided[f] = set(re.findall(r'^\$(\w+)\s*=', src, re.M))
    funcs[f] = set(re.findall(r'^function\s+(\w+)', src, re.M))

def closure(f, key, seen=None):
    seen = seen or set()
    if f in seen: return set()
    seen.add(f)
    out = set(key.get(f, set()))
    for d in graph.get(f, []):
        out |= closure(d, key, seen)
    return out

pages = [p for p in glob.glob('*.php') + glob.glob('*/*.php')
         if not p.startswith(('includes/', 'config/', 'library/'))]

print('== Cakupan include per halaman ==')
problems = 0
for page in sorted(pages):
    raw = open(page).read()
    lines = raw.split('\n')          # deteksi include (butuh string literal)
    scan = strip_php(raw).split('\n')  # deteksi FUNC/VAR (tanpa string/komentar)
    incs = []
    for i, l in enumerate(lines, 1):
        for m in inc_re.finditer(l):
            t = resolve(page, m.group(1))
            if os.path.exists(t):
                incs.append((i, t))
    # variabel yang masuk lewat closure `use (&$x)` atau `global $x` dianggap tersedia
    closure_vars = set()
    for m in re.finditer(r'use\s*\(([^)]*)\)', raw):
        closure_vars |= set(re.findall(r'\$(\w+)', m.group(1)))
    for m in re.finditer(r'^\s*global\s+([^;]+);', raw, re.M):
        closure_vars |= set(re.findall(r'\$(\w+)', m.group(1)))

    # variabel & fungsi tersedia sebelum baris tertentu
    def avail(before_line):
        v, f = set(closure_vars), set()
        for ln, t in incs:
            if ln <= before_line:
                v |= closure(t, provided)
                f |= closure(t, funcs)
        return v, f
    # cek pemakaian $pdo/$usersPdo
    for i, line in enumerate(scan, 1):
        if line.strip().startswith(('//','*','#')): continue
        if re.search(r'\$pdo\s*=', line) or re.search(r'\$usersPdo\s*=', line): continue
        for var in ('pdo','usersPdo'):
            if re.search(r'\$'+var+r'\b', line):
                v,_ = avail(i)
                if var not in v:
                    print(f'  [VAR]  {page}:{i}  ${var} belum tersedia')
                    problems += 1
                    break
    # cek pemanggilan fungsi milik aplikasi
    all_funcs = set().union(*funcs.values()) if funcs else set()
    for i, line in enumerate(scan, 1):
        if line.strip().startswith(('//','*','#')): continue
        for fn in re.findall(r'(?<![\w$>:])([a-z_][a-z0-9_]{2,})\s*\(', line):
            if fn not in all_funcs: continue
            _, f = avail(i)
            if fn not in f:
                print(f'  [FUNC] {page}:{i}  {fn}() belum tersedia')
                problems += 1
print('HASIL:', 'OK' if problems == 0 else f'{problems} masalah')
