import { generateUuidBuffer, nowSql, uuidToBuffer } from './utils.js'

const translationForeignKeyByTarget = {
	hmnet_configurator_field: 'hmnet_configurator_field_id',
	hmnet_configurator_option: 'hmnet_configurator_option_id',
	hmnet_configurator_option_possibility:
		'hmnet_configurator_option_possibility_id',
}

function pickFirstDefined(row, keys, defaultValue = null) {
	for (const key of keys) {
		if (key in row && row[key] !== undefined && row[key] !== null) {
			return row[key]
		}
	}

	return defaultValue
}

function normalizeBoolean(value, defaultValue = 0) {
	if (value === undefined || value === null) {
		return defaultValue ? 1 : 0
	}

	if (typeof value === 'boolean') {
		return value ? 1 : 0
	}

	if (typeof value === 'number') {
		return value > 0 ? 1 : 0
	}

	const normalized = String(value).trim().toLowerCase()
	return normalized === '1' || normalized === 'true' ? 1 : 0
}

function normalizeNumber(value, defaultValue = null) {
	if (value === undefined || value === null || value === '') {
		return defaultValue
	}

	const numberValue = Number(value)
	return Number.isFinite(numberValue) ? numberValue : defaultValue
}

function normalizeJson(value, defaultValue = []) {
	if (value === undefined || value === null || value === '') {
		return JSON.stringify(defaultValue)
	}

	if (typeof value === 'string') {
		try {
			return JSON.stringify(JSON.parse(value))
		} catch (error) {
			return JSON.stringify(defaultValue)
		}
	}

	return JSON.stringify(value)
}

function normalizeDateTime(value, fallback) {
	if (!value) {
		return fallback
	}

	if (value instanceof Date) {
		return value.toISOString().replace('T', ' ').substring(0, 23)
	}

	if (typeof value === 'number') {
		return new Date(value).toISOString().replace('T', ' ').substring(0, 23)
	}

	const stringValue = String(value).trim().replace('T', ' ')

	if (stringValue.length === 19) {
		return `${stringValue}.000`
	}

	return stringValue.substring(0, 23)
}

function serializePriceTiers(entries, logger, referenceId) {
	if (!Array.isArray(entries) || !entries.length) {
		return JSON.stringify([])
	}

	const normalized = []

	for (const entry of entries) {
		const quantityStart = entry.quantityStart ?? null
		const quantityEnd = entry.quantityEnd ?? null
		const priceRaw = entry.price ?? 0
		const price = Number.isFinite(Number(priceRaw)) ? Number(priceRaw) : 0

		if (!Number.isFinite(price)) {
			if (logger) {
				logger.warn(
					`Ignoring invalid price tier price for legacy id ${referenceId}: ${priceRaw}`
				)
			}
			continue
		}

		normalized.push({
			quantityStart:
				quantityStart === null || Number.isNaN(Number(quantityStart))
					? null
					: Number(quantityStart),
			quantityEnd:
				quantityEnd === null || Number.isNaN(Number(quantityEnd))
					? null
					: Number(quantityEnd),
			price,
		})
	}

	return JSON.stringify(normalized)
}

function extractTranslations(row, field) {
	const translations = {}

	if (row.translations && typeof row.translations === 'object') {
		for (const [locale, value] of Object.entries(row.translations)) {
			if (value && typeof value === 'object') {
				if (field in value) {
					translations[locale] = value[field]
				} else if ('value' in value) {
					translations[locale] = value.value
				}
				continue
			}

			translations[locale] = value
		}
	}

	const pattern = new RegExp(`^${field}[-_]?([a-z]{2}(?:-[a-z]{2})?)$`, 'i')

	for (const [key, value] of Object.entries(row)) {
		const match = key.match(pattern)

		if (!match || match.length < 2) {
			continue
		}

		translations[normalizeLocale(match[1])] = value
	}

	if (row[field] !== undefined && row[field] !== null) {
		translations.default = row[field]
	}

	return translations
}

function normalizeLocale(locale) {
	return locale.includes('-')
		? `${locale.substring(0, 2).toLowerCase()}-${locale
				.substring(3, 5)
				.toUpperCase()}`
		: locale.substring(0, 2).toLowerCase()
}

