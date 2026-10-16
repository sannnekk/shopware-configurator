<?php

declare(strict_types=1);

namespace HMnet\Configurator\Core\Content\Promotion;

use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity as EntityAttribute;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\ForeignKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/**
 * Per-discount settings of the configurator, attached to `promotion_discount` as `hmnetSetting`.
 */
#[EntityAttribute(
	name: PromotionDiscountSettingEntity::ENTITY_NAME
)]
class PromotionDiscountSettingEntity extends Entity
{
	public const ENTITY_NAME = 'hmnet_promotion_discount_setting';

	#[PrimaryKey]
	#[Field(type: FieldType::UUID, api: true)]
	public string $id;

	#[ForeignKey(entity: 'promotion_discount', api: true, column: 'promotion_discount_id')]
	public string $promotionDiscountId;

	/**
	 * When enabled, a percentage discount also applies to configurator options and setup/film costs,
	 * otherwise it only applies to the base product price.
	 */
	#[Field(type: FieldType::BOOL, api: true, column: 'include_configurator_options')]
	public bool $includeConfiguratorOptions = false;
}
