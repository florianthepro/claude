import { randomBytes } from 'node:crypto'

// 32-byte key (base64) for KEK_BASE64.
process.stdout.write(randomBytes(32).toString('base64') + '\n')