function buildTranslationRows({
	targetTable,
	sourceRows,
	languageMap,
	identityMap,
	sourceKey,
	field,
}) {
	const foreignKey = translationForeignKeyByTarget[targetTable]

	if (!foreignKey) {
		return []
	}

	const translationTable = `${targetTable}_translation`
	const now = nowSql()
	const rows = []

	for (const sourceRow of sourceRows) {
		const translations = extractTranslations(sourceRow, field)
		const primaryId = identityMap.get(sourceKey, sourceRow.id)

		if (!primaryId) {
			continue
		}

		for (const [locale, { languageId, enabled }] of languageMap.entries()) {
			if (!enabled) {
				continue
			}

			const normalizedLocale = normalizeLocale(locale)
			let translationValue =
				translations[locale] ??
				translations[normalizedLocale] ??
				translations[locale.substring(0, 2)] ??
				translations.default

			if (translationValue === undefined) {
				continue
			}

			const trimmedValue =
				translationValue === null ? null : String(translationValue).trim()

			if (trimmedValue === '') {
				continue
			}

			rows.push({
				[foreignKey]: primaryId,
				language_id: languageId,
				[field]: trimmedValue,
				created_at: now,
				updated_at: null,
			})
		}
	}

	if (!rows.length) {
		return []
	}

	return [
		{
			table: translationTable,
			rows,
		},
	]
}

