<?php

declare(strict_types=1);

namespace HMnet\Configurator\Core\Checkout\Cart;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use HMnet\Configurator\Service\ConfiguratorLineItemHandler;
use HMnet\Configurator\Service\SetupFilmLineItemHandler;
use HMnet\Configurator\Utils\PriceUtils;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\PercentagePriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Promotion\Aggregate\PromotionDiscount\PromotionDiscountEntity;
use Shopware\Core\Checkout\Promotion\Cart\PromotionProcessor;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Promotions are calculated (priority 4900) before the configurator adds option and setup/film
 * costs to the product price (priority 0), so by default a discount only applies to the base
 * product price.
 *
 * For percentage discounts flagged with "include configurator options" this processor adds the
 * missing discount on the option share of every discounted product afterwards.
 */
class ConfiguratorPromotionProcessor implements CartProcessorInterface, ResetInterface
{
	/**
	 * Payload key holding the discount total after it was extended. Used to detect items which
	 * already contain the extension, e.g. promotions restored from an order.
	 */
	public const PAYLOAD_EXTENDED_TOTAL = 'hmnetWholeCartTotal';

	private const SUPPORTED_SCOPES = [
		PromotionDiscountEntity::SCOPE_CART,
		PromotionDiscountEntity::SCOPE_SET,
		PromotionDiscountEntity::SCOPE_SETGROUP,
	];

	private const OPTION_TYPES = [
		ConfiguratorLineItemHandler::TYPE,
		SetupFilmLineItemHandler::TYPE,
	];

	/**
	 * @var array<string, list<string>>
	 */
	private array $flaggedDiscountIds = [];

	public function __construct(
		private readonly Connection $connection,
		private readonly PercentagePriceCalculator $percentageCalculator
	) {}

	public function process(
		CartDataCollection $data,
		Cart $original,
		Cart $toCalculate,
		SalesChannelContext $context,
		CartBehavior $behavior
	): void {
		$discountItems = $toCalculate->getLineItems()
			->filterType(PromotionProcessor::LINE_ITEM_TYPE)
			->filter(fn(LineItem $item) => $this->isSupported($item));

		if ($discountItems->count() === 0) {
			return;
		}

		$discountIds = array_values(array_unique(array_map(
			fn(LineItem $item) => (string) $item->getPayloadValue('discountId'),
			$discountItems->getElements()
		)));

		$flagged = $this->loadFlaggedDiscountIds($discountIds);

		foreach ($discountItems as $discountItem) {
			if (!\in_array($discountItem->getPayloadValue('discountId'), $flagged, true)) {
				continue;
			}

			$this->extendDiscount($discountItem, $toCalculate, $context);
		}
	}

	public function reset(): void
	{
		$this->flaggedDiscountIds = [];
	}

	private function isSupported(LineItem $item): bool
	{
		$payload = $item->getPayload();

		return ($payload['discountType'] ?? null) === PromotionDiscountEntity::TYPE_PERCENTAGE
			&& \in_array($payload['discountScope'] ?? null, self::SUPPORTED_SCOPES, true)
			&& Uuid::isValid((string) ($payload['discountId'] ?? ''));
	}

	/**
	 * Adds percentage × option share of all discounted products to the discount line item
	 */
	private function extendDiscount(LineItem $discountItem, Cart $toCalculate, SalesChannelContext $context): void
	{
		$price = $discountItem->getPrice();

		if ($price === null || $this->isAlreadyExtended($discountItem, $price)) {
			return;
		}

		$percentage = abs((float) $discountItem->getPayloadValue('value'));
		$composition = $discountItem->getPayloadValue('composition');

		if ($percentage <= 0.0 || !\is_array($composition)) {
			return;
		}

		$optionPrices = new PriceCollection();
		$optionTotals = [];

		foreach ($composition as $index => $entry) {
			$product = $toCalculate->getLineItems()->get((string) ($entry['id'] ?? ''));

			if ($product === null) {
				continue;
			}

			$optionPrice = $this->getOptionPrice($product, (int) ($entry['quantity'] ?? 0));

			if ($optionPrice === null) {
				continue;
			}

			$optionPrices->add($optionPrice);
			$optionTotals[$index] = $optionPrice->getTotalPrice();
		}

		$optionTotal = array_sum($optionTotals);

		if ($optionTotal <= 0.0) {
			return;
		}

		$extra = $this->percentageCalculator->calculate(-$percentage, $optionPrices, $context);
		$extra = $this->limitToMaxValue($extra, $price, $discountItem->getPayloadValue('maxValue'), $context);

		if ($extra === null || $extra->getTotalPrice() === 0.0) {
			return;
		}

		$taxes = PriceUtils::cloneTaxes($price->getCalculatedTaxes());
		PriceUtils::mergeTaxes($taxes, $extra->getCalculatedTaxes());

		$extendedPrice = new CalculatedPrice(
			$price->getUnitPrice() + $extra->getTotalPrice(),
			$price->getTotalPrice() + $extra->getTotalPrice(),
			$taxes,
			$price->getTaxRules()->count() > 0 ? $price->getTaxRules() : $extra->getTaxRules(),
			$price->getQuantity()
		);

		$discountItem->setPrice($extendedPrice);
		$discountItem->setPayloadValue(self::PAYLOAD_EXTENDED_TOTAL, $extendedPrice->getTotalPrice());

		// Spread the extra discount over the composition so orders and refunds show it per product
		foreach ($optionTotals as $index => $itemOptionTotal) {
			$share = abs($extra->getTotalPrice()) * $itemOptionTotal / $optionTotal;
			$composition[$index]['discount'] = (float) ($composition[$index]['discount'] ?? 0.0) + $share;
		}

		$discountItem->setPayloadValue('composition', $composition);
	}

