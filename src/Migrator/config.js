export const config = {
	oldDb: {
		host: 'localhost',
		port: 3306,
		user: '',
		password: '',
		database: '',
		charset: 'utf8mb4',
	},
	newDb: {
		host: 'localhost',
		port: 3306,
		user: '',
		password: '',
		database: '',
		charset: 'utf8mb4',
	},
	entityMap: {
		ott_printconfig_print_positions: 'hmnet_configurator_field',
		ott_printconfig_print_methods: 'hmnet_configurator_option',
		ott_printconfig_print_options: 'hmnet_configurator_option_possibility',
		ott_printconfig_add_costs: null,
	},
	translations: {
		'de-DE': {
			enabled: true,
			languageId: '2fbb5fe2e29a4d70aa5854ce7ce3e20b',
		},
		'en-GB': {
			enabled: true,
			languageId: '650a0e02185244688af4e2435f03ead9',
		},
	},
	inputFolder: './input',
	outputFolder: './output',
	eraseTargetTables: true,
}
