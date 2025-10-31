import chalk from 'chalk'
import ora from 'ora'

const SYMBOLS = {
	success: chalk.green('✔'),
	info: chalk.cyan('ℹ'),
	warn: chalk.yellow('⚠'),
	error: chalk.red('✖'),
	skip: chalk.blue('⏭'),
}

const defaultLoggerOptions = {
	useSpinner: true,
}

function formatMessage(message) {
	if (message instanceof Error) {
		return message.message
	}

	if (typeof message === 'object' && message !== null) {
		return JSON.stringify(message, null, 2)
	}

	return message
}

export function createLogger(options = {}) {
	const settings = { ...defaultLoggerOptions, ...options }

	function log(prefix, message) {
		const text = formatMessage(message)
		console.log(prefix, text)
	}

	function info(message) {
		log(SYMBOLS.info, message)
	}

	function warn(message) {
		log(SYMBOLS.warn, message)
	}

	function error(message) {
		if (message instanceof Error && message.stack) {
			console.error(SYMBOLS.error, message.stack)
			return
		}

		console.error(SYMBOLS.error, formatMessage(message))
	}

	function success(message) {
		log(SYMBOLS.success, message)
	}

	function stage(label) {
		if (settings.useSpinner) {
			const spinner = ora({
				text: label,
				color: 'cyan',
			}).start()

			return {
				update(message) {
					spinner.text = `${label} · ${formatMessage(message)}`
				},
				succeed(message) {
					spinner.succeed(
						message ? `${label} · ${formatMessage(message)}` : label
					)
				},
				fail(message) {
					spinner.fail(message ? `${label} · ${formatMessage(message)}` : label)
				},
				skip(message) {
					spinner.stopAndPersist({
						symbol: SYMBOLS.skip,
						text: message ? `${label} · ${formatMessage(message)}` : label,
					})
				},
			}
		}

		info(label)

		return {
			update(message) {
				info(`${label} · ${formatMessage(message)}`)
			},
			succeed(message) {
				success(`${label}${message ? ` · ${formatMessage(message)}` : ''}`)
			},
			fail(message) {
				error(`${label}${message ? ` · ${formatMessage(message)}` : ''}`)
			},
			skip(message) {
				info(
					`${label}${message ? ` · ${formatMessage(message)}` : ''} (skipped)`
				)
			},
		}
	}

	return {
		info,
		warn,
		error,
		success,
		stage,
	}
}
