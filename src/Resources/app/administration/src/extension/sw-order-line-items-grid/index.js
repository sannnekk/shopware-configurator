import template from './sw-order-line-items-grid.html.twig'
import getConfiguratorDisplayPrice from '../../util/configurator-display-price'

const { Component } = Shopware

/**
 * Configured products show the product price only. The options and setup/film costs are listed
 * with their own prices in the nested line items modal.
 */
Component.override('sw-order-line-items-grid', {
	template,

	methods: {
		hmnetDisplayValue(item, field) {
			const displayPrice = getConfiguratorDisplayPrice(item)

			return displayPrice ? displayPrice[field] : item[field]
		},
	},
})
