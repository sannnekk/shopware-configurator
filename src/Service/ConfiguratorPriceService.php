<?php

declare(strict_types=1);

namespace HMnet\Configurator\Service;

use HMnet\Configurator\Core\Content\Configurator\ConfiguratorFieldEntity;
use HMnet\Configurator\Utils\FieldUtils;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ConfiguratorPriceService
{
	public function __construct(
		private readonly EntityRepository $productRepository,
		private readonly EntityRepository $configuratorFieldRepository
	) {}

	/**
	 * Calculate all prices for a product configuration
	 *
	 * @param string $productId
	 * @param int $quantity
	 * @param array<string, string> $selection fieldId => possibilityId
	 * @param SalesChannelContext $context
	 * @return array{
	 *     success: bool,
	 *     minimumQuantity: int|null,
	 *     quantityAdjusted: bool,
	 *     adjustedQuantity: int,
	 *     minimumQuantityHint: string|null,
	 *     product: array{unitNet: float, quantity: int, totalNet: float},
	 *     options: array<array{fieldId: string, optionId: string, possibilityId: string, label: string, unitNet: float, quantity: int, totalNet: float}>,
	 *     surcharges: array<array{type: string, label: string, amount: float}>,
	 *     totals: array{netTotal: float, taxAmount: float, grossTotal: float, taxRate: float},
	 *     currencySymbol: string,
	 *     currencyDecimals: int
	 * }
	 */
	public function calculate(
		string $productId,
		int $quantity,
		array $selection,
		SalesChannelContext $context
	): array {
		$product = $this->fetchProduct($productId, $context);

		if (!$product instanceof ProductEntity) {
			return [
				'success' => false,
				'error' => 'Product not found',
			];
		}

		$fields = $this->fetchConfiguratorFields($productId, $context);
		$currencyId = $context->getCurrencyId();
		$taxRate = (float) ($product->getTax()?->getTaxRate() ?? 0.0);
		$currencySymbol = $context->getCurrency()->getSymbol();
		$currencyDecimals = $context->getCurrency()->getItemRounding()->getDecimals();

		// Determine minimum quantity based on selected options
		$minQuantityResult = $this->determineMinimumQuantity($fields, $selection, $quantity);
		$effectiveQuantity = $minQuantityResult['effectiveQuantity'];
		$minimumQuantity = $minQuantityResult['minimumQuantity'];
		$quantityAdjusted = $minQuantityResult['adjusted'];
		$minimumQuantityHint = $minQuantityResult['hint'];

		// Calculate product price
		$productUnit = $this->getProductUnitPrice($product, $currencyId, $effectiveQuantity);
		$productTotal = $productUnit * $effectiveQuantity;

		// Calculate option prices
		$optionLines = [];
		$surcharges = [];

		foreach ($fields as $field) {
			$fieldId = $field->id;
			$possibilityId = $selection[$fieldId] ?? null;

			if (!$possibilityId) {
				continue;
			}

			[$option, $possibility] = FieldUtils::getOptionAndPossibility($field, $possibilityId);

			if (!$option || !$possibility) {
				continue;
			}

			$multiplicator = (float) ($possibility->multiplicator ?? 1.0);
			$priceTiers = $option->priceTiers?->getTiers() ?? [];
			$optionUnit = FieldUtils::getPriceFromTiers($priceTiers, $effectiveQuantity) * $multiplicator;
			$optionTotal = $optionUnit * $effectiveQuantity;

			$optionLines[] = [
				'fieldId' => $fieldId,
				'optionId' => $option->id,
				'possibilityId' => $possibilityId,
				'label' => sprintf('%s: %s %s', $field->name ?? '', $option->name ?? '', $possibility->name ?? ''),
				'unitNet' => $optionUnit,
				'quantity' => $effectiveQuantity,
				'totalNet' => $optionTotal,
			];

			$setupPrice = ((float) ($option->setupPrice ?? 0.0)) * $multiplicator;
			$filmPrice = ((float) ($option->filmPrice ?? 0.0)) * $multiplicator;

			if ($setupPrice > 0) {
				$surcharges[] = [
					'type' => 'setup',
					'label' => sprintf('Einrichtung: %s %s', $option->name ?? '', $possibility->name ?? ''),
					'amount' => $setupPrice,
				];
			}

			if ($filmPrice > 0) {
				$surcharges[] = [
					'type' => 'film',
					'label' => sprintf('Film: %s %s', $option->name ?? '', $possibility->name ?? ''),
					'amount' => $filmPrice,
				];
			}
		}

		$optionTotalNet = array_sum(array_column($optionLines, 'totalNet'));
		$surchargeTotalNet = array_sum(array_column($surcharges, 'amount'));

		$netTotal = $productTotal + $optionTotalNet + $surchargeTotalNet;
		$taxAmount = $netTotal * ($taxRate / 100);
		$grossTotal = $netTotal + $taxAmount;

		return [
			'success' => true,
			'minimumQuantity' => $minimumQuantity,
			'quantityAdjusted' => $quantityAdjusted,
			'adjustedQuantity' => $effectiveQuantity,
			'minimumQuantityHint' => $minimumQuantityHint,
			'product' => [
				'unitNet' => $productUnit,
				'quantity' => $effectiveQuantity,
				'totalNet' => $productTotal,
			],
			'options' => $optionLines,
			'surcharges' => $surcharges,
			'totals' => [
				'netTotal' => $netTotal,
				'taxAmount' => $taxAmount,
				'grossTotal' => $grossTotal,
				'taxRate' => $taxRate,
			],
			'currencySymbol' => $currencySymbol,
			'currencyDecimals' => $currencyDecimals,
		];
	}

	/**
	 * Determine the minimum quantity based on selected options' price tiers
	 *
	 * @param array<ConfiguratorFieldEntity> $fields
	 * @param array<string, string> $selection
	 * @param int $requestedQuantity
	 * @return array{minimumQuantity: int|null, effectiveQuantity: int, adjusted: bool, hint: string|null}
	 */
	public function determineMinimumQuantity(array $fields, array $selection, int $requestedQuantity): array
	{
		$minimumQuantity = 1;
		$optionsRequiringMinimum = [];

		foreach ($fields as $field) {
			$fieldId = $field->id;
			$possibilityId = $selection[$fieldId] ?? null;

			if (!$possibilityId) {
				continue;
			}

			[$option, $possibility] = FieldUtils::getOptionAndPossibility($field, $possibilityId);

			if (!$option) {
				continue;
			}

			$priceTiers = $option->priceTiers?->getTiers() ?? [];

			if (empty($priceTiers)) {
				continue;
			}

			// Find the lowest quantityStart among all tiers
			$lowestStart = null;
			foreach ($priceTiers as $tier) {
				$start = $tier['quantityStart'] ?? 1;
				if ($lowestStart === null || $start < $lowestStart) {
					$lowestStart = $start;
				}
			}

			if ($lowestStart !== null && $lowestStart > $minimumQuantity) {
				$minimumQuantity = $lowestStart;
				$optionsRequiringMinimum[] = [
					'option' => $option->name ?? '',
					'possibility' => $possibility->name ?? '',
					'minQuantity' => $lowestStart,
				];
			}
		}

		$effectiveQuantity = max($requestedQuantity, $minimumQuantity);
		$adjusted = $effectiveQuantity > $requestedQuantity;

		$hint = null;
		if ($adjusted && !empty($optionsRequiringMinimum)) {
			$optionNames = array_map(
				fn($o) => trim($o['option'] . ' ' . $o['possibility']),
				$optionsRequiringMinimum
			);
			$hint = sprintf(
				'Die gewählte Konfiguration ist erst ab %d Stück verfügbar (%s).',
				$minimumQuantity,
				implode(', ', $optionNames)
			);
		}

		return [
			'minimumQuantity' => $minimumQuantity > 1 ? $minimumQuantity : null,
			'effectiveQuantity' => $effectiveQuantity,
			'adjusted' => $adjusted,
			'hint' => $hint,
		];
	}

	/**
	 * Get the minimum quantity for the current selection
	 *
	 * @param string $productId
	 * @param array<string, string> $selection
	 * @param SalesChannelContext $context
	 * @return int
	 */
	public function getMinimumQuantity(string $productId, array $selection, SalesChannelContext $context): int
	{
		$fields = $this->fetchConfiguratorFields($productId, $context);
		$result = $this->determineMinimumQuantity($fields, $selection, 1);

		return $result['effectiveQuantity'];
	}

	private function fetchProduct(string $productId, SalesChannelContext $context): ?ProductEntity
	{
		if ($productId === '' || !Uuid::isValid($productId)) {
			return null;
		}

		$criteria = (new Criteria([$productId]))
			->addAssociation('prices')
			->addAssociation('price')
			->addAssociation('tax');

		return $this->productRepository->search($criteria, $context->getContext())->first();
	}

	/**
	 * @return array<ConfiguratorFieldEntity>
	 */
	private function fetchConfiguratorFields(string $productId, SalesChannelContext $context): array
	{
		$criteria = (new Criteria())
			->addFilter(new EqualsFilter('productId', $productId))
			->addAssociation('options.possibilities')
			->addSorting(new FieldSorting('position'))
			->addSorting(new FieldSorting('options.position'))
			->addSorting(new FieldSorting('options.possibilities.position'));

		return $this->configuratorFieldRepository->search($criteria, $context->getContext())->getElements();
	}

	private function getProductUnitPrice(ProductEntity $product, string $currencyId, int $quantity): float
	{
		$prices = $product->getPrices();

		if ($prices) {
			foreach ($prices as $priceRule) {
				$start = $priceRule->getQuantityStart();
				$end = $priceRule->getQuantityEnd();
				$matchesQuantity = ($start === null || $quantity >= $start) && ($end === null || $quantity <= $end);

				if (!$matchesQuantity) {
					continue;
				}

				$price = $priceRule->getPrice()->getCurrencyPrice($currencyId, false) ?? $priceRule->getPrice()->first();

				return (float) ($price?->getNet() ?? 0.0);
			}
		}

		$basePrice = $product->getPrice();
		$price = $basePrice?->getCurrencyPrice($currencyId, true) ?? $basePrice?->first();

		return (float) ($price?->getNet() ?? 0.0);
	}
}
