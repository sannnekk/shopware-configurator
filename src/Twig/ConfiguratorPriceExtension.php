<?php

declare(strict_types=1);

namespace HMnet\Configurator\Twig;

use HMnet\Configurator\Service\ConfiguratorLineItemHandler;
use HMnet\Configurator\Service\SetupFilmLineItemHandler;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The cart processor folds option and setup/film costs into the price of the configured product,
 * because cart totals only consider top-level line items. For display, the product row should
 * show the product price only, while the options and setup/film costs show their own prices.
 *
 * `hmnet_configurator_display_price(lineItem)` returns the prices to display for such rows, or
 * `null` when the core prices should be shown. Works for cart line items and order line items, so
 * it can be used in the storefront, documents and mails alike.
 */
class ConfiguratorPriceExtension extends AbstractExtension
{
	public function getFunctions(): array
	{
		return [
			new TwigFunction('hmnet_configurator_display_price', $this->getDisplayPrice(...)),
		];
	}

	/**
	 * @return array{unitPrice: float, totalPrice: float, quantity: int}|null
	 */
	public function getDisplayPrice(mixed $lineItem): ?array
	{
		if (!$lineItem instanceof LineItem && !$lineItem instanceof OrderLineItemEntity) {
			return null;
		}

		if ($lineItem->getType() === SetupFilmLineItemHandler::TYPE) {
			return $this->getSetupFilmPrice($lineItem);
		}

		if ($lineItem->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE) {
			return $this->getBasePrice($lineItem);
		}

		return null;
	}

	/**
	 * Setup and film costs are one-off: their line item carries the product quantity, but the
	 * price is calculated for a quantity of one.
	 *
	 * @return array{unitPrice: float, totalPrice: float, quantity: int}|null
	 */
	private function getSetupFilmPrice(LineItem|OrderLineItemEntity $lineItem): ?array
	{
		$total = $this->getTotalPrice($lineItem);

		if ($total === null) {
			return null;
		}

		return ['unitPrice' => $total, 'totalPrice' => $total, 'quantity' => 1];
	}

	/**
	 * @return array{unitPrice: float, totalPrice: float, quantity: int}|null
	 */
	private function getBasePrice(LineItem|OrderLineItemEntity $lineItem): ?array
	{
		$quantity = max(1, $lineItem->getQuantity());
		$basePrice = $lineItem->getPayload()['hmnetConfiguratorBasePrice'] ?? null;

		// Only stored by the cart processor for products with configurator options. Checked first,
		// as flat order line item lists (e.g. in mails) come without their children.
		if (\is_array($basePrice) && isset($basePrice['total'])) {
			$total = (float) $basePrice['total'];
			$unit = isset($basePrice['unit']) ? (float) $basePrice['unit'] : $total / $quantity;

			return ['unitPrice' => $unit, 'totalPrice' => $total, 'quantity' => $quantity];
		}

		// Orders created before the base price was stored in the payload: derive it from the children.
		$children = $this->getChildren($lineItem);
		$options = array_filter($children, fn($child) => $child->getType() === ConfiguratorLineItemHandler::TYPE);

		if ($options === []) {
			return null;
		}

		$lineTotal = $this->getTotalPrice($lineItem);

		if ($lineTotal === null) {
			return null;
		}

		$childTotal = 0.0;

		foreach ($children as $child) {
			if (\in_array($child->getType(), [ConfiguratorLineItemHandler::TYPE, SetupFilmLineItemHandler::TYPE], true)) {
				$childTotal += $this->getTotalPrice($child) ?? 0.0;
			}
		}

		$total = $lineTotal - $childTotal;

		return ['unitPrice' => $total / $quantity, 'totalPrice' => $total, 'quantity' => $quantity];
	}

	/**
	 * @return list<LineItem|OrderLineItemEntity>
	 */
	private function getChildren(LineItem|OrderLineItemEntity $lineItem): array
	{
		$children = $lineItem->getChildren();

		return $children === null ? [] : array_values($children->getElements());
	}

	private function getTotalPrice(LineItem|OrderLineItemEntity $lineItem): ?float
	{
		if ($lineItem->getPrice() !== null) {
			return $lineItem->getPrice()->getTotalPrice();
		}

		return $lineItem instanceof OrderLineItemEntity ? $lineItem->getTotalPrice() : null;
	}
}
