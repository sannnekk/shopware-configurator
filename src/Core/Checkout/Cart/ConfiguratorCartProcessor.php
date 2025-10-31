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
use HMnet\Configurator\Service\SetupFilmLineItemHandler;
use HMnet\Configurator\Utils\FieldUtils;
use HMnet\Configurator\Utils\PriceUtils;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Cart\Tax\TaxCalculator;

class ConfiguratorCartProcessor implements CartProcessorInterface
{
	private ConfiguratorLineItemHandler $configuratorFactory;

	private SetupFilmLineItemHandler $setupFilmFactory;

	private LoggerInterface $logger;

	private TaxCalculator $taxCalculator;

	public function __construct(ConfiguratorLineItemHandler $configuratorFactory, SetupFilmLineItemHandler $setupFilmFactory, LoggerInterface $logger, TaxCalculator $taxCalculator)
	{
		$this->configuratorFactory = $configuratorFactory;
		$this->setupFilmFactory = $setupFilmFactory;
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
		$this->addSetupAndFilmPrices($toCalculate, $context);
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
				PriceUtils::mergeTaxes($childTaxes, $childPrice->getCalculatedTaxes());
			}

			if ($childTotalPrice <= 0.0) {
				continue;
			}

			$quantity = max(1, $lineItem->getQuantity());
			$additionalUnitPrice = $childTotalPrice / $quantity;

			$parentTaxes = PriceUtils::cloneTaxes($parentPrice->getCalculatedTaxes());
			PriceUtils::mergeTaxes($parentTaxes, $childTaxes);

			// Include configurator child surcharges directly in the parent product price as cart totals only consider top-level items.
			$parentPrice->overwrite(
				$parentPrice->getUnitPrice() + $additionalUnitPrice,
				$parentPrice->getTotalPrice() + $childTotalPrice,
				$parentTaxes
			);
		}
	}

	/**
	 * Add setup and film prices
	 */
	private function addSetupAndFilmPrices(Cart $toCalculate, SalesChannelContext $context): void
	{
		foreach ($toCalculate->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
			$configuratorChildren = $lineItem->getChildren()->filterFlatByType(ConfiguratorLineItemHandler::TYPE);
			$taxRules = $lineItem->getPrice()->getTaxRules();
			$productId = $lineItem->getReferencedId();
			$productQuantity = $lineItem->getQuantity();

			if (count($configuratorChildren) === 0) {
				continue;
			}

			[$lineItemsWithSetupPrice, $lineItemsWithFilmPrice] = FieldUtils::getSetupAndFilmLineItems($configuratorChildren);

			$setupPriceLineItem = $this->setupFilmFactory->create([
				'productId' => $productId,
				'taxRules' => $taxRules,
				'type' => 'setup',
				'lineItems' => $lineItemsWithSetupPrice
			], $context);
			$setupPriceLineItem->setQuantity($productQuantity);

			$filmPriceLineItem = $this->setupFilmFactory->create([
				'productId' => $productId,
				'taxRules' => $taxRules,
				'type' => 'film',
				'lineItems' => $lineItemsWithFilmPrice
			], $context);
			$filmPriceLineItem->setQuantity($productQuantity);

			// attach setup/film line items as children when they contain positions
			$additionalTotal = 0.0;
			$additionalTaxes = new CalculatedTaxCollection([]);

			if ($setupPriceLineItem && $setupPriceLineItem->getPrice() && $setupPriceLineItem->getPrice()->getTotalPrice() > 0.0) {
				$lineItem->addChild($setupPriceLineItem);
				$additionalTotal += $setupPriceLineItem->getPrice()->getTotalPrice();
				PriceUtils::mergeTaxes($additionalTaxes, $setupPriceLineItem->getPrice()->getCalculatedTaxes());
			}

			if ($filmPriceLineItem && $filmPriceLineItem->getPrice() && $filmPriceLineItem->getPrice()->getTotalPrice() > 0.0) {
				$lineItem->addChild($filmPriceLineItem);
				$additionalTotal += $filmPriceLineItem->getPrice()->getTotalPrice();
				PriceUtils::mergeTaxes($additionalTaxes, $filmPriceLineItem->getPrice()->getCalculatedTaxes());
			}

			if ($additionalTotal <= 0.0) {
				continue;
			}

			$quantity = max(1, $lineItem->getQuantity());
			$additionalUnitPrice = $additionalTotal / $quantity;

			$parentPrice = $lineItem->getPrice();
			if ($parentPrice === null) {
				continue;
			}

			$parentTaxes = PriceUtils::cloneTaxes($parentPrice->getCalculatedTaxes());
			PriceUtils::mergeTaxes($parentTaxes, $additionalTaxes);

			$parentPrice->overwrite(
				$parentPrice->getUnitPrice() + $additionalUnitPrice,
				$parentPrice->getTotalPrice() + $additionalTotal,
				$parentTaxes
			);
		}
	}

	/**
	 * Build calculated price for a child line item
	 */
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

	/**
	 * Generates taxes for a given amount based on tax rules
	 */
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

	/**
	 * Generates child line items from field entities and payload
	 * 
	 * @param EntityCollection<ConfiguratorFieldEntity> $fields
	 * @param array<string, string> $payload `fieldId => $chosenPossibilityId`
	 * @param int $quantity
	 * @param SalesChannelContext $context
	 * @return array<\Shopware\Core\Checkout\Cart\LineItem\LineItem>
	 */
	private function generateChildrenFromFields(EntityCollection $fieldEntities, array $payload, int $quantity, SalesChannelContext $context): array
	{
		$children = [];

		foreach ($fieldEntities as $field) {
			$chosenPossibilityId = $payload[$field->id] ?? null;

			$child = $this->configuratorFactory->create([
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
