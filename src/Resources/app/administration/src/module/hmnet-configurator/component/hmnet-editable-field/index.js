import template from './hmnet-editable-field.html.twig'
import './hmnet-editable-field.scss'

const { Component } = Shopware

Component.register('hmnet-editable-field', {
	template,
	props: {
		modelValue: {
			type: [String, Number],
			required: false,
			default: '',
		},
		label: {
			type: String,
			required: false,
			default: '',
		},
		placeholder: {
			type: String,
			required: false,
			default: '',
		},
		type: {
			type: String,
			required: false,
			default: 'text',
		},
		isEditable: {
			type: Boolean,
			required: false,
			default: false,
		},
	},

	emits: ['update:modelValue'],
})
