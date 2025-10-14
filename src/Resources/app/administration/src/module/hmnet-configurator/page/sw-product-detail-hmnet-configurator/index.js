import template from './sw-product-detail-hmnet-configurator.html.twig'
import './sw-product-detail-hmnet-configurator.scss'

const { Component } = Shopware

Component.register('sw-product-detail-hmnet-configurator', {
	template,

	inject: ['repositoryFactory'],

	data() {
		return {
			fields: [],
		}
	},

	computed: {
		configuratorFieldRepository() {
			return this.repositoryFactory.create('hmnet_configurator_field')
		},
	},

	methods: {
		onAddField() {},

		onRemoveField() {},

		onSaveField() {},
	},
})
