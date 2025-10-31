import { randomUUID } from 'node:crypto'
import { mkdir, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const projectRoot = path.resolve(__dirname, '..')

export function resolvePath(relativePath) {
	return path.resolve(projectRoot, relativePath)
}

export function ensureDirectory(directoryPath) {
	return mkdir(directoryPath, { recursive: true })
}

export async function writeJson(filePath, data) {
	await ensureDirectory(path.dirname(filePath))
	await writeFile(filePath, `${JSON.stringify(data, null, 2)}\n`, 'utf8')
}

export function uuidToBuffer(uuid) {
	if (!uuid) {
		return null
	}

	if (Buffer.isBuffer(uuid)) {
		return uuid
	}

	let normalized = String(uuid).trim()

	if (normalized.startsWith('0x') || normalized.startsWith('0X')) {
		normalized = normalized.substring(2)
	}

	const cleanUuid = normalized.replace(/-/g, '')

	if (!cleanUuid || cleanUuid.length % 2 !== 0) {
		return null
	}

	try {
		return Buffer.from(cleanUuid, 'hex')
	} catch (error) {
		return null
	}
}

export function bufferToUuid(buffer) {
	if (!buffer) {
		return null
	}

	const hex = buffer.toString('hex')
	return `${hex.substring(0, 8)}-${hex.substring(8, 12)}-${hex.substring(
		12,
		16
	)}-${hex.substring(16, 20)}-${hex.substring(20)}`
}

export function generateUuidBuffer() {
	return uuidToBuffer(randomUUID())
}

export function now() {
	return new Date()
}

export function nowSql() {
	const date = new Date()
	const iso = date.toISOString()
	return iso.replace('T', ' ').substring(0, 23)
}

export function formatDuration(start, end = new Date()) {
	const duration = end.getTime() - start.getTime()
	const seconds = Math.round(duration / 1000)

	if (seconds < 60) {
		return `${seconds}s`
	}

	const minutes = Math.floor(seconds / 60)
	const remainingSeconds = seconds % 60

	return `${minutes}m ${remainingSeconds}s`
}

export class IdentityMap {
	constructor() {
		this.map = new Map()
	}

	keyFor(entity, legacyId) {
		return `${entity}::${legacyId ?? 'null'}`
	}

	has(entity, legacyId) {
		return this.map.has(this.keyFor(entity, legacyId))
	}

	get(entity, legacyId) {
		return this.map.get(this.keyFor(entity, legacyId))
	}

	set(entity, legacyId, value) {
		this.map.set(this.keyFor(entity, legacyId), value)
		return value
	}

	resolve(entity, legacyId, fallbackFactory) {
		if (this.has(entity, legacyId)) {
			return this.get(entity, legacyId)
		}

		const value = fallbackFactory
			? fallbackFactory(legacyId)
			: generateUuidBuffer()
		return this.set(entity, legacyId, value)
	}
}
