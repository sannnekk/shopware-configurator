import template from './hmnet-configurator-option-container.html.twig'
import './hmnet-configurator-option-container.scss'

const { Component } = Shopware

Component.register('hmnet-configurator-option-container', {
	template,

	props: {
		option: {
			type: Object,
			required: true,
		},
		isEditable: {
			type: Boolean,
			required: false,
			default: false,
		},
	},

	inject: ['repositoryFactory'],

	// emits
	emits: ['update:option', 'remove-option'],

	computed: {
		optionModel: {
			get() {
				return this.option
			},
			set(value) {
				this.$emit('update:option', value)
			},
		},

		configuratorPossibilityRepository() {
			return this.repositoryFactory.create(
				'hmnet_configurator_option_possibility'
			)
		},
	},

	methods: {
		onRemovePossibility(possibility) {
			this.option.possibilities.remove(possibility)
		},

		onRemoveOption() {
			this.$emit('remove-option', this.option.id)
		},

		onAddPossibility() {
			const possibility = this.configuratorPossibilityRepository.create()

			possibility.optionId = this.option.id
			possibility.multiplicator = 1
			possibility.position = this.option.possibilities
				? this.option.possibilities.length + 1
				: 1

			if (!this.option.possibilities) {
				this.option.possibilities = new Shopware.Data.EntityCollection(
					this.configuratorPossibilityRepository.route,
					this.configuratorPossibilityRepository.entityName,
					Shopware.Context.api
				)
			}

			this.option.possibilities.add(possibility)
		},
	},
})
