<?php

declare(strict_types=1);

namespace HMnet\Configurator\Utils;

use HMnet\Configurator\Core\Content\Configurator\PriceTierCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

class FieldUtils
{
	/**
	 * Gets option and possibility entities based on possibility ID
	 * 
	 * @return array{option: Entity|null, possibility: Entity|null}
	 */
	public static function getOptionAndPossibility(Entity $fieldEntity, string $possibilityId): array
	{
		$options = $fieldEntity->get('options');

		if (!$options) {
			return [null, null];
		}

		foreach ($options as $option) {
			$possibilities = $option->get('possibilities');
			if (!$possibilities) {
				continue;
			}

			foreach ($possibilities as $possibility) {
				if ($possibility->id === $possibilityId) {
					return [$option, $possibility];
				}
			}
		}

		return [null, null];
	}

	/**
	 * Gets price based on possibility ID and quantity
	 * 
	 * @param array<string, array<string, float>> $priceTiers - Price tiers in format: [['quantityStart' => int|null, 'quantityEnd' => int|null, 'price' => float], ...]
	 * @return float|null
	 */
	public static function getPriceFromTiers(array $priceTiers, int $quantity): float
	{
		return (new PriceTierCollection($priceTiers))->getPrice($quantity);
	}

	/**
	 * Gets lists of line items with setup and film prices from given line items
	 * 
	 * @param array<\Shopware\Core\Checkout\Cart\LineItem\LineItem> $lineItems
	 * @return array{array<\Shopware\Core\Checkout\Cart\LineItem\LineItem>, array<\Shopware\Core\Checkout\Cart\LineItem\LineItem>}
	 */
	public static function getSetupAndFilmLineItems(array $lineItems): array
	{
		$lineItemsWithSetupPrice = [];
		$lineItemsWithFilmPrice = [];

		foreach ($lineItems as $lineItem) {
			$payload = $lineItem->getPayload();

			if (isset($payload['setupPrice']) && $payload['filmPrice'] > 0) {
				$lineItemsWithSetupPrice[] = $lineItem;
			}

			if (isset($payload['filmPrice']) && $payload['filmPrice'] > 0) {
				$lineItemsWithFilmPrice[] = $lineItem;
			}
		}

		return [$lineItemsWithSetupPrice, $lineItemsWithFilmPrice];
	}
}
