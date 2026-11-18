/**
 * Admin counterpart of HMnet\Configurator\Twig\ConfiguratorPriceExtension - keep both in sync.
 *
 * The cart folds the option and setup/film costs into the price of the configured product. For
 * display, the product row shows the product price only, while the options and setup/film costs
 * show their own prices. Setup/film costs are one-off and therefore shown with a quantity of one.
 *
 * @param {Object} lineItem order line item
 * @returns {{unitPrice: number, totalPrice: number, quantity: number}|null} `null` keeps the core values
 */
export default function getConfiguratorDisplayPrice(lineItem) {
	if (!lineItem) {
		return null;
	}

	if (lineItem.type === 'hmnet-configurator-setup-film') {
		return { unitPrice: lineItem.totalPrice, totalPrice: lineItem.totalPrice, quantity: 1 };
	}

	if (lineItem.type !== 'product') {
		return null;
	}

	const quantity = Math.max(1, lineItem.quantity || 1);
	const basePrice = lineItem.payload?.hmnetConfiguratorBasePrice;

	// Only stored by the cart processor for products with configurator options
	if (basePrice && basePrice.total !== undefined && basePrice.total !== null) {
		const total = Number(basePrice.total);
		const unit = basePrice.unit !== undefined && basePrice.unit !== null ? Number(basePrice.unit) : total / quantity;

		return { unitPrice: unit, totalPrice: total, quantity };
	}

	// Orders created before the base price was stored in the payload: derive it from the children
	const children = Array.from(lineItem.children || []);

	if (!children.some((child) => child.type === 'hmnet-configurator')) {
		return null;
	}

	const childTotal = children
		.filter((child) => ['hmnet-configurator', 'hmnet-configurator-setup-film'].includes(child.type))
		.reduce((sum, child) => sum + (child.totalPrice || 0), 0);
	const total = lineItem.totalPrice - childTotal;

	return { unitPrice: total / quantity, totalPrice: total, quantity };
}
