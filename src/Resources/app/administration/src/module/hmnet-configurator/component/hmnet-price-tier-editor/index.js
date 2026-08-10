import template from './hmnet-price-tier-editor.html.twig'
import './hmnet-price-tier-editor.scss'

const { Component } = Shopware

Component.register('hmnet-price-tier-editor', {
	template,

	props: {
		priceTiers: {
			type: [Array, Object],
			required: false,
			default: () => [],
		},
		isEditable: {
			type: Boolean,
			required: false,
			default: false,
		},
	},

	emits: ['update:priceTiers'],

	computed: {
		tiersModel: {
			get() {
				return this.normalizedTiers
			},
			set(value) {
				this.$emit('update:priceTiers', value)
			},
		},

		normalizedTiers() {
			if (!this.priceTiers) {
				return []
			}

			// If it's already an array, return it
			if (Array.isArray(this.priceTiers)) {
				return this.priceTiers
			}

			// If it has a getTiers method (PriceTierCollection), call it
			if (typeof this.priceTiers.getTiers === 'function') {
				return this.priceTiers.getTiers()
			}

			// If it's an object with numeric keys, convert to array
			if (typeof this.priceTiers === 'object') {
				return Object.values(this.priceTiers)
			}

			return []
		},
	},

	methods: {
		onAddTier() {
			const newTiers = [...this.normalizedTiers]
			const lastTier = newTiers.length > 0 ? newTiers[newTiers.length - 1] : null

			// Calculate new tier start
			let newStart = 1
			if (lastTier) {
				// Update the previous tier's end to be one less than the new start
				const previousEnd = lastTier.quantityEnd
				if (previousEnd === null) {
					// Previous tier has no end, set it to current start + 99
					newStart = (lastTier.quantityStart || 1) + 100
					lastTier.quantityEnd = newStart - 1
				} else {
					newStart = previousEnd + 1
				}
			}

			newTiers.push({
				quantityStart: newStart,
				quantityEnd: null,
				price: 0.0,
			})

			this.$emit('update:priceTiers', newTiers)
		},

		onRemoveTier(index) {
			const newTiers = [...this.normalizedTiers]
			newTiers.splice(index, 1)

			// If there are remaining tiers, update the last one to have no end
			if (newTiers.length > 0) {
				newTiers[newTiers.length - 1].quantityEnd = null
			}

			this.$emit('update:priceTiers', newTiers)
		},

		onUpdateTierStart(index, value) {
			const newTiers = [...this.normalizedTiers]
			newTiers[index] = { ...newTiers[index], quantityStart: value }

			// Update previous tier's end if needed
			if (index > 0 && value !== null) {
				newTiers[index - 1] = {
					...newTiers[index - 1],
					quantityEnd: value - 1,
				}
			}

			this.$emit('update:priceTiers', newTiers)
		},

		onUpdateTierEnd(index, value) {
			const newTiers = [...this.normalizedTiers]
			newTiers[index] = { ...newTiers[index], quantityEnd: value }

			// Update next tier's start if needed
			if (index < newTiers.length - 1 && value !== null) {
				newTiers[index + 1] = {
					...newTiers[index + 1],
					quantityStart: value + 1,
				}
			}

			this.$emit('update:priceTiers', newTiers)
		},

		onUpdateTierPrice(index, value) {
			const newTiers = [...this.normalizedTiers]
			newTiers[index] = { ...newTiers[index], price: value }
			this.$emit('update:priceTiers', newTiers)
		},

		isLastTier(index) {
			return index === this.normalizedTiers.length - 1
		},

		formatQuantityEnd(tier) {
			return tier.quantityEnd ?? '∞'
		},
	},
})
