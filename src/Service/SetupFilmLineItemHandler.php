<?php

declare(strict_types=1);

namespace HMnet\Configurator\Service;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryHandler\LineItemFactoryInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Cart\Tax\TaxCalculator;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class SetupFilmLineItemHandler implements LineItemFactoryInterface
{
	public const TYPE = 'hmnet-configurator-setup-film';

	private TaxCalculator $taxCalculator;

	public function __construct(TaxCalculator $taxCalculator)
	{
		$this->taxCalculator = $taxCalculator;
	}

	public function supports($type): bool
	{
		return $type === self::TYPE;
	}

	public function create(array $data, SalesChannelContext $context): LineItem
	{
		$type = $data['type'] === 'setup' ? 'setup' : 'film';
		$prefix = $type === 'setup' ? 'setup-' : 'film-';
		$referencedId = $prefix . ($data['productId'] ?? '');
		$lineItems = $data['lineItems'] ?? [];
		$taxRules = $data['taxRules'] ?? new TaxRuleCollection();

		$lineItem = new LineItem($referencedId, self::TYPE, null, 1);


		$lineItem->setLabel($this->getLabel($type));
		$lineItem->setStackable(true);
		$lineItem->setRemovable(false);
		$lineItem->setPayload([
			"positions" => array_map(fn(LineItem $item) => [
				"label" => $item->getLabel(),
				"price" => $item->getPayloadValue($type === 'setup' ? 'setupPrice' : 'filmPrice') ?? 0.0,
			], $lineItems),
		]);

		$totalPrice = $this->getPrice($lineItems, $type);

		$taxes = $this->calculateTaxes($taxRules, $context);

		$lineItem->setPrice(new CalculatedPrice(
			$totalPrice,
			$totalPrice,
			$taxes,
			$taxRules,
			1
		));

		return $lineItem;
	}

	public function update(LineItem $lineItem, array $data, SalesChannelContext $context): void
	{
		// TODO: Implement
	}

	/**
	 * Gets label based on type
	 */
	private function getLabel(string $type): string
	{
		return $type === 'setup' ? 'Setup Price' : 'Film Price';
	}

	private function getPrice(array $lineItems, string $type): float
	{
		$total = 0.0;

		foreach ($lineItems as $item) {
			$price = $item->getPayloadValue($type === 'setup' ? 'setupPrice' : 'filmPrice') ?? 0.0;
			$multiplicator = (float) ($item->getPayloadValue('multiplicator') ?? 1.0);
			$price *= $multiplicator;
			$total += $price;
		}

		return max(0.0, $total);
	}

	/**
	 * Generates taxes based on tax rules
	 */
	private function calculateTaxes(TaxRuleCollection $taxRules, SalesChannelContext $context): CalculatedTaxCollection
	{
		if (\count($taxRules) === 0) {
			return new CalculatedTaxCollection([]);
		}

		$taxState = $context->getTaxState();

		if ($taxState === CartPrice::TAX_STATE_FREE) {
			return new CalculatedTaxCollection([]);
		}

		if ($taxState === CartPrice::TAX_STATE_NET) {
			return $this->taxCalculator->calculateNetTaxes(1, $taxRules);
		}

		return $this->taxCalculator->calculateGrossTaxes(1, $taxRules);
	}
}
