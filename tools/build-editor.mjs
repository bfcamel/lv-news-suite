import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const dir = path.join(root, 'src/editor');
const files = fs.readdirSync(dir).filter(name => name.endsWith('.js.inc')).sort();
const output = files.map(name => fs.readFileSync(path.join(dir, name), 'utf8')).join('');
fs.writeFileSync(path.join(root, 'assets/editor.js'), output);
console.log('Built editor.js from ' + files.length + ' ordered source sections.');
