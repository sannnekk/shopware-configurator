import mysql from 'mysql2/promise'
import path from 'node:path'
import {
	IdentityMap,
	ensureDirectory,
	formatDuration,
	now,
	resolvePath,
	uuidToBuffer,
	writeJson,
} from './utils.js'
import { createTransformers } from './transformers.js'

const DEFAULT_CHUNK_SIZE = 200

export class ConfiguratorMigrator {
	constructor(config, logger, options = {}) {
		this.config = config
		this.logger = logger
		this.options = {
			dryRun: false,
			truncate: false,
			limit: null,
			snapshots: true,
			chunkSize: DEFAULT_CHUNK_SIZE,
			...options,
		}

		this.identityMap = new IdentityMap()
		this.productMap = new Map()
		this.priceTierIndex = new Map()
		this.optionCostIndex = new Map()
		this.availableLanguageIds = new Set()
		this.transformers = null

		this.stats = {
			processed: 0,
			written: 0,
			skipped: 0,
		}
	}

	async run(runtimeOptions = {}) {
		this.options = { ...this.options, ...runtimeOptions }
		const start = now()
		const summaryStage = this.logger.stage('Configurator migration')

		try {
			await this.prepareFileSystem()
			await this.connect()
			await this.prepareContext()
			await this.executeMigration()
			summaryStage.succeed(`Done in ${formatDuration(start)}`)
		} catch (error) {
			summaryStage.fail('Migration failed')
			if (error instanceof Error) {
				this.logger.error(error)
			} else {
				this.logger.error(String(error))
			}
			throw error
		} finally {
			await this.cleanup()
		}

		return this.stats
	}

	async prepareFileSystem() {
		const stage = this.logger.stage('Preparing filesystem')

		try {
			const outputDir = resolvePath(this.config.outputFolder ?? './output')
			const snapshotsDir = path.join(outputDir, 'snapshots')

			await ensureDirectory(outputDir)
			await ensureDirectory(snapshotsDir)

			stage.succeed()
		} catch (error) {
			stage.fail('Unable to prepare filesystem')
			throw error
		}
	}

	async connect() {
		const stage = this.logger.stage('Opening database connections')

		try {
			this.legacyPool = mysql.createPool({
				...this.config.oldDb,
				waitForConnections: true,
				connectionLimit: 5,
				namedPlaceholders: false,
				decimalNumbers: true,
			})

			this.targetPool = mysql.createPool({
				...this.config.newDb,
				waitForConnections: true,
				connectionLimit: 5,
				namedPlaceholders: false,
				decimalNumbers: true,
			})

			this.targetConnection = await this.targetPool.getConnection()

			stage.succeed('Connections ready')
		} catch (error) {
			stage.fail('Unable to connect to databases')
			throw error
		}
	}

	async prepareContext() {
		const stage = this.logger.stage('Loading supporting data')

		try {
			const productCount = await this.buildProductMap()
			const languageCount = await this.buildLanguageSet()
			const priceTierCount = await this.buildPriceTierIndex()
			const optionCostCount = await this.buildOptionCostIndex()
			this.transformers = createTransformers({
				config: this.config,
				identityMap: this.identityMap,
				productMap: this.productMap,
				priceTierIndex: this.priceTierIndex,
				optionCostIndex: this.optionCostIndex,
				logger: this.logger,
				availableLanguages: this.availableLanguageIds,
			})
			stage.succeed(
				`${productCount} product(s) mapped, ${languageCount} language(s) detected, ${priceTierCount} price tier group(s) prepared, ${optionCostCount} option cost group(s) prepared`
			)
		} catch (error) {
			stage.fail('Unable to load supporting data')
			throw error
		}
	}

	async executeMigration() {
		const stage = this.logger.stage('Executing migration')

		try {
			if (!this.transformers) {
				throw new Error('Migration transformers not initialised')
			}

			if (this.options.truncate && !this.options.dryRun) {
				await this.truncateTargetTables()
			}

			if (!this.options.dryRun) {
				await this.targetConnection.beginTransaction()
			}

			for (const [sourceTable, targetTable] of Object.entries(
				this.config.entityMap ?? {}
			)) {
				await this.migrateEntity(sourceTable, targetTable)
			}

			if (!this.options.dryRun) {
				await this.targetConnection.commit()
			}

			stage.succeed()
		} catch (error) {
			if (!this.options.dryRun && this.targetConnection) {
				await this.targetConnection.rollback()
			}

			stage.fail('Migration aborted')
			throw error
		}
	}

