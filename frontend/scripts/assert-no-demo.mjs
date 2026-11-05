// Fails if any production bundle in dist/ contains demo-mode code or sample data.
// Demo mode (npm run demo) must stay development-only — see docs/DEMO-UI.md.
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

const MARKERS = ['sitex.demo.db', 'sitex.demo.settings', 'demo_gateway', '.demo.example']
const root = fileURLToPath(new URL('../dist/', import.meta.url))
const hits = []
function walk(dir) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name)
    if (statSync(p).isDirectory()) walk(p)
    else if (/\.(js|html|css|map)$/.test(name)) {
      const text = readFileSync(p, 'utf8')
      for (const m of MARKERS) if (text.includes(m)) hits.push(`${p}: ${m}`)
    }
  }
}
try {
  walk(root)
} catch (e) {
  console.error(`assert-no-demo: cannot read ${root} – run the build first.`, e.message)
  process.exit(1)
}
if (hits.length) {
  console.error('Demo code found in production build:\n' + hits.join('\n'))
  process.exit(1)
}
console.log('assert-no-demo: OK – no demo code in dist/')
