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
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;

class ConfiguratorCartProcessor implements CartProcessorInterface
{
	private ConfiguratorLineItemHandler $factory;

	private LoggerInterface $logger;

	public function __construct(ConfiguratorLineItemHandler $factory, LoggerInterface $logger)
	{
		$this->factory = $factory;
		$this->logger = $logger;
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
		foreach ($toCalculate->getLineItems() as $lineItem) {

			foreach ($lineItem->getChildren()->filterFlatByType(ConfiguratorLineItemHandler::TYPE) as $child) {
				$quantity = $child->getQuantity();
				$priceTiers = $child->getPayload()['priceTiers'] ?? [];
				$multiplicator = $child->getPayload()['multiplicator'] ?? 1.0;
				$taxCollection = $lineItem->getPrice()->getCalculatedTaxes();
				$taxRules = $lineItem->getPrice()->getTaxRules();

				$unitPrice = FieldUtils::getPriceFromTiers($priceTiers, $quantity) * $multiplicator;

				$calculatedPrice = new CalculatedPrice(
					$unitPrice,
					$unitPrice * $quantity,
					$taxCollection,
					$taxRules,
					$quantity
				);

				$child->setPrice($calculatedPrice);
			}
		}
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
