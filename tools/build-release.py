"""Build a WordPress upload ZIP containing only runtime files and documentation."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import hashlib
import re

root = Path(__file__).resolve().parents[1]
version = re.search(r"Version: ([0-9.]+)", (root / 'lv-news-suite.php').read_text()).group(1)
output = root / 'dist'
output.mkdir(exist_ok=True)
archive = output / f'lv-news-suite-{version}.zip'
files = [root / name for name in ['lv-news-suite.php', 'readme.txt', 'README.md']]
for folder in ['includes', 'assets', 'docs']:
    files.extend(p for p in (root / folder).rglob('*') if p.is_file())
with ZipFile(archive, 'w', ZIP_DEFLATED) as z:
    for p in sorted(files):
        z.write(p, 'lv-news-suite/' + p.relative_to(root).as_posix())
checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
(archive.with_suffix('.sha256')).write_text(f'{checksum}  {archive.name}\n')
print(f'Built {archive.name}: {len(files)} files, SHA-256 {checksum}')
