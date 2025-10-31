#!/usr/bin/env node
import { Command } from 'commander'
import { config } from './config.js'
import { ConfiguratorMigrator } from './lib/migrator.js'
import { createLogger } from './lib/logger.js'

const program = new Command()

program
	.name('hmnet-configurator-migrator')
	.description(
		'Copies configurator data from a legacy shop into the current Shopware instance.'
	)
	.option('--dry-run', 'Process data without writing to the target database')
	.option('--truncate', 'Truncate target tables before inserting migrated data')
	.option(
		'--erase-target',
		'Erase target configurator tables before migration starts'
	)
	.option(
		'--limit <number>',
		'Limit the number of rows fetched per entity',
		(value) => parseInt(value, 10)
	)
	.option(
		'--chunk-size <number>',
		'Number of rows per insert batch',
		(value) => parseInt(value, 10),
		200
	)
	.option('--no-spinner', 'Disable spinner animations in the terminal output')
	.option('--no-snapshots', 'Skip writing JSON snapshots to the output folder')
	.parse(process.argv)

const options = program.opts()
const logger = createLogger({ useSpinner: options.spinner })

const migrator = new ConfiguratorMigrator(config, logger, {
	snapshots: options.snapshots,
	chunkSize: options.chunkSize,
	truncate: config.eraseTargetTables === true,
})

async function main() {
	try {
		const eraseTargetSource = program.getOptionValueSource('eraseTarget')
		const truncateSource = program.getOptionValueSource('truncate')

		let eraseTarget = config.eraseTargetTables === true

		if (truncateSource) {
			eraseTarget = Boolean(options.truncate)
		}

		if (eraseTargetSource) {
			eraseTarget = Boolean(options.eraseTarget)
		}

		const stats = await migrator.run({
			dryRun: Boolean(options.dryRun),
			truncate: eraseTarget,
			limit: Number.isInteger(options.limit) ? options.limit : null,
			snapshots: options.snapshots,
			chunkSize: options.chunkSize,
		})

		logger.success(
			`Processed ${stats.processed} record(s); wrote ${stats.written} row(s); skipped ${stats.skipped} entity/ies.`
		)
	} catch (error) {
		logger.error(error)
		process.exitCode = 1
	}
}

process.on('SIGINT', () => {
	logger.warn('Received SIGINT, terminating...')
	process.exit(1)
})

process.on('unhandledRejection', (reason) => {
	logger.error(reason)
	process.exit(1)
})

await main()
