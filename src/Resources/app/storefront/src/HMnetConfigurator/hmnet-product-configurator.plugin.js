import Plugin from 'src/plugin-system/plugin.class'
import { CountUp } from './odometer'
import { Odometer } from './odometer-plugin'

/**
 * HMnet Product Configurator Plugin
 *
 * Warning: this plugin only works on sales channels with 'net' price display mode.
 * Switch to gross mode is not supported yet.
 */
export default class HmnetProductConfiguratorPlugin extends Plugin {
	/**
	 * @type {number}
	 */
	currencyDecimals = 2

	/**
	 * @type {number}
	 */
	taxRate = 19

	/**
	 * @type {string[]}
	 */
	fieldIds = []

	/**
	 * @type {{ setup: string, film: string }}
	 */
	labelTemplates = {
		setup: '',
		film: '',
	}

	init() {
		this.registerEvents()
		this.calculate()
	}

	registerEvents() {
		const thisPointer = this

		document
			.querySelector('[data-quantity-selector] input')
			?.addEventListener('change', thisPointer.calculate.bind(thisPointer))
		document
			.querySelector('[data-quantity-selector] input')
			?.addEventListener('input', thisPointer.calculate.bind(thisPointer))

		document
			.querySelector('[data-quantity-selector] .js-button-minus')
			?.addEventListener('click', () => {
				setTimeout(() => thisPointer.calculate.bind(thisPointer), 50)
			})

		document
			.querySelector('[data-quantity-selector] .js-button-plus')
			?.addEventListener('click', () => {
				setTimeout(() => thisPointer.calculate.bind(thisPointer), 50)
			})

		document
			.querySelectorAll('[data-hmnet-field-select]')
			.forEach((selectEl) =>
				selectEl.addEventListener(
					'change',
					thisPointer.calculate.bind(thisPointer)
				)
			)
	}

	/**
	 * @returns {number}
	 */
	getQuantity() {
		return (
			parseInt(
				document.querySelector('[data-quantity-selector] input')?.value
			) || 0
		)
	}

	/**
	 * Recalculates all prices based on selected options and quantity
	 *
	 * @return {void}
	 */
	calculate() {
		this.taxRate = parseFloat(this.el.dataset.taxRate)
		this.currencyDecimals = parseInt(this.el.dataset.currencyDecimals) || 2
		this.labelTemplates = {
			setup:
				this.el.dataset.setupLabelTemplate || 'Setup: %option%%possibility%',
			film: this.el.dataset.filmLabelTemplate || 'Film: %option%%possibility%',
		}
		this.fieldIds = [...this.el.querySelectorAll('[data-hmnet-field]')]
			.map((el) => el.dataset.fieldId)
			.filter(Boolean)

		const quantity = this.getQuantity()

		/**
		 * @type {number}
		 */
		const wholePriceNet = 0
		/**
		 * @type {{ label: string, price: number }[]}
		 */
		const additionalOptions = []

		/**
		 * @type {Record<string, string>}
		 */
		const chosenPossibilityIds = {}

		for (const fieldId of this.fieldIds) {
			const [chosenUnitPrice, possibilityId, opts] = this.getDataForField(
				fieldId,
				quantity
			)

			const unitTotal = quantity * chosenUnitPrice

			this.setFieldElement(fieldId, chosenUnitPrice, quantity, unitTotal)

			additionalOptions.push(...opts)

			wholePriceNet += unitTotal
			wholePriceNet += opts.reduce((sum, opt) => sum + opt.price, 0)

			if (possibilityId) {
				chosenPossibilityIds[fieldId] = possibilityId
			}
		}

		const [wholePriceGross, wholeTax] = this.getGrossFromNet(wholePriceNet)

		this.setFilmAndSetupOptions(additionalOptions)
		this.setChosenOptionsInCartData(chosenPossibilityIds)
		this.setWholePriceElements(wholePriceNet, wholePriceGross, wholeTax)
	}

	/**
	 * @param {{ label: string, price: number }[]} options
	 */
	setFilmAndSetupOptions(options) {
		const container = this.el.querySelector('[data-hmnet-breakdown-list]')

		container.innerHTML = options
			.filter((opt) => opt.price > 0)
			.map((opt) => this.getOptionTemplate(opt.label, opt.price))
			.join('')
	}

	/**
	 * @param {string} label
	 * @param {number} price
	 * @returns {string}
	 */
	getOptionTemplate(label, price) {
		return `<li>
			<span>${label}</span>
			<span>${price}</span>
		</li>`
	}

