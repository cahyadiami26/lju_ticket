import glob, sys
import tree_sitter_php as tsp
from tree_sitter import Language, Parser

lang = Language(tsp.language_php())
parser = Parser(lang)

def errors(node, out):
    if node.type == 'ERROR' or node.is_missing:
        out.append(node)
    for ch in node.children:
        errors(ch, out)

files = sorted([f for f in glob.glob('**/*.php', recursive=True) if not f.startswith('library/')])
bad = 0
for f in files:
    src = open(f, 'rb').read()
    tree = parser.parse(src)
    out = []
    errors(tree.root_node, out)
    if out:
        bad += 1
        print(f'\n{f}: {len(out)} node bermasalah')
        for n in out[:3]:
            line = src[:n.start_byte].count(b'\n') + 1
            snippet = src[n.start_byte:n.end_byte][:120].decode('utf-8', 'replace').replace('\n', ' ⏎ ')
            print(f'   baris {line} [{n.type}] -> {snippet}')
print(f'\n{len(files)} file diperiksa — ' + ('SEMUA BERSIH' if bad == 0 else f'{bad} file bermasalah'))
