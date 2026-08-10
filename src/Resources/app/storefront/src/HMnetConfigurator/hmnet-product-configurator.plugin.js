import Plugin from 'src/plugin-system/plugin.class'
import { CountUp } from './odometer'
import { Odometer } from './odometer-plugin'

/**
 * HMnet Product Configurator Plugin
 *
 * All price calculations are performed server-side for consistency.
 * This plugin fetches calculated prices from the backend API.
 */
export default class HmnetProductConfiguratorPlugin extends Plugin {
	/**
	 * @type {number}
	 */
	currencyDecimals = 2

	/**
	 * @type {string}
	 */
	currencySymbol = '€'

	/**
	 * @type {AbortController|null}
	 */
	currentRequest = null

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
		this.loaderEl = this.el.querySelector('[data-hmnet-configurator-loader]')
		this.hintEl = this.el.querySelector('[data-hmnet-configurator-hint]')
		this.quantityInput = document.querySelector(
			'[data-quantity-selector] input'
		)

		this.debouncedCalculate = this.debounce(
			this.calculateFromServer.bind(this),
			400
		)
		this.registerEvents()
		this.calculateFromServer()
	}

	registerEvents() {
		this.quantityInput?.addEventListener(
			'change',
			this.debouncedCalculate.bind(this)
		)
		this.quantityInput?.addEventListener(
			'input',
			this.debouncedCalculate.bind(this)
		)

		document
			.querySelector('[data-quantity-selector] .js-button-minus')
			?.addEventListener('click', this.debouncedCalculate.bind(this))

		document
			.querySelector('[data-quantity-selector] .js-button-plus')
			?.addEventListener('click', this.debouncedCalculate.bind(this))

		document
			.querySelectorAll('[data-hmnet-field-select]')
			.forEach((selectEl) =>
				selectEl.addEventListener('change', this.debouncedCalculate.bind(this))
			)

		this.registerPdfDownload()
	}

	registerPdfDownload() {
		const button = document.querySelector(
			'[data-hmnet-configurator-pdf-download="quote"]'
		)

		if (!button) {
			return
		}

		button.addEventListener('click', () =>
			this.handlePdfDownload(button).catch(() => {})
		)
	}

	/**
	 * @param {Function} func
	 * @param {number} delay
	 * @returns {Function}
	 */
	debounce(func, delay) {
		let timeoutId
		return function (...args) {
			this.setLoading(true)
			clearTimeout(timeoutId)
			timeoutId = setTimeout(() => func.apply(this, args), delay)
		}
	}

	/**
	 * @returns {number}
	 */
	getQuantity() {
		return parseInt(this.quantityInput?.value) || 1
	}

	/**
	 * @param {number} quantity
	 */
	setQuantity(quantity) {
		if (this.quantityInput) {
			this.quantityInput.value = quantity
		}
	}

	/**
	 * Get the current selection of field options
	 * @returns {Record<string, string>}
	 */
	getSelection() {
		const selection = {}
		this.fieldIds = [
			...this.el.querySelectorAll('[data-hmnet-field]'),
		].map((el) => el.dataset.fieldId)

		for (const fieldId of this.fieldIds) {
			const field = this.el.querySelector(
				`[data-hmnet-field][data-field-id="${fieldId}"]`
			)
			const select = field?.querySelector('[data-hmnet-field-select]')

			if (select?.value) {
				selection[fieldId] = select.value
			}
		}

		return selection
	}

	/**
	 * Fetch calculated prices from the server
	 */
	async calculateFromServer() {
		// Cancel any pending request
		if (this.currentRequest) {
			this.currentRequest.abort()
		}

		this.currentRequest = new AbortController()
		this.setLoading(true)
		this.hideHint()

		try {
			const response = await fetch(this.getCalculateEndpoint(), {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-Token': this.getCsrfToken(),
				},
				body: JSON.stringify({
					productId: this.getProductId(),
					quantity: this.getQuantity(),
					selection: this.getSelection(),
				}),
				signal: this.currentRequest.signal,
			})

			if (!response.ok) {
				throw new Error('Failed to calculate prices')
			}

			const result = await response.json()

			if (!result.success) {
				console.error('Calculation error:', result.error)
				return
			}

			this.applyCalculationResult(result)
		} catch (error) {
			if (error.name === 'AbortError') {
				// Request was cancelled, ignore
				return
			}
			console.error('Error calculating prices:', error)
		} finally {
			this.currentRequest = null
			this.setLoading(false)
		}
	}

	/**
	 * Apply the calculation result from the server
	 * @param {Object} result
	 */
	applyCalculationResult(result) {
		this.currencyDecimals = result.currencyDecimals ?? 2
		this.currencySymbol = result.currencySymbol ?? '€'

		// Handle quantity adjustment
		if (result.quantityAdjusted && result.adjustedQuantity) {
			this.setQuantity(result.adjustedQuantity)

			if (result.minimumQuantityHint) {
				this.showHint(result.minimumQuantityHint)
			}
		}

		const quantity = result.adjustedQuantity ?? this.getQuantity()

		// Update product price display
		this.setProductPriceElements(
			result.product.unitNet,
			quantity,
			result.product.totalNet
		)

		// Update option prices
		for (const option of result.options) {
			this.setFieldElement(
				option.fieldId,
				option.unitNet,
				quantity,
				option.totalNet
			)
		}

		// Update surcharges (setup/film)
		this.setSurcharges(result.surcharges)

		// Update totals
		this.setWholePriceElements(
			result.totals.netTotal,
			result.totals.grossTotal,
			result.totals.taxAmount
		)

		// Update cart data
		this.setChosenOptionsInCartData(this.getSelection())
	}

	/**
	 * Show a hint message to the user
	 * @param {string} message
	 */
	showHint(message) {
		if (!this.hintEl) {
			// Create hint element if it doesn't exist
			this.hintEl = document.createElement('div')
			this.hintEl.className = 'hmnet-product-configurator__hint alert alert-info'
			this.hintEl.setAttribute('data-hmnet-configurator-hint', '')
			this.hintEl.setAttribute('role', 'alert')

			const container = this.el.querySelector(
				'.hmnet-product-configurator__content'
			)
			if (container) {
				container.insertBefore(this.hintEl, container.firstChild)
			} else {
				this.el.insertBefore(this.hintEl, this.el.firstChild)
			}
		}

		this.hintEl.textContent = message
		this.hintEl.style.display = 'block'
		this.hintEl.setAttribute('aria-hidden', 'false')
	}

	/**
	 * Hide the hint message
	 */
	hideHint() {
		if (this.hintEl) {
			this.hintEl.style.display = 'none'
			this.hintEl.setAttribute('aria-hidden', 'true')
		}
	}

	/**
	 * @param {array} surcharges
	 */
	setSurcharges(surcharges) {
		const container = this.el.querySelector('[data-hmnet-breakdown-list]')
		const wrapper = container?.closest('[data-hmnet-breakdown]')

		if (!container) return

		const items = surcharges.filter((s) => s.amount > 0)
		container.innerHTML = items
			.map((s) => this.getOptionTemplate(s.label, s.amount))
			.join('')

		const hasItems = items.length > 0
		if (wrapper) {
			wrapper.classList.toggle(
				'hmnet-product-configurator__breakdown--visible',
				hasItems
			)
			wrapper.classList.toggle(
				'hmnet-product-configurator__breakdown--hidden',
				!hasItems
			)
			wrapper.setAttribute('aria-hidden', hasItems ? 'false' : 'true')
		}
	}

	/**
	 * Set product price elements
	 * @param {number} unitPrice
	 * @param {number} quantity
	 * @param {number} totalPrice
	 */
	setProductPriceElements(unitPrice, quantity, totalPrice) {
		const productUnitPriceEl = this.el.querySelector(
			'[data-hmnet-product-unit-price]'
		)
		const productQuantityEl = this.el.querySelector(
			'[data-hmnet-product-quantity]'
		)
		const productTotalPriceEl = this.el.querySelector(
			'[data-hmnet-product-total]'
		)

		this.setNumber(productUnitPriceEl, unitPrice, this.currencyDecimals)
		this.setNumber(productQuantityEl, quantity, 0, '', '')
		this.setNumber(productTotalPriceEl, totalPrice, this.currencyDecimals)
	}

	/**
	 * @param {string} label
	 * @param {number} price
	 * @returns {string}
	 */
	getOptionTemplate(label, price) {
		return `<li>
			<span>${label}</span>
			<span>${price.toFixed(this.currencyDecimals)} ${this.currencySymbol}</span>
		</li>`
	}

	/**
	 * Set all the chosen options in the cart data to be picked up by the server
	 *
	 * @param {Record<string, string>} chosenPossibilityIds (fieldId => possibilityId)
	 */
	setChosenOptionsInCartData(chosenPossibilityIds) {
		const input = document.querySelector('[data-hmnet-configurator-payload]')

		if (!input) {
			return
		}

		input.value = JSON.stringify({
			hmnetProductConfigurator: chosenPossibilityIds,
		})
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

	setLoading(isLoading) {
		if (!this.el) {
			return
		}

		this.el.classList.toggle(
			'hmnet-product-configurator--calculating',
			isLoading
		)
		this.el.setAttribute('aria-busy', isLoading ? 'true' : 'false')

		if (this.loaderEl) {
			this.loaderEl.setAttribute('aria-hidden', isLoading ? 'false' : 'true')
		}
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
		this.setNumber(quantityEl, quantity, 0, '', '')
		this.setNumber(totalPriceEl, totalPrice, this.currencyDecimals)
	}

	/**
	 * @param {HTMLElement} htmlEl
	 * @param {number} price
	 * @param {number} decimals
	 * @param {string} separator
	 * @param {string} decimal
	 */
	setNumber(htmlEl, price, decimals = 2, separator = '.', decimal = ',') {
		if (!htmlEl) return

		const prevValue = parseFloat(htmlEl.dataset.counterPrevValue || '0')
		const elementUid = htmlEl.dataset.hmnetUid ?? '-'

		if (!window.countup) {
			window.countup = {}
		}

		window.countup[elementUid] = new CountUp(htmlEl, price, {
			plugin: new Odometer({ duration: 0.8, lastDigitDelay: 0 }),
			startVal: prevValue,
			duration: 0.8,
			decimalPlaces: decimals,
			separator,
			decimal,
		})
		htmlEl.dataset.counterPrevValue = price
		window.countup[elementUid].start()
	}

	async handlePdfDownload(button) {
		if (button) {
			button.setAttribute('disabled', 'disabled')
		}

		try {
			const requestBody = {
				productId: this.getProductId(),
				quantity: this.getQuantity(),
				payload: this.getSelection(),
			}

			const response = await fetch(this.el.dataset.pdfEndpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-Token': this.getCsrfToken(),
				},
				body: JSON.stringify(requestBody),
			})

			if (!response.ok) {
				throw new Error('PDF konnte nicht erstellt werden')
			}

			const blob = await response.blob()
			const fileName = 'angebot.pdf'
			const url = window.URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = fileName
			document.body.appendChild(link)
			link.click()
			link.remove()
			window.URL.revokeObjectURL(url)
		} finally {
			if (button) {
				button.removeAttribute('disabled')
			}
		}
	}

	getCsrfToken() {
		const meta = document.querySelector('meta[name="csrf-token"]')
		return meta?.getAttribute('content') || ''
	}

	getProductId() {
		return this.el.dataset.productId || ''
	}

	getCalculateEndpoint() {
		return this.el.dataset.calculateEndpoint || '/hmnet/configurator/calculate'
	}
}
