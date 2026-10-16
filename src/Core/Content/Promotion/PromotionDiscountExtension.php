<?php

declare(strict_types=1);

namespace HMnet\Configurator\Core\Content\Promotion;

use Shopware\Core\Checkout\Promotion\Aggregate\PromotionDiscount\PromotionDiscountDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class PromotionDiscountExtension extends EntityExtension
{
	public const EXTENSION_NAME = 'hmnetSetting';

	public function getDefinitionClass(): string
	{
		return PromotionDiscountDefinition::class;
	}

	public function getEntityName(): string
	{
		return PromotionDiscountDefinition::ENTITY_NAME;
	}

	public function extendFields(FieldCollection $collection): void
	{
		$collection->add(
			(new OneToOneAssociationField(
				self::EXTENSION_NAME,
				'id',
				'promotion_discount_id',
				PromotionDiscountSettingEntity::ENTITY_NAME,
				false
			))->addFlags(new ApiAware(), new CascadeDelete())
		);
	}
}
