<?php

declare(strict_types=1);

namespace HMnet\Configurator\Core\Content\Product;

use HMnet\Configurator\Core\Content\Configurator\ConfiguratorFieldEntity;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class ConfiguratorProductExtension extends EntityExtension
{
	public function getDefinitionClass(): string
	{
		return ProductDefinition::class;
	}

	public function getEntityName(): string
	{
		return ProductDefinition::ENTITY_NAME;
	}

	public function extendFields(FieldCollection $collection): void
	{
		$collection->add(
			(new OneToManyAssociationField(
				'hmnetConfiguratorOptions',
				ConfiguratorFieldEntity::ENTITY_NAME,
				'product_id'
			))
		);
	}
}
