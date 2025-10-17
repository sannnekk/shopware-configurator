<?php

declare(strict_types=1);

namespace HMnet\Configurator\Storefront\Page\Product;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductConfiguratorSubscriber implements EventSubscriberInterface
{
	public function __construct(
		private readonly EntityRepository $configuratorFieldRepository
	) {}

	public static function getSubscribedEvents(): array
	{
		return [
			ProductPageLoadedEvent::class => 'addConfiguratorFields',
		];
	}

	public function addConfiguratorFields(ProductPageLoadedEvent $event): void
	{
		$product = $event->getPage()->getProduct();
		if ($product === null) {
			return;
		}

		$productId = $product->getId();
		if ($productId === null) {
			return;
		}

		$context = $event->getSalesChannelContext()->getContext();

		$criteria = (new Criteria())
			->addFilter(new EqualsFilter('productId', $productId))
			->addAssociation('options.possibilities')
			->addSorting(new FieldSorting('position'))
			->addSorting(new FieldSorting('options.position'))
			->addSorting(new FieldSorting('options.possibilities.position'));

		$fieldsResult = $this->configuratorFieldRepository->search($criteria, $context);
		$fields = $fieldsResult->getEntities();

		$product->addExtension('hmnetConfiguratorFields', $fields);
		$event->getPage()->addExtension('hmnetConfiguratorFields', $fields);
	}
}
