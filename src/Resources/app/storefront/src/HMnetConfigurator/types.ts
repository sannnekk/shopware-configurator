export interface ConfiguratorField {
	id: string
	name: string
	translated: { name: string }
	isVisible: boolean
	isRequired: boolean
	position: number | null
	setupPrice: number
	filmPrice: number
	options: ConfiguratorOption[]
}

export interface ConfiguratorOption {
	id: string
	name: string
	translated: { name: string }
	position: number | null
	possibilities: ConfiguratorOptionPossibility[]
	priceTiers: PriceTier[]
}

export interface ConfiguratorOptionPossibility {
	id: string
	name: string
	translated: { name: string }
	position: number | null
	multiplicator: number
}

export interface PriceTier {
	quantityStart: number | null
	quantityEnd: number | null
	price: number
}
