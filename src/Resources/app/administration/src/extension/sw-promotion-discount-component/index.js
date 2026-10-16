import template from './sw-promotion-discount-component.html.twig'

const { Component } = Shopware

Component.override('sw-promotion-discount-component', {
	template,

	computed: {
		hmnetShowConfiguratorOptionsSwitch() {
			return this.discount.type === 'percentage' && this.discount.scope !== 'delivery'
		},
	},

	methods: {
		createdComponent() {
			this.$super('createdComponent')

			if (this.discount.extensions.hmnetSetting) {
				return
			}

			const setting = this.repositoryFactory.create('hmnet_promotion_discount_setting').create()
			setting.promotionDiscountId = this.discount.id
			setting.includeConfiguratorOptions = false

			this.discount.extensions.hmnetSetting = setting
		},
	},
})
