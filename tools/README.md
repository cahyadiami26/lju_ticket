# Tools Pemeriksaan Statis

Tidak ada PHP di sandbox pengembangan, jadi kode divalidasi dengan tiga skrip Python.
Opsional — tidak dibutuhkan di server produksi.

## Persiapan (sekali)

```bash
python3 -m venv /tmp/phpenv
/tmp/phpenv/bin/pip install tree_sitter tree_sitter_php
```

## Menjalankan

```bash
python3 tools/lint_includes.py                     # fungsi & $pdo tersedia di setiap halaman
/tmp/phpenv/bin/python tools/lint_syntax.py        # sintaks semua file PHP (tree-sitter)
/tmp/phpenv/bin/python tools/lint_unwrapped_queries.py   # query DB tanpa try/catch
```

`lint_includes.py` membaca graph `require_once` dan memastikan setiap halaman memuat
semua fungsi/variabel yang dipakainya — inilah pencegah kelas bug
"Call to undefined function is_it()".
