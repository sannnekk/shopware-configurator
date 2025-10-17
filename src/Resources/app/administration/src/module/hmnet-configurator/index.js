import './page/sw-product-detail-hmnet-configurator'
import './component/hmnet-configurator-option-container'
import './component/hmnet-editable-field'

const { Module } = Shopware

Module.register('hmnet-configurator-product', {
	type: 'plugin',
	name: 'hmnet-configurator-product',
	title: 'hmnet-configurator.general.moduleTitle',
	description: 'hmnet-configurator.general.moduleDescription',
	color: '#0E94D2',
	icon: 'regular-settings-gear',
	parent: 'sw-product',
	routePrefixName: 'sw.product.detail',
	routePrefixPath: 'sw/product/detail/:id',

	routeMiddleware(next, currentRoute) {
		const customRouteName = 'sw.product.detail.hmnetConfigurator'

		if (
			currentRoute.name === 'sw.product.detail' &&
			currentRoute.children.every(
				(currentRoute) => currentRoute.name !== customRouteName
			)
		) {
			currentRoute.children.push({
				name: customRouteName,
				path: '/sw/product/detail/:id/hmnet-configurator',
				component: 'sw-product-detail-hmnet-configurator',
				meta: {
					parentPath: 'sw.product.index',
					privilege: 'product.viewer',
				},
			})
		}
		next(currentRoute)
	},
})
