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
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Translations;
use Shopware\Core\Framework\Struct\ArrayEntity;

#[EntityAttribute(
	name: ConfiguratorFieldEntity::ENTITY_NAME
)]
class ConfiguratorFieldEntity extends Entity
{
	public const ENTITY_NAME = 'hmnet_configurator_field';

	#[PrimaryKey]
	#[Field(type: FieldType::UUID, api: true)]
	public string $id;

	#[Field(type: FieldType::STRING, api: true, translated: true)]
	public ?string $name = null;

	#[Field(type: FieldType::INT, api: true)]
	public ?int $position = null;

	#[Field(type: FieldType::BOOL, api: true)]
	public bool $isRequired;

	#[Field(type: FieldType::BOOL, api: true)]
	public bool $isVisible;

	/**
	 * @var array<string, ConfiguratorOptionEntity>|null
	 */
	#[OneToMany(
		entity: ConfiguratorOptionEntity::ENTITY_NAME,
		ref: 'fieldId',
		onDelete: OnDelete::CASCADE,
		api: true
	)]
	public ?array $options = null;

	#[ForeignKey(entity: 'product', api: true)]
	public ?string $productId = null;

	/**
	 * @var array<string, ArrayEntity>|null
	 */
	#[Translations]
	public ?array $translations = null;
}