	/**
	 * @param {string} fieldId
	 * @param {number} quantity
	 * @returns {[number, string|null, { label: string, price: number }, { label: string, price: number }]}
	 */
	getDataForField(fieldId, quantity) {
		const defaultReturn = [0, null, [], []]
		const field = document.querySelector(
			`[data-hmnet-field][data-field-id="${fieldId}"]`
		)

		if (!field) {
			return defaultReturn
		}

		const chosenPossibilityId = field.querySelector(
			'[data-hmnet-field-select]'
		).value

		const setupPrice = parseFloat(field.dataset.setupPrice) || 0
		const filmPrice = parseFloat(field.dataset.filmPrice) || 0
		const options = Object.values(JSON.parse(field.dataset.options || '[]'))

		if (!chosenPossibilityId) {
			return defaultReturn
		}

		const option = options.find((o) =>
			Object.values(o.possibilities).some((p) => p.id === chosenPossibilityId)
		)

		const possibility = Object.values(option?.possibilities).find(
			(p) => p.id === chosenPossibilityId
		)

		if (!option || !possibility) {
			return defaultReturn
		}

		const optionName = option.translated.name || option.name || ''
		const possibilityName =
			possibility.translated.name || possibility.name || ''
		const multiplicator = possibility.multiplicator ?? 1

		return [
			this.getUnitPriceForOption(option.priceTiers, multiplicator, quantity),
			chosenPossibilityId,
			[
				{
					label: this.getLabelFromTemplate(
						this.labelTemplates.setup,
						'Einrichtung',
						optionName,
						possibilityName
					),
					price: (setupPrice ?? 0) * multiplicator,
				},
				{
					label: this.getLabelFromTemplate(
						this.labelTemplates.film,
						'Film',
						optionName,
						possibilityName
					),
					price: (filmPrice ?? 0) * multiplicator,
				},
			],
		]
	}

	/**
	 * @param {object} priceTiers
	 * @param {number} multiplicator
	 * @param {number} quantity
	 * @returns {number}
	 */
	getUnitPriceForOption(priceTiers, multiplicator, quantity) {
		const tier = priceTiers.find(
			(t) =>
				(t.quantityStart <= quantity && t.quantityEnd >= quantity) ||
				(t.quantityEnd === null && t.quantityStart <= quantity)
		)

		if (!tier) {
			return 0
		}

		return tier.price * multiplicator
	}

	/**
	 * Set all the chosen options in the cart data to be picked up by the server
	 *
	 * @param {Record<string, string>} chosenPossibilityIds
	 */
	setChosenOptionsInCartData(chosenPossibilityIds) {}

	/**
	 * Calculates gross price and tax amount from net price, concidering taxRate and currencyDecimals
	 * @param {number} netPrice
	 * @returns {[number, number]}
	 */
	getGrossFromNet(netPrice) {
		const grossPrice = parseFloat(
			(netPrice * (1 + this.taxRate / 100)).toFixed(this.currencyDecimals)
		)

		return [grossPrice, grossPrice - netPrice]
	}

	/**
	 * @param {number} netPrice
	 * @param {number} grossPrice
	 * @param {number} taxAmount
	 */
	setWholePriceElements(netPrice, grossPrice, taxAmount) {
		const netPriceEl = document.querySelector(
			'[data-hmnet-product-configurator-total-net]'
		)
		const grossPriceEl = document.querySelector(
			'[data-hmnet-product-configurator-total-gross]'
		)
		const taxAmountEl = document.querySelector(
			'[data-hmnet-product-configurator-total-tax]'
		)

		this.setNumber(netPriceEl, netPrice, this.currencyDecimals)
		this.setNumber(grossPriceEl, grossPrice, this.currencyDecimals)
		this.setNumber(taxAmountEl, taxAmount, this.currencyDecimals)
	}

	/**
	 * @param {string} fieldId
	 * @param {number} unitPrice
	 * @param {number} quantity
	 * @param {number} totalPrice
	 */
	setFieldElement(fieldId, unitPrice, quantity, totalPrice) {
		const fieldEl = document.querySelector(
			`[data-hmnet-field][data-field-id="${fieldId}"]`
		)

		if (!fieldEl) {
			return
		}

		const unitPriceEl = fieldEl.querySelector('[data-hmnet-field-unit-price]')
		const quantityEl = fieldEl.querySelector('[data-hmnet-field-amount]')
		const totalPriceEl = fieldEl.querySelector('[data-hmnet-field-unit-total]')

		this.setNumber(unitPriceEl, unitPrice, this.currencyDecimals)
		this.setNumber(quantityEl, quantity, 0)
		this.setNumber(totalPriceEl, totalPrice, this.currencyDecimals)
	}

	/**
	 * @param {number} price
	 */
	setNumber(htmlEl, price, decimals = 2) {
		const prevValue = parseFloat(htmlEl.dataset.counterPrevValue || '0')

		const countUp = new CountUp(htmlEl, price, {
			plugin: new Odometer({ duration: 0.8, lastDigitDelay: 0 }),
			startVal: prevValue,
			duration: 0.8,
			decimalPlaces: decimals,
			separator: '.',
			decimal: ',',
		})
		countUp.start()

		htmlEl.dataset.counterPrevValue = price
	}

	/**
	 * @param {string} template
	 * @param {string} fallbackPrefix
	 * @param {string} optionName
	 * @param {string} possibilityName
	 * @returns {string}
	 */
	getLabelFromTemplate(template, fallbackPrefix, optionName, possibilityName) {
		const safeOption = optionName || ''
		const safePossibility = possibilityName ? ` ${possibilityName}` : ''
		const baseTemplate = template || `${fallbackPrefix}: %option%%possibility%`

		return baseTemplate
			.replace(/%option%/g, safeOption)
			.replace(/%possibility%/g, safePossibility)
			.replace(/\s+/g, ' ')
			.trim()
	}
}
