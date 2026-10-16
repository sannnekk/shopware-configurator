const { Component } = Shopware

Component.override('sw-promotion-v2-detail', {
	computed: {
		promotionCriteria() {
			const criteria = this.$super('promotionCriteria')

			// load the per-discount configurator settings, they are saved together with the promotion
			criteria.addAssociation('discounts.hmnetSetting')

			return criteria
		},
	},
})
