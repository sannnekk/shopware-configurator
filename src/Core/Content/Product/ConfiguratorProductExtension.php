<?php

declare(strict_types=1);

namespace HMnet\Configurator\Core\Content\Product;

use HMnet\Configurator\Core\Content\Configurator\ConfiguratorFieldEntity;
use HMnet\Configurator\Core\Content\Configurator\ConfiguratorOptionDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Symfony\Flex\Configurator;

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
				ConfiguratorFieldEntity::class,
				'product_id'
			))
		);
	}
}
