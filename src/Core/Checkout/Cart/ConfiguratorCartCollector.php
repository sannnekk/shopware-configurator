<?php

declare(strict_types=1);

namespace HMnet\Configurator\Core\Checkout\Cart;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ConfiguratorCartCollector implements CartDataCollectorInterface
{
	public const PAYLOAD_KEY = 'hmnetProductConfigurator';

	private EntityRepository $fieldRepository;

	private LoggerInterface $logger;

	public function __construct(EntityRepository $fieldRepository, LoggerInterface $logger)
	{
		$this->fieldRepository = $fieldRepository;
		$this->logger = $logger;
	}

	public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
	{
		foreach ($original->getLineItems() as $lineItem) {
			$payload = $lineItem->getPayload()[self::PAYLOAD_KEY] ?? null;

			if (!$payload || $lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
				continue;
			}

			$fieldEntities = $this->fetchFieldEntities($payload, $context);
			$lineItemId = $lineItem->getId();

			$data->set($lineItemId, [$payload, $fieldEntities]);
			$lineItem->removePayloadValue(self::PAYLOAD_KEY);
		}
	}

	/**
	 * Fetch field entities based on the payload
	 * 
	 * @param array<string, string> $payload
	 * @param SalesChannelContext $context
	 * @return \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection
	 */
	private function fetchFieldEntities(array $payload, SalesChannelContext $context): EntityCollection
	{
		$criteria = new Criteria(array_keys($payload));
		$criteria->addAssociation('options.possibilities');
		$criteria->setLimit(50);

		return $this->fieldRepository->search($criteria, $context->getContext())->getEntities();
	}
}