export function createTransformers({
	config,
	identityMap,
	productMap,
	priceTierIndex,
	fieldCostIndex,
	logger,
	availableLanguages,
}) {
	const languageMap = new Map()
	const productIndex = productMap ?? new Map()
	const priceTierGroups = priceTierIndex ?? new Map()
	const fieldCostGroups = fieldCostIndex ?? new Map()
	const missingProductNumbers = new Set()
	const missingFieldRefs = new Set()
	const missingOptionRefs = new Set()
	const missingLanguageRefs = new Set()
	const availableLanguageHex = new Set()

	if (availableLanguages && availableLanguages.size) {
		for (const languageId of availableLanguages) {
			if (!languageId) {
				continue
			}

			if (Buffer.isBuffer(languageId)) {
				availableLanguageHex.add(languageId.toString('hex'))
				continue
			}

			availableLanguageHex.add(String(languageId))
		}
	}

	for (const [locale, definition] of Object.entries(
		config.translations ?? {}
	)) {
		if (!definition.languageId) {
			continue
		}

		const languageIdBuffer = uuidToBuffer(definition.languageId)

		if (!languageIdBuffer) {
			if (logger) {
				logger.warn(
					`Skipping translation locale ${locale} because language id ${definition.languageId} is invalid`
				)
			}
			continue
		}

		const languageHex = languageIdBuffer.toString('hex')

		if (availableLanguageHex.size && !availableLanguageHex.has(languageHex)) {
			if (logger && !missingLanguageRefs.has(languageHex)) {
				logger.warn(
					`Skipping translation locale ${locale} because language ${definition.languageId} does not exist in the target shop`
				)
				missingLanguageRefs.add(languageHex)
			}
			continue
		}

		languageMap.set(locale, {
			languageId: languageIdBuffer,
			enabled: definition.enabled !== false,
		})
	}

	const defaultCreatedAt = nowSql()

	const transformers = {
		ott_printconfig_print_positions(sourceRows) {
			const rows = []

			for (const row of sourceRows) {
				const createdAt = normalizeDateTime(row.created_at, defaultCreatedAt)
				const updatedAt = row.updated_at
					? normalizeDateTime(row.updated_at, null)
					: null

				const productNumber = pickFirstDefined(row, [
					'product_number',
					'productNumber',
					'ordernumber',
					'orderNumber',
				])
				let productId = null

				if (productNumber) {
					productId = productIndex.get(productNumber) ?? null

					if (
						!productId &&
						logger &&
						!missingProductNumbers.has(productNumber)
					) {
						logger.warn(
							`No product could be resolved for product number ${productNumber}`
						)
						missingProductNumbers.add(productNumber)
					}
				}

				if (!productId) {
					const productReference = pickFirstDefined(row, [
						'product_id',
						'productId',
						'product_uuid',
						'productUuid',
					])

					if (Buffer.isBuffer(productReference)) {
						productId = productReference
					} else if (typeof productReference === 'string') {
						const sanitized = productReference.replace(/[^a-f0-9-]/gi, '')
						if (sanitized.length === 32 || sanitized.length === 36) {
							productId = uuidToBuffer(sanitized)
						}
					}
				}

				if (!productId) {
					continue
				}

				const newId = identityMap.resolve(
					'ott_printconfig_print_positions',
					row.id,
					generateUuidBuffer
				)

				const fieldCosts = fieldCostGroups.get(String(row.id)) ?? null
				const setupPrice =
					fieldCosts?.setup ??
					normalizeNumber(pickFirstDefined(row, ['setup_price', 'setupPrice']))
				const filmPrice =
					fieldCosts?.film ??
					normalizeNumber(pickFirstDefined(row, ['film_price', 'filmPrice']))

				rows.push({
					id: newId,
					product_id: productId,
					position: normalizeNumber(
						pickFirstDefined(row, ['position', 'sort', 'sort_order'])
					),
					is_required: normalizeBoolean(
						pickFirstDefined(row, [
							'is_required',
							'isRequired',
							'required',
							'preselection',
						]),
						0
					),
					is_visible: normalizeBoolean(
						pickFirstDefined(row, ['is_visible', 'isVisible', 'visible']),
						1
					),
					setup_price: setupPrice,
					film_price: filmPrice,
					created_at: createdAt,
					updated_at: updatedAt,
				})
			}

			const related = buildTranslationRows({
				targetTable: 'hmnet_configurator_field',
				sourceRows,
				languageMap,
				identityMap,
				sourceKey: 'ott_printconfig_print_positions',
				field: 'name',
			})

			return {
				primary: rows,
				related,
			}
		},
		ott_printconfig_print_methods(sourceRows) {
			const rows = []

			for (const row of sourceRows) {
				const createdAt = normalizeDateTime(row.created_at, defaultCreatedAt)
				const updatedAt = row.updated_at
					? normalizeDateTime(row.updated_at, null)
					: null

				const legacyFieldId = pickFirstDefined(row, [
					'field_id',
					'print_position_id',
					'position_id',
				])
				const mappedFieldId =
					legacyFieldId !== undefined
						? identityMap.get('ott_printconfig_print_positions', legacyFieldId)
						: null

				if (!mappedFieldId) {
					if (
						logger &&
						legacyFieldId !== undefined &&
						!missingFieldRefs.has(legacyFieldId)
					) {
						logger.warn(
							`Skipping print method ${row.id} because field reference ${legacyFieldId} could not be resolved`
						)
						missingFieldRefs.add(legacyFieldId)
					}
					continue
				}

				const newId = identityMap.resolve(
					'ott_printconfig_print_methods',
					row.id,
					generateUuidBuffer
				)

				rows.push({
					id: newId,
					field_id: mappedFieldId,
					position: normalizeNumber(
						pickFirstDefined(row, ['position', 'sort', 'sort_order'])
					),
					price_tiers: serializePriceTiers(
						priceTierGroups.get(String(row.id)) ?? [],
						logger,
						row.id
					),
					created_at: createdAt,
					updated_at: updatedAt,
				})
			}

			const related = buildTranslationRows({
				targetTable: 'hmnet_configurator_option',
				sourceRows,
				languageMap,
				identityMap,
				sourceKey: 'ott_printconfig_print_methods',
				field: 'name',
			})

			return {
				primary: rows,
				related,
			}
		},
		ott_printconfig_print_options(sourceRows) {
			const rows = []

			for (const row of sourceRows) {
				const createdAt = normalizeDateTime(row.created_at, defaultCreatedAt)
				const updatedAt = row.updated_at
					? normalizeDateTime(row.updated_at, null)
					: null

				const legacyOptionId = pickFirstDefined(row, [
					'option_id',
					'print_method_id',
					'method_id',
				])
				const mappedOptionId =
					legacyOptionId !== undefined
						? identityMap.get('ott_printconfig_print_methods', legacyOptionId)
						: null

				if (!mappedOptionId) {
					if (
						logger &&
						legacyOptionId !== undefined &&
						!missingOptionRefs.has(legacyOptionId)
					) {
						logger.warn(
							`Skipping print option ${row.id} because method reference ${legacyOptionId} could not be resolved`
						)
						missingOptionRefs.add(legacyOptionId)
					}
					continue
				}

				const newId = identityMap.resolve(
					'ott_printconfig_print_options',
					row.id,
					generateUuidBuffer
				)

				rows.push({
					id: newId,
					option_id: mappedOptionId,
					position:
						normalizeNumber(
							pickFirstDefined(row, ['position', 'sort', 'sort_order']),
							0
						) ?? 0,
					multiplicator:
						normalizeNumber(
							pickFirstDefined(row, ['multiplicator', 'multiplier']),
							1
						) ?? 1,
					created_at: createdAt,
					updated_at: updatedAt,
				})
			}

			const related = buildTranslationRows({
				targetTable: 'hmnet_configurator_option_possibility',
				sourceRows,
				languageMap,
				identityMap,
				sourceKey: 'ott_printconfig_print_options',
				field: 'name',
			})

			return {
				primary: rows,
				related,
			}
		},
	}

	function fallbackTransformer(sourceRows, targetTable) {
		return {
			primary: sourceRows.map((row) => ({
				...row,
				created_at: row.created_at ?? defaultCreatedAt,
				updated_at: row.updated_at ?? null,
			})),
			related: buildTranslationRows({
				targetTable,
				sourceRows,
				languageMap,
				identityMap,
				sourceKey: targetTable,
				field: 'name',
			}),
		}
	}

	return {
		transform(entityKey, targetTable, rows) {
			const transformer = transformers[entityKey]

			if (!transformer) {
				return fallbackTransformer(rows, targetTable)
			}

			return transformer(rows, targetTable)
		},
	}
}
