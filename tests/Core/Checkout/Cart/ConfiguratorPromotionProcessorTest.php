<?php

declare(strict_types=1);

namespace HMnet\Configurator\Tests\Core\Checkout\Cart;

use Doctrine\DBAL\Connection;
use HMnet\Configurator\Core\Checkout\Cart\ConfiguratorPromotionProcessor;
use HMnet\Configurator\Service\ConfiguratorLineItemHandler;
use HMnet\Configurator\Service\SetupFilmLineItemHandler;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\CashRounding;
use Shopware\Core\Checkout\Cart\Price\GrossPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\NetPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\PercentagePriceCalculator;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\PercentageTaxRuleBuilder;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Cart\Tax\TaxCalculator;
use Shopware\Core\Checkout\Promotion\Cart\PromotionProcessor;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Generator;

class ConfiguratorPromotionProcessorTest extends TestCase
{
	private string $discountId;

	private SalesChannelContext $context;

	private TaxRuleCollection $taxRules;

	protected function setUp(): void
	{
		$this->discountId = Uuid::randomHex();
		$this->context = Generator::generateSalesChannelContext();
		$this->taxRules = new TaxRuleCollection([new TaxRule(19.0)]);
	}

	public function testUnflaggedDiscountOnlyCoversBaseProduct(): void
	{
		$cart = $this->createCart(quantity: 10, discountedQuantity: 10);

		$this->createProcessor([])->process(new CartDataCollection(), $cart, $cart, $this->context, new CartBehavior());

		static::assertSame(-4.0, $this->getDiscount($cart)->getPrice()->getTotalPrice());
	}

	public function testFlaggedDiscountAlsoCoversOptions(): void
	{
		$cart = $this->createCart(quantity: 10, discountedQuantity: 10);

		$this->createProcessor([$this->discountId])->process(new CartDataCollection(), $cart, $cart, $this->context, new CartBehavior());

		$discount = $this->getDiscount($cart);

		// 4% of 100 € base + 4% of (40 € options + 10 € setup)
		static::assertSame(-6.0, $discount->getPrice()->getTotalPrice());
		static::assertEqualsWithDelta(-0.96, $discount->getPrice()->getCalculatedTaxes()->getAmount(), 0.01);
		static::assertEqualsWithDelta(6.0, $discount->getPayloadValue('composition')[0]['discount'], 0.0001);
	}

	public function testExtensionIsNotAppliedTwice(): void
	{
		$cart = $this->createCart(quantity: 10, discountedQuantity: 10);
		$processor = $this->createProcessor([$this->discountId]);

		$processor->process(new CartDataCollection(), $cart, $cart, $this->context, new CartBehavior());
		$processor->process(new CartDataCollection(), $cart, $cart, $this->context, new CartBehavior());

		static::assertSame(-6.0, $this->getDiscount($cart)->getPrice()->getTotalPrice());
	}

	public function testPartialQuantityScalesOptionShare(): void
	{
		$cart = $this->createCart(quantity: 10, discountedQuantity: 5);

		$this->createProcessor([$this->discountId])->process(new CartDataCollection(), $cart, $cart, $this->context, new CartBehavior());

		static::assertSame(-3.0, $this->getDiscount($cart)->getPrice()->getTotalPrice());
	}

	public function testMaxValueLimitsWholeDiscount(): void
	{
		$cart = $this->createCart(quantity: 10, discountedQuantity: 10, maxValue: '5');

		$this->createProcessor([$this->discountId])->process(new CartDataCollection(), $cart, $cart, $this->context, new CartBehavior());

		static::assertSame(-5.0, $this->getDiscount($cart)->getPrice()->getTotalPrice());
	}

	public function testAbsoluteDiscountIsIgnored(): void
	{
		$cart = $this->createCart(quantity: 10, discountedQuantity: 10, discountType: 'absolute');

		$connection = $this->createMock(Connection::class);
		$connection->expects(static::never())->method('fetchFirstColumn');

		$this->createProcessor([], $connection)->process(new CartDataCollection(), $cart, $cart, $this->context, new CartBehavior());

		static::assertSame(-4.0, $this->getDiscount($cart)->getPrice()->getTotalPrice());
	}

	/**
	 * @param list<string> $flaggedIds
	 */
	private function createProcessor(array $flaggedIds, ?Connection $connection = null): ConfiguratorPromotionProcessor
	{
		if ($connection === null) {
			$connection = $this->createStub(Connection::class);
			$connection->method('fetchFirstColumn')->willReturn($flaggedIds);
		}

		$rounding = new CashRounding();
		$taxCalculator = new TaxCalculator();

		return new ConfiguratorPromotionProcessor(
			$connection,
			new PercentagePriceCalculator(
				$rounding,
				new QuantityPriceCalculator(
					new GrossPriceCalculator($taxCalculator, $rounding),
					new NetPriceCalculator($taxCalculator, $rounding)
				),
				new PercentageTaxRuleBuilder()
			)
		);
	}

	/**
	 * Product: 10 € × quantity, options: 4 € × quantity, setup costs: 10 € once.
	 * The product price already contains the options, as done by the ConfiguratorCartProcessor.
	 */
	private function createCart(int $quantity, int $discountedQuantity, string $maxValue = '', string $discountType = 'percentage'): Cart
	{
		$product = new LineItem('product-1', LineItem::PRODUCT_LINE_ITEM_TYPE, Uuid::randomHex(), $quantity);
		$product->setStackable(true);

		$option = new LineItem(Uuid::randomHex(), ConfiguratorLineItemHandler::TYPE, null, $quantity);
		$option->setPrice($this->createPrice(4.0 * $quantity, $quantity));

		$setup = new LineItem('setup-1', SetupFilmLineItemHandler::TYPE, null, $quantity);
		$setup->setPrice($this->createPrice(10.0, 1));

		$product->addChild($option);
		$product->addChild($setup);
		$product->setPrice($this->createPrice(10.0 * $quantity + 4.0 * $quantity + 10.0, $quantity));

		$discount = new LineItem(Uuid::randomHex(), PromotionProcessor::LINE_ITEM_TYPE, null, 1);
		$discount->setPayload([
			'discountId' => $this->discountId,
			'discountType' => $discountType,
			'discountScope' => 'cart',
			'value' => '4',
			'maxValue' => $maxValue,
			'composition' => [
				['id' => 'product-1', 'quantity' => $discountedQuantity, 'discount' => 0.4 * $discountedQuantity],
			],
		]);
		$discount->setPrice($this->createPrice(-0.4 * $discountedQuantity, 1));

		$cart = new Cart('test');
		$cart->add($product);
		$cart->add($discount);

		return $cart;
	}

	private function createPrice(float $total, int $quantity): CalculatedPrice
	{
		return new CalculatedPrice(
			$total / $quantity,
			$total,
			(new TaxCalculator())->calculateGrossTaxes($total, $this->taxRules),
			$this->taxRules,
			$quantity
		);
	}

	private function getDiscount(Cart $cart): LineItem
	{
		$discount = $cart->getLineItems()->filterType(PromotionProcessor::LINE_ITEM_TYPE)->first();
		static::assertNotNull($discount);

		return $discount;
	}
}
