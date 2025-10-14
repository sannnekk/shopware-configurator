<?php

declare(strict_types=1);

namespace HMnet\Configurator\Core\Content\Configurator;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity as EntityAttribute;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\ForeignKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\OnDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\OneToMany;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Serialized;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Translations;
use Shopware\Core\Framework\Struct\ArrayEntity;

#[EntityAttribute(
	name: ConfiguratorOptionEntity::ENTITY_NAME
)]
class ConfiguratorOptionEntity extends Entity
{
	public const ENTITY_NAME = 'hmnet_configurator_option';

	#[PrimaryKey]
	#[Field(type: FieldType::UUID, api: true)]
	public string $id;

	#[Field(type: FieldType::STRING, api: true, translated: true)]
	public ?string $name = null;

	#[Field(type: FieldType::INT, api: true)]
	public ?int $position = null;

	/**
	 * @var array<string, ConfiguratorOptionPossibilityEntity>|null
	 */
	#[OneToMany(
		entity: ConfiguratorOptionPossibilityEntity::ENTITY_NAME,
		ref: 'optionId',
		onDelete: OnDelete::CASCADE,
		api: true
	)]
	public ?array $possibilities = null;

	#[Field(type: FieldType::JSON, api: true)]
	#[Serialized(serializer: PriceTierCollectionSerializer::class)]
	public PriceTierCollection $priceTiers;

	#[ForeignKey(entity: ConfiguratorFieldEntity::ENTITY_NAME, api: true)]
	public ?string $fieldId = null;

	/**
	 * @var array<string, ArrayEntity>|null
	 */
	#[Translations]
	public ?array $translations = null;
}
