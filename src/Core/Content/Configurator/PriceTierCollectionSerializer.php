<?php

declare(strict_types=1);

namespace HMnet\Configurator\Core\Content\Configurator;

use Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Field\SerializedField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldSerializer\AbstractFieldSerializer;
use Shopware\Core\Framework\DataAbstractionLayer\Write\DataStack\KeyValuePair;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteParameterBag;
use Shopware\Core\Framework\Util\Json;

class PriceTierCollectionSerializer extends AbstractFieldSerializer
{
	public function normalize(Field $field, array $data, WriteParameterBag $parameters): array
	{
		if (!$field instanceof SerializedField) {
			throw DataAbstractionLayerException::invalidSerializerField(SerializedField::class, $field);
		}

		$propertyName = $field->getPropertyName();

		if (!\array_key_exists($propertyName, $data)) {
			return $data;
		}

		$value = $data[$propertyName];

		if ($value instanceof PriceTierCollection) {
			$data[$propertyName] = $value->getTiers();
		}

		return $data;
	}

	public function encode(Field $field, EntityExistence $existence, KeyValuePair $data, WriteParameterBag $parameters): \Generator
	{
		if (!$field instanceof SerializedField) {
			throw DataAbstractionLayerException::invalidSerializerField(SerializedField::class, $field);
		}

		$this->validateIfNeeded($field, $existence, $data, $parameters);

		$value = $this->normalizeValue($data->getValue());

		yield $field->getStorageName() => $value !== null ? Json::encode($value) : null;
	}

	public function decode(Field $field, mixed $value): mixed
	{
		if (!$field instanceof SerializedField) {
			throw DataAbstractionLayerException::invalidSerializerField(SerializedField::class, $field);
		}

		if ($value === null || $value === '') {
			return new PriceTierCollection();
		}

		if (\is_string($value)) {
			$value = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
		}

		return new PriceTierCollection($this->normalizeValue($value) ?? []);
	}

	/**
	 * @return array<int, array{toQuantity: ?int, price: float}>|null
	 */
	private function normalizeValue(mixed $value): ?array
	{
		if ($value === null) {
			return null;
		}

		if ($value instanceof PriceTierCollection) {
			return $value->getTiers();
		}

		if (!\is_array($value)) {
			throw new \InvalidArgumentException('Price tiers must be an array or PriceTierCollection instance.');
		}

		return array_values(array_map(static function (array $tier): array {
			return [
				'toQuantity' => $tier['toQuantity'] ?? null,
				'price' => isset($tier['price']) ? (float) $tier['price'] : 0.0,
			];
		}, $value));
	}
}
