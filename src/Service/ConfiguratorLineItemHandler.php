<?php

declare(strict_types=1);

namespace HMnet\Configurator\Service;

use HMnet\Configurator\Utils\FieldUtils;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryHandler\LineItemFactoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ConfiguratorLineItemHandler implements LineItemFactoryInterface
{
	public const TYPE = 'hmnet-configurator';

	public function supports($type): bool
	{
		return $type === self::TYPE;
	}

	public function create(array $data, SalesChannelContext $context): LineItem
	{
		$lineItem = new LineItem($data['possibilityId'], self::TYPE, null, 1);

		$fieldEntity = $data['fieldEntity'] ?? null;
		$possibilityId = $data['possibilityId'] ?? null;

		[$setupPrice, $filmPrice] = $this->getSetupAndFilmPrice($fieldEntity, $possibilityId);

		$lineItem->setLabel($this->getLabel($fieldEntity, $possibilityId));
		$lineItem->setStackable(true);
		$lineItem->setRemovable(false);
		$lineItem->setQuantity($data['quantity'] ?? 1);
		$lineItem->setPayload(array_merge(
			$this->getLabelParts($fieldEntity, $possibilityId),
			[
				"priceTiers" => $this->getPriceTiers($fieldEntity, $possibilityId),
				"multiplicator" => $this->getMultiplicator($fieldEntity, $possibilityId),
				"setupPrice" => $setupPrice,
				"filmPrice" => $filmPrice
			]
		));

		return $lineItem;
	}

	public function update(LineItem $lineItem, array $data, SalesChannelContext $context): void
	{
		// TODO: Implement
	}

	private function getLabel(Entity $fieldEntity, string $possibilityId): string
	{
		[$option, $possibility] = FieldUtils::getOptionAndPossibility($fieldEntity, $possibilityId);

		if ($option && $possibility) {
			return sprintf('%s: %s %s', $fieldEntity->name, $option->name, $possibility->name);
		}

		return '---';
	}

	/**
	 * The single-string label is kept for documents and the administration, but the storefront
	 * renders the field name and the chosen value separately. Exposing the parts here avoids
	 * parsing the label back apart in Twig.
	 *
	 * @return array{fieldName: string|null, optionName: string|null, possibilityName: string|null}
	 */
	private function getLabelParts(Entity $fieldEntity, string $possibilityId): array
	{
		[$option, $possibility] = FieldUtils::getOptionAndPossibility($fieldEntity, $possibilityId);

		return [
			'fieldName' => $fieldEntity->name,
			'optionName' => $option?->name,
			'possibilityName' => $possibility?->name,
		];
	}

	private function getPriceTiers(Entity $fieldEntity, string $possibilityId): array
	{
		[$option,] = FieldUtils::getOptionAndPossibility($fieldEntity, $possibilityId);

		return $option?->priceTiers?->getTiers() ?? [];
	}

	private function getMultiplicator(Entity $fieldEntity, string $possibilityId): float
	{
		[, $possibility] = FieldUtils::getOptionAndPossibility($fieldEntity, $possibilityId);

		return $possibility?->multiplicator ?? 1.0;
	}

	private function getSetupAndFilmPrice(Entity $fieldEntity, string $possibilityId): array
	{
		[$option,] = FieldUtils::getOptionAndPossibility($fieldEntity, $possibilityId);

		return [
			$option?->setupPrice ?? 0.0,
			$option?->filmPrice ?? 0.0
		];
	}
}
