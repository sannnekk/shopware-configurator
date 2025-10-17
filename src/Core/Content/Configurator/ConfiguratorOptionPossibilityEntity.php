<?php

declare(strict_types=1);

namespace HMnet\Configurator\Core\Content\Configurator;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity as EntityAttribute;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\ForeignKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\ManyToOne;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Translations;
use Shopware\Core\Framework\Struct\ArrayEntity;

#[EntityAttribute(
	name: ConfiguratorOptionPossibilityEntity::ENTITY_NAME
)]
class ConfiguratorOptionPossibilityEntity extends Entity
{
	public const ENTITY_NAME = 'hmnet_configurator_option_possibility';

	#[PrimaryKey]
	#[Field(type: FieldType::UUID, api: true)]
	public string $id;

	#[Field(type: FieldType::STRING, api: true, translated: true)]
	public ?string $name = null;

	#[Field(type: FieldType::INT, api: true)]
	public int $position = 0;

	#[Field(type: FieldType::INT, api: true)]
	public int $multiplicator;

	#[ForeignKey(entity: ConfiguratorOptionEntity::ENTITY_NAME, column: 'option_id', api: true)]
	public ?string $optionId = null;

	#[ManyToOne(entity: ConfiguratorOptionEntity::ENTITY_NAME, ref: 'id', column: 'option_id', api: true)]
	public ?ConfiguratorOptionEntity $option = null;

	/**
	 * @var array<string, ArrayEntity>|null
	 */
	#[Translations]
	public ?array $translations = null;
}