	async migrateEntity(sourceTable, targetTable) {
		const stage = this.logger.stage(`Migrating ${sourceTable}`)

		if (!targetTable) {
			stage.skip('No target table configured')
			this.stats.skipped += 1
			return
		}

		try {
			const rows = await this.loadSourceRows(sourceTable)
			stage.update(`${rows.length} row(s) loaded`)

			if (!rows.length) {
				stage.skip('Nothing to migrate')
				return
			}

			const { primary, related } = this.transformers.transform(
				sourceTable,
				targetTable,
				rows
			)

			if (this.options.snapshots) {
				await this.writeSnapshots(sourceTable, {
					raw: rows,
					mapped: primary,
					related,
				})
			}

			if (this.options.dryRun) {
				stage.succeed(`${primary.length} row(s) processed (dry run)`)
				this.stats.processed += primary.length
				return
			}

			const writtenPrimary = await this.writeRows(targetTable, primary)
			let writtenRelated = 0

			for (const attachment of related ?? []) {
				writtenRelated += await this.writeRows(
					attachment.table,
					attachment.rows
				)
			}

			stage.succeed(`${writtenPrimary + writtenRelated} row(s) written`)
			this.stats.processed += primary.length
			this.stats.written += writtenPrimary + writtenRelated
		} catch (error) {
			stage.fail('Failed')
			throw error
		}
	}

	async loadSourceRows(sourceTable) {
		const limitClause = this.options.limit ? ' LIMIT ?' : ''
		let query
		let params = []

		switch (sourceTable) {
			case 'ott_printconfig_print_positions':
				query = [
					'SELECT p.*, sad.ordernumber AS product_number',
					'FROM `ott_printconfig_print_positions` p',
					'LEFT JOIN `s_articles` a ON a.id = p.article_id',
					'LEFT JOIN `s_articles_details` sad ON sad.id = a.main_detail_id',
					'ORDER BY p.id',
				].join('\n')
				break
			default:
				query = `SELECT * FROM \`${sourceTable}\` ORDER BY id`
		}

		if (this.options.limit) {
			query = `${query}${limitClause}`
			params = [this.options.limit]
		}

		try {
			const [rows] = await this.legacyPool.query(query, params)
			return rows
		} catch (error) {
			this.logger.error(`Unable to load data from ${sourceTable}`)
			throw error
		}
	}

	async buildProductMap() {
		const [rows] = await this.targetConnection.query(
			'SELECT `id`, `product_number` FROM `product`'
		)

		this.productMap.clear()

		for (const row of rows) {
			if (!row.product_number) {
				continue
			}

			const productId = Buffer.isBuffer(row.id)
				? row.id
				: uuidToBuffer(String(row.id))

			if (!productId) {
				continue
			}

			this.productMap.set(row.product_number, productId)
		}

		return this.productMap.size
	}

	async buildLanguageSet() {
		const [rows] = await this.targetConnection.query(
			'SELECT `id` FROM `language`'
		)

		this.availableLanguageIds.clear()

		for (const row of rows) {
			const languageId = Buffer.isBuffer(row.id)
				? row.id
				: uuidToBuffer(String(row.id))

			if (!languageId) {
				continue
			}

			this.availableLanguageIds.add(languageId.toString('hex'))
		}

		return this.availableLanguageIds.size
	}

	async buildPriceTierIndex() {
		const [rows] = await this.legacyPool.query(
			`SELECT
				o.print_method_id,
				o.quantity_up,
				o.quantity_to,
				o.surcharge_per_piece
			FROM ott_printconfig_add_costs o
			ORDER BY o.print_method_id, o.quantity_up, o.quantity_to`
		)

		this.priceTierIndex.clear()

		for (const row of rows) {
			const legacyMethodId = row.print_method_id ?? row.printMethodId

			if (legacyMethodId === undefined || legacyMethodId === null) {
				continue
			}

			const key = String(legacyMethodId)
			const collection = this.priceTierIndex.get(key) ?? []
			const quantityStartRaw = row.quantity_up ?? row.quantityUp
			const quantityEndRaw = row.quantity_to ?? row.quantityTo
			const priceRaw = row.surcharge_per_piece ?? row.surchargePerPiece

			const quantityStart = Number.isFinite(Number(quantityStartRaw))
				? Number(quantityStartRaw)
				: null
			const quantityEnd = Number.isFinite(Number(quantityEndRaw))
				? Number(quantityEndRaw)
				: null
			const price = Number.isFinite(Number(priceRaw)) ? Number(priceRaw) : 0

			collection.push({
				quantityStart,
				quantityEnd,
				price,
			})

			this.priceTierIndex.set(key, collection)
		}

		return this.priceTierIndex.size
	}

