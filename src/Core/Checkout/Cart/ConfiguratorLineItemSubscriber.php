<?php

declare(strict_types=1);

namespace HMnet\Configurator\Core\Checkout\Cart;

use HMnet\Configurator\Core\Checkout\Cart\ConfiguratorCartCollector as Collector;
use JsonException;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ConfiguratorLineItemSubscriber implements EventSubscriberInterface
{
	/**
	 * @return array<string, string>
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			BeforeLineItemAddedEvent::class => 'onBeforeLineItemAdded',
		];
	}

	public function onBeforeLineItemAdded(BeforeLineItemAddedEvent $event): void
	{
		$lineItem = $event->getLineItem();

		if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
			return;
		}

		if (!$lineItem->hasPayloadValue(Collector::PAYLOAD_KEY)) {
			return;
		}

		$configuration = $lineItem->getPayloadValue(Collector::PAYLOAD_KEY);

		if (!\is_array($configuration) || $configuration === []) {
			return;
		}

		ksort($configuration, SORT_STRING);

		try {
			$encoded = json_encode($configuration, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return;
		}

		if ($encoded === false || $encoded === 'null') {
			return;
		}

		$hash = md5($encoded);
		$baseId = $lineItem->getReferencedId() ?? $lineItem->getId();
		$newId = sprintf('%s-%s', $baseId, $hash);
		$currentId = $lineItem->getId();
		$cart = $event->getCart();

		if (!$event->isMerged()) {
			// Replace the freshly added line item (still using the raw product id)
			$cart->getLineItems()->remove($currentId);

			$lineItem->setId($newId);
			$lineItem->setPayloadValue('hmnetConfiguratorHash', $hash);

			$cart->add($lineItem);

			return;
		}

		$existing = $cart->getLineItems()->get($currentId);

		if ($existing === null) {
			$lineItem->setId($newId);
			$lineItem->setPayloadValue('hmnetConfiguratorHash', $hash);

			$cart->add($lineItem);

			return;
		}

		if ($newId === $currentId) {
			$existing->setPayloadValue(Collector::PAYLOAD_KEY, $configuration);
			$existing->setPayloadValue('hmnetConfiguratorHash', $hash);

			return;
		}

		$quantityToSplit = $lineItem->getQuantity();
		$remainingQuantity = $existing->getQuantity() - $quantityToSplit;

		if ($remainingQuantity <= 0) {
			$cart->getLineItems()->remove($currentId);
		} else {
			$existing->setQuantity($remainingQuantity);
		}

		$lineItem->setId($newId);
		$lineItem->setPayloadValue('hmnetConfiguratorHash', $hash);

		$cart->add($lineItem);
	}
}
