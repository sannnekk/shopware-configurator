//import './scss/base.scss'
import HmnetProductConfiguratorPlugin from './HMnetConfigurator/hmnet-product-configurator.plugin'

const { PluginManager } = window

if (PluginManager) {
	PluginManager.register(
		'HmnetProductConfigurator',
		HmnetProductConfiguratorPlugin,
		'[data-hmnet-product-configurator]'
	)
}
