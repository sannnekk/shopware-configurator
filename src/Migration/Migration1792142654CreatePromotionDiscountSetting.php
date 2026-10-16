<?php

declare(strict_types=1);

namespace HMnet\Configurator\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1792142654CreatePromotionDiscountSetting extends MigrationStep
{
	public function getCreationTimestamp(): int
	{
		return 1792142654;
	}

	public function update(Connection $connection): void
	{
		$connection->executeStatement(
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `hmnet_promotion_discount_setting` (
	`id` BINARY(16) NOT NULL,
	`promotion_discount_id` BINARY(16) NOT NULL,
	`include_configurator_options` TINYINT(1) NOT NULL DEFAULT 0,
	`created_at` DATETIME(3) NOT NULL,
	`updated_at` DATETIME(3) NULL,
	PRIMARY KEY (`id`),
	UNIQUE KEY `uniq.hmnet_promotion_discount_setting.promotion_discount_id` (`promotion_discount_id`),
	CONSTRAINT `fk.hmnet_promotion_discount_setting.promotion_discount_id`
		FOREIGN KEY (`promotion_discount_id`)
		REFERENCES `promotion_discount` (`id`)
		ON UPDATE CASCADE
		ON DELETE CASCADE
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
SQL
		);
	}

	public function updateDestructive(Connection $connection): void
	{
		// No destructive changes for creation
	}
}