	private function isAlreadyExtended(LineItem $discountItem, CalculatedPrice $price): bool
	{
		$extendedTotal = $discountItem->getPayloadValue(self::PAYLOAD_EXTENDED_TOTAL);

		return $extendedTotal !== null && abs((float) $extendedTotal - $price->getTotalPrice()) < 0.0001;
	}

	/**
	 * Builds the price of the option and setup/film children of a product, scaled to the
	 * discounted quantity, as a promotion may only cover some units of a line item.
	 */
	private function getOptionPrice(LineItem $product, int $discountedQuantity): ?CalculatedPrice
	{
		$quantity = max(1, $product->getQuantity());
		$ratio = min(1.0, max(0, $discountedQuantity) / $quantity);

		if ($ratio <= 0.0) {
			return null;
		}

		$total = 0.0;
		$taxes = new CalculatedTaxCollection([]);
		$children = $product->getChildren()->filter(
			static fn(LineItem $child) => \in_array($child->getType(), self::OPTION_TYPES, true)
		);

		foreach ($children as $child) {
			$childPrice = $child->getPrice();

			if ($childPrice === null) {
				continue;
			}

			$total += $childPrice->getTotalPrice();
			PriceUtils::mergeTaxes($taxes, $childPrice->getCalculatedTaxes());
		}

		if ($total <= 0.0) {
			return null;
		}

		$taxRules = $product->getPrice()?->getTaxRules() ?? $children->first()?->getPrice()?->getTaxRules();

		return new CalculatedPrice(
			$total * $ratio,
			$total * $ratio,
			$this->scaleTaxes($taxes, $ratio),
			$taxRules ?? new TaxRuleCollection(),
			1
		);
	}

	/**
	 * The configured max value applies to the whole discount, including the base product share
	 */
	private function limitToMaxValue(CalculatedPrice $extra, CalculatedPrice $price, mixed $maxValue, SalesChannelContext $context): ?CalculatedPrice
	{
		if ($maxValue === null || $maxValue === '') {
			return $extra;
		}

		$allowed = (float) $maxValue - abs($price->getTotalPrice());

		if ($allowed <= 0.0) {
			return null;
		}

		$extraTotal = abs($extra->getTotalPrice());

		if ($extraTotal <= $allowed) {
			return $extra;
		}

		$decimals = $context->getItemRounding()->getDecimals();
		$ratio = $allowed / $extraTotal;
		$limited = -round($allowed, $decimals);

		return new CalculatedPrice(
			$limited,
			$limited,
			$this->scaleTaxes($extra->getCalculatedTaxes(), $ratio, $decimals),
			$extra->getTaxRules(),
			1
		);
	}

	private function scaleTaxes(CalculatedTaxCollection $taxes, float $ratio, ?int $decimals = null): CalculatedTaxCollection
	{
		$scaled = new CalculatedTaxCollection([]);

		foreach ($taxes as $tax) {
			$amount = $tax->getTax() * $ratio;
			$price = $tax->getPrice() * $ratio;

			$scaled->add(new CalculatedTax(
				$decimals === null ? $amount : round($amount, $decimals),
				$tax->getTaxRate(),
				$decimals === null ? $price : round($price, $decimals),
				$tax->getLabel()
			));
		}

		return $scaled;
	}

	/**
	 * @param list<string> $discountIds
	 *
	 * @return list<string>
	 */
	private function loadFlaggedDiscountIds(array $discountIds): array
	{
		sort($discountIds);
		$cacheKey = implode(',', $discountIds);

		if (\array_key_exists($cacheKey, $this->flaggedDiscountIds)) {
			return $this->flaggedDiscountIds[$cacheKey];
		}

		/** @var list<string> $flagged */
		$flagged = $this->connection->fetchFirstColumn(
			'SELECT LOWER(HEX(`promotion_discount_id`))
			FROM `hmnet_promotion_discount_setting`
			WHERE `include_configurator_options` = 1
			AND `promotion_discount_id` IN (:ids)',
			['ids' => Uuid::fromHexToBytesList($discountIds)],
			['ids' => ArrayParameterType::BINARY]
		);

		return $this->flaggedDiscountIds[$cacheKey] = $flagged;
	}
}
