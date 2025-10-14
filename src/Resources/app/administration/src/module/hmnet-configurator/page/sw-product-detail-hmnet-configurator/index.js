import template from './sw-product-detail-hmnet-configurator.html.twig'
import './sw-product-detail-hmnet-configurator.scss'

const { Component, Mixin } = Shopware
const { Criteria, EntityCollection } = Shopware.Data

Component.register('sw-product-detail-hmnet-configurator', {
	template,

	inject: ['repositoryFactory'],

	mixins: [Mixin.getByName('notification')],

	data() {
		return {
			fields: null,
			isLoading: false,
			isSaving: false,
		}
	},

	computed: {
		configuratorFieldRepository() {
			return this.repositoryFactory.create('hmnet_configurator_field')
		},
		productId() {
			return this.$route.params.id
		},
		columns() {
			return [
				{
					label: this.$tc('hmnet-configurator.table.columns.name'),
					property: 'name',
					type: 'string',
					inlineEdit: 'string',
					allowResize: true,
					primary: true,
				},
				{
					label: this.$tc('hmnet-configurator.table.columns.isRequired'),
					property: 'isRequired',
					type: 'boolean',
					inlineEdit: 'boolean',
					allowResize: true,
				},
				{
					label: this.$tc('hmnet-configurator.table.columns.isVisible'),
					property: 'isVisible',
					type: 'boolean',
					inlineEdit: 'boolean',
					allowResize: true,
				},
			]
		},
		hasFields() {
			return this.fields && this.fields.length > 0
		},
		isSaveDisabled() {
			return this.isSaving || !this.hasFields
		},
	},

	watch: {
		productId: {
			immediate: true,
			handler() {
				if (!this.productId) {
					this.fields = this.createEmptyCollection()
					return
				}

				this.loadFields()
			},
		},
	},

	created() {
		if (!this.fields) {
			this.fields = this.createEmptyCollection()
		}
	},

	methods: {
		createEmptyCollection() {
			return new EntityCollection(
				this.configuratorFieldRepository.route,
				this.configuratorFieldRepository.entityName,
				Shopware.Context.api
			)
		},

		onAddField() {
			const newField = this.configuratorFieldRepository.create(
				Shopware.Context.api
			)

			newField.productId = this.productId
			newField.position = this.fields.length
			newField.isVisible = true
			newField.isRequired = false

			this.fields.add(newField)
		},

		onRemoveField(field) {
			if (!this.fields) {
				return
			}

			this.fields.remove(field.id)
		},

		async onSaveFields() {
			if (!this.fields) {
				return
			}

			this.isSaving = true

			const fieldsToSave = Array.from(this.fields).map((field, index) => {
				field.productId = this.productId
				field.position = index
				return field
			})

			try {
				await Promise.all(
					fieldsToSave.map((field) =>
						this.configuratorFieldRepository.save(field, Shopware.Context.api)
					)
				)

				this.createNotificationSuccess({
					message: this.$tc('hmnet-configurator.notifications.saveSuccess'),
				})

				await this.loadFields()
			} catch (error) {
				this.createNotificationError({
					message: this.$tc('hmnet-configurator.notifications.saveError'),
				})

				console.error(error)
			} finally {
				this.isSaving = false
			}
		},

		async loadFields() {
			this.isLoading = true

			const criteria = new Criteria(1, 50)

			criteria.addFilter(Criteria.equals('productId', this.productId))
			criteria.addSorting(Criteria.sort('position', 'ASC'))

			try {
				const result = await this.configuratorFieldRepository.search(
					criteria,
					Shopware.Context.api
				)

				this.fields = result || this.createEmptyCollection()
			} catch (error) {
				this.fields = this.createEmptyCollection()

				this.createNotificationError({
					message: this.$tc('hmnet-configurator.notifications.loadError'),
				})

				console.error(error)
			} finally {
				this.isLoading = false
			}
		},
	},
})
