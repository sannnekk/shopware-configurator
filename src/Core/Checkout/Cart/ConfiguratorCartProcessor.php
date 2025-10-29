<?php

namespace HMnet\Configurator\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use HMnet\Configurator\Core\Content\Configurator\ConfiguratorFieldEntity;
use HMnet\Configurator\Service\ConfiguratorLineItemHandler;
use HMnet\Configurator\Utils\FieldUtils;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Cart\Tax\TaxCalculator;

class ConfiguratorCartProcessor implements CartProcessorInterface
{
	private ConfiguratorLineItemHandler $factory;

	private LoggerInterface $logger;

	private TaxCalculator $taxCalculator;

	public function __construct(ConfiguratorLineItemHandler $factory, LoggerInterface $logger, TaxCalculator $taxCalculator)
	{
		$this->factory = $factory;
		$this->logger = $logger;
		$this->taxCalculator = $taxCalculator;
	}

	public function process(
		CartDataCollection $data,
		Cart $original,
		Cart $toCalculate,
		SalesChannelContext $context,
		CartBehavior $behavior
	): void {
		$this->addChildrenToCart($data, $toCalculate, $context);
		$this->adjustChildQuantities($toCalculate);
		$this->adjustChildPrices($toCalculate, $context);

		// TODO: add setup & film prices
	}

	/**
	 * Add all the chosen options as child line items
	 */
	private function addChildrenToCart(CartDataCollection $data, Cart $toCalculate, SalesChannelContext $context): void
	{
		foreach ($toCalculate->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
			[$payload, $fieldEntities] = $data->get($lineItem->getId());

			if (!$fieldEntities) {
				continue;
			}

			$children = $this->generateChildrenFromFields(
				$fieldEntities,
				$payload,
				$lineItem->getQuantity(),
				$context
			);

			foreach ($children as $child) {
				$lineItem->addChild($child);
			}

			$data->remove($lineItem->getId());
		}
	}

	/**
	 * Ensure that child line items have the same quantity as their parent
	 */
	private function adjustChildQuantities(Cart $toCalculate): void
	{
		foreach ($toCalculate->getLineItems()->filterFlatByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
			if ($lineItem->hasChildren()) {
				$quantity = $lineItem->getQuantity();

				foreach ($lineItem->getChildren()->filterType(ConfiguratorLineItemHandler::TYPE) as $child) {
					$child->setQuantity($quantity);
				}
			}
		}
	}

	/**
	 * Adjust prices of child line items based on chosen possibilities
	 */
	private function adjustChildPrices(Cart $toCalculate, SalesChannelContext $context): void
	{
		foreach ($toCalculate->getLineItems()->filterFlatByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
			if (!$lineItem->hasChildren()) {
				continue;
			}

			$parentPrice = $lineItem->getPrice();

			if ($parentPrice === null) {
				continue;
			}

			$children = $lineItem->getChildren()->filterFlatByType(ConfiguratorLineItemHandler::TYPE);

			if ($children === []) {
				continue;
			}

			$lineItem->setPayloadValue('hmnetConfiguratorBasePrice', [
				'unit' => $parentPrice->getUnitPrice(),
				'total' => $parentPrice->getTotalPrice()
			]);

			$childTotalPrice = 0.0;
			$childTaxes = new CalculatedTaxCollection([]);

			foreach ($children as $child) {
				$childPrice = $this->buildChildPrice($child, $parentPrice->getTaxRules(), $context);
				$child->setPrice($childPrice);

				$childTotalPrice += $childPrice->getTotalPrice();
				$this->mergeTaxes($childTaxes, $childPrice->getCalculatedTaxes());
			}

			if ($childTotalPrice <= 0.0) {
				continue;
			}

			$quantity = max(1, $lineItem->getQuantity());
			$additionalUnitPrice = $childTotalPrice / $quantity;

			$parentTaxes = $this->cloneTaxes($parentPrice->getCalculatedTaxes());
			$this->mergeTaxes($parentTaxes, $childTaxes);

			// Include configurator child surcharges directly in the parent product price as cart totals only consider top-level items.
			$parentPrice->overwrite(
				$parentPrice->getUnitPrice() + $additionalUnitPrice,
				$parentPrice->getTotalPrice() + $childTotalPrice,
				$parentTaxes
			);
		}
	}

	private function buildChildPrice(LineItem $child, TaxRuleCollection $taxRules, SalesChannelContext $context): CalculatedPrice
	{
		$quantity = max(1, $child->getQuantity());
		$payload = $child->getPayload();
		$priceTiers = $payload['priceTiers'] ?? [];
		$multiplicator = (float) ($payload['multiplicator'] ?? 1.0);

		$unitPrice = FieldUtils::getPriceFromTiers($priceTiers, $quantity) * $multiplicator;
		$unitPrice = (float) max(0.0, $unitPrice);
		$totalPrice = $unitPrice * $quantity;

		$taxes = $this->calculateTaxes($totalPrice, $taxRules, $context);

		return new CalculatedPrice(
			$unitPrice,
			$totalPrice,
			$taxes,
			$taxRules,
			$quantity
		);
	}

	private function calculateTaxes(float $amount, TaxRuleCollection $taxRules, SalesChannelContext $context): CalculatedTaxCollection
	{
		if ($amount <= 0.0 || \count($taxRules) === 0) {
			return new CalculatedTaxCollection([]);
		}

		$taxState = $context->getTaxState();

		if ($taxState === CartPrice::TAX_STATE_FREE) {
			return new CalculatedTaxCollection([]);
		}

		if ($taxState === CartPrice::TAX_STATE_NET) {
			return $this->taxCalculator->calculateNetTaxes($amount, $taxRules);
		}

		return $this->taxCalculator->calculateGrossTaxes($amount, $taxRules);
	}

	private function mergeTaxes(CalculatedTaxCollection $target, CalculatedTaxCollection $source): void
	{
		foreach ($source as $tax) {
			$taxClone = new CalculatedTax($tax->getTax(), $tax->getTaxRate(), $tax->getPrice(), $tax->getLabel());
			$existing = $target->get((string) $tax->getTaxRate());

			if ($existing instanceof CalculatedTax) {
				$existing->increment($taxClone);

				continue;
			}

			$target->add($taxClone);
		}
	}

	private function cloneTaxes(CalculatedTaxCollection $taxes): CalculatedTaxCollection
	{
		$cloned = new CalculatedTaxCollection([]);

		foreach ($taxes as $tax) {
			$cloned->add(new CalculatedTax($tax->getTax(), $tax->getTaxRate(), $tax->getPrice(), $tax->getLabel()));
		}

		return $cloned;
	}

	/**
	 * @param EntityCollection<ConfiguratorFieldEntity> $fields
	 * @param array<string, string> $payload - fieldId => $chosenPossibilityId
	 * @param int $quantity
	 * @param SalesChannelContext $context
	 * @return array<\Shopware\Core\Checkout\Cart\LineItem\LineItem>
	 */
	private function generateChildrenFromFields(EntityCollection $fieldEntities, array $payload, int $quantity, SalesChannelContext $context): array
	{
		$children = [];

		foreach ($fieldEntities as $field) {
			$chosenPossibilityId = $payload[$field->id] ?? null;

			$child = $this->factory->create([
				'type' => ConfiguratorLineItemHandler::TYPE,
				'referencedId' => $field->id,
				'quantity' => $quantity,
				'possibilityId' => $chosenPossibilityId,
				'fieldEntity' => $field
			], $context);

			$children[] = $child;
		}

		return $children;
	}
}