	async buildOptionCostIndex() {
		const [rows] = await this.legacyPool.query(
			`SELECT 
				m.id,
				m.print_position_id,
				m.setup_costs_per_color,
				m.setup_costs_once,
				m.film_costs_per_color,
				m.film_costs_once
			FROM ott_printconfig_print_methods m`
		)

		this.optionCostIndex.clear()

		for (const row of rows) {
			const methodId =
				row.id ??
				row.print_method_id ??
				row.printMethodId ??
				row.method_id ??
				row.methodId ??
				row.print_position_method_id ??
				row.printPositionMethodId

			if (methodId === undefined || methodId === null) {
				continue
			}

			const setupPerColorRaw =
				row.setup_costs_per_color ??
				row.setupCostsPerColor ??
				row.setup_per_color
			const setupOnceRaw =
				row.setup_costs_once ?? row.setupCostsOnce ?? row.setup_once
			const filmPerColorRaw =
				row.film_costs_per_color ?? row.filmCostsPerColor ?? row.film_per_color
			const filmOnceRaw =
				row.film_costs_once ?? row.filmCostsOnce ?? row.film_once

			const normalize = (value) =>
				Number.isFinite(Number(value)) ? Number(value) : null

			this.optionCostIndex.set(String(methodId), {
				fieldId: row.print_position_id ?? row.printPositionId ?? null,
				setupPerColor: normalize(setupPerColorRaw),
				setupOnce: normalize(setupOnceRaw),
				filmPerColor: normalize(filmPerColorRaw),
				filmOnce: normalize(filmOnceRaw),
			})
		}

		return this.optionCostIndex.size
	}

	async writeRows(table, rows = []) {
		if (!rows.length) {
			return 0
		}

		const chunkSize = this.options.chunkSize ?? DEFAULT_CHUNK_SIZE
		let written = 0

		for (let i = 0; i < rows.length; i += chunkSize) {
			const chunk = rows.slice(i, i + chunkSize)
			await this.insertChunk(table, chunk)
			written += chunk.length
		}

		return written
	}

	async insertChunk(table, rows) {
		const columns = Object.keys(rows[0])
		const placeholders = `(${columns.map(() => '?').join(', ')})`
		const updateColumns = columns.filter(
			(column) => column !== 'created_at' && column !== 'id'
		)

		const values = []

		for (const row of rows) {
			for (const column of columns) {
				values.push(row[column] ?? null)
			}
		}

		const assignments = updateColumns
			.map((column) => `\`${column}\` = VALUES(\`${column}\`)`)
			.join(', ')

		const sql = `${
			assignments ? 'INSERT INTO' : 'INSERT IGNORE INTO'
		} \`${table}\` (${columns
			.map((column) => `\`${column}\``)
			.join(', ')}) VALUES ${rows.map(() => placeholders).join(', ')}${
			assignments ? ` ON DUPLICATE KEY UPDATE ${assignments}` : ''
		}`

		await this.targetConnection.query(sql, values)
	}

	async truncateTargetTables() {
		const stage = this.logger.stage('Truncating target tables')

		try {
			const tables = new Set()

			for (const targetTable of Object.values(this.config.entityMap ?? {})) {
				if (!targetTable) {
					continue
				}

				tables.add(targetTable)
				tables.add(`${targetTable}_translation`)
			}

			await this.targetConnection.query('SET FOREIGN_KEY_CHECKS=0')

			for (const table of tables) {
				await this.targetConnection.query(`TRUNCATE TABLE \`${table}\``)
			}

			await this.targetConnection.query('SET FOREIGN_KEY_CHECKS=1')
			stage.succeed()
		} catch (error) {
			stage.fail('Unable to truncate tables')
			throw error
		}
	}

	async writeSnapshots(sourceTable, payload) {
		try {
			const outputDir = resolvePath(this.config.outputFolder ?? './output')
			const snapshotDir = path.join(outputDir, 'snapshots', sourceTable)

			await ensureDirectory(snapshotDir)
			await writeJson(path.join(snapshotDir, 'raw.json'), payload.raw ?? [])
			await writeJson(
				path.join(snapshotDir, 'mapped.json'),
				payload.mapped ?? []
			)
			await writeJson(
				path.join(snapshotDir, 'related.json'),
				payload.related ?? []
			)
		} catch (error) {
			this.logger.warn(
				`Failed to write snapshots for ${sourceTable}: ${error.message}`
			)
		}
	}

	async cleanup() {
		if (this.targetConnection) {
			this.targetConnection.release()
		}

		if (this.legacyPool) {
			await this.legacyPool.end()
		}

		if (this.targetPool) {
			await this.targetPool.end()
		}
	}
}
