import template from './sw-order-nested-line-items-row.html.twig'
import getConfiguratorDisplayPrice from '../../util/configurator-display-price'

const { Component } = Shopware

/**
 * Setup/film costs are one-off: show them with a quantity of one instead of the product quantity.
 */
Component.override('sw-order-nested-line-items-row', {
	template,

	computed: {
		hmnetDisplayPrice() {
			return getConfiguratorDisplayPrice(this.lineItem)
		},
	},
})
