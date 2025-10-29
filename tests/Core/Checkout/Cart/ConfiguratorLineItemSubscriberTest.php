<?php

declare(strict_types=1);

namespace HMnet\Configurator\Tests\Core\Checkout\Cart;

use HMnet\Configurator\Core\Checkout\Cart\ConfiguratorCartCollector;
use HMnet\Configurator\Core\Checkout\Cart\ConfiguratorLineItemSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Test\Generator;

class ConfiguratorLineItemSubscriberTest extends TestCase
{
	public function testDifferentConfigurationsCreateDistinctLineItems(): void
	{
		$subscriber = new ConfiguratorLineItemSubscriber();
		$context = Generator::generateSalesChannelContext();
		$cart = new Cart('test-cart');

		$configurationA = ['field-a' => 'possibility-1'];
		$lineItemA = $this->createConfiguredLineItem('product-1', $configurationA);
		$cart->add($lineItemA);
		$subscriber->onBeforeLineItemAdded(new BeforeLineItemAddedEvent($lineItemA, $cart, $context, false));

		$expectedIdA = $this->buildExpectedId('product-1', $configurationA);
		$hashA = $this->buildHash($configurationA);

		static::assertNotNull($cart->getLineItems()->get($expectedIdA));
		static::assertSame($hashA, $cart->getLineItems()->get($expectedIdA)?->getPayloadValue('hmnetConfiguratorHash'));

		$configurationB = ['field-a' => 'possibility-2'];
		$lineItemB = $this->createConfiguredLineItem('product-1', $configurationB);
		$cart->add($lineItemB);
		$subscriber->onBeforeLineItemAdded(new BeforeLineItemAddedEvent($lineItemB, $cart, $context, false));

		$expectedIdB = $this->buildExpectedId('product-1', $configurationB);
		$hashB = $this->buildHash($configurationB);

		static::assertNotNull($cart->getLineItems()->get($expectedIdB));
		static::assertSame($hashB, $cart->getLineItems()->get($expectedIdB)?->getPayloadValue('hmnetConfiguratorHash'));
		static::assertCount(2, $cart->getLineItems());
	}

	public function testIdenticalConfigurationsStackQuantities(): void
	{
		$subscriber = new ConfiguratorLineItemSubscriber();
		$context = Generator::generateSalesChannelContext();
		$cart = new Cart('test-cart');

		$configuration = ['field-a' => 'possibility-1'];
		$expectedId = $this->buildExpectedId('product-1', $configuration);

		$firstLineItem = $this->createConfiguredLineItem('product-1', $configuration);
		$cart->add($firstLineItem);
		$subscriber->onBeforeLineItemAdded(new BeforeLineItemAddedEvent($firstLineItem, $cart, $context, false));

		$secondLineItem = $this->createConfiguredLineItem('product-1', $configuration);
		$cart->add($secondLineItem);
		$subscriber->onBeforeLineItemAdded(new BeforeLineItemAddedEvent($secondLineItem, $cart, $context, false));

		$stackedItem = $cart->getLineItems()->get($expectedId);

		static::assertNotNull($stackedItem);
		static::assertSame(2, $stackedItem?->getQuantity());
		static::assertSame($this->buildHash($configuration), $stackedItem?->getPayloadValue('hmnetConfiguratorHash'));
		static::assertCount(1, $cart->getLineItems());
	}

	/**
	 * @param array<string, string> $configuration
	 */
	private function createConfiguredLineItem(string $productId, array $configuration): LineItem
	{
		$lineItem = new LineItem($productId, LineItem::PRODUCT_LINE_ITEM_TYPE, $productId, 1);
		$lineItem->setPayloadValue(ConfiguratorCartCollector::PAYLOAD_KEY, $configuration);

		return $lineItem;
	}

	/**
	 * @param array<string, string> $configuration
	 */
	private function buildExpectedId(string $productId, array $configuration): string
	{
		return sprintf('%s-%s', $productId, $this->buildHash($configuration));
	}

	/**
	 * @param array<string, string> $configuration
	 */
	private function buildHash(array $configuration): string
	{
		ksort($configuration, SORT_STRING);

		return md5((string) json_encode($configuration, JSON_THROW_ON_ERROR));
	}
}
