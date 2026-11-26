import glob
import tree_sitter_php as tsp
from tree_sitter import Language, Parser
lang = Language(tsp.language_php()); parser = Parser(lang)

def inside_try(node):
    n = node.parent
    while n:
        if n.type == 'try_statement':
            # pastikan node ada di body, bukan di catch/finally
            body = [c for c in n.children if c.type == 'compound_statement']
            if body and node.start_byte >= body[0].start_byte and node.end_byte <= body[0].end_byte:
                return True
        n = n.parent
    return False

def walk(node):
    yield node
    for c in node.children:
        yield from walk(c)

rows = []
for f in sorted(glob.glob('**/*.php', recursive=True)):
    if f.startswith(('library/', 'database/')): continue
    src = open(f,'rb').read()
    tree = parser.parse(src)
    for n in walk(tree.root_node):
        if n.type != 'member_call_expression': continue
        obj = n.child_by_field_name('object')
        name = n.child_by_field_name('name')
        if not obj or not name: continue
        otxt = src[obj.start_byte:obj.end_byte].decode()
        ntxt = src[name.start_byte:name.end_byte].decode()
        if otxt not in ('$pdo','$usersPdo'): continue
        if ntxt not in ('query','prepare','exec'): continue
        if inside_try(n): continue
        # cari statement pembungkusnya
        stmt = n
        while stmt.parent and stmt.parent.type not in ('expression_statement','compound_statement','if_statement','foreach_statement','while_statement','return_statement','assignment_expression'):
            stmt = stmt.parent
        txt = src[stmt.start_byte:stmt.end_byte].decode('utf-8','replace')
        rows.append((f, src[:n.start_byte].count(b'\n')+1, ntxt, ' '.join(txt.split())[:110]))

print(f'query tanpa try/catch: {len(rows)}')
for r in rows: print(f'{r[0]}:{r[1]} [{r[2]}] {r[3]}')
