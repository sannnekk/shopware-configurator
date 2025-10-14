<?php

declare(strict_types=1);

namespace HMnet\Configurator\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1759997554CreateConfiguratorSchema extends MigrationStep
{
	public function getCreationTimestamp(): int
	{
		return 1759997554;
	}

	public function update(Connection $connection): void
	{
		$this->createConfiguratorFieldTable($connection);
		$this->createConfiguratorFieldTranslationTable($connection);
		$this->createConfiguratorOptionTable($connection);
		$this->createConfiguratorOptionTranslationTable($connection);
		$this->createConfiguratorOptionPossibilityTable($connection);
		$this->createConfiguratorOptionPossibilityTranslationTable($connection);
	}

	public function updateDestructive(Connection $connection): void
	{
		// No destructive changes for creation
	}

	private function createConfiguratorFieldTable(Connection $connection): void
	{
		$connection->executeStatement(
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `hmnet_configurator_field` (
	`id` BINARY(16) NOT NULL,
	`product_id` BINARY(16) NULL,
	`position` INT NULL,
	`is_required` TINYINT(1) NOT NULL DEFAULT 0,
	`is_visible` TINYINT(1) NOT NULL DEFAULT 1,
	`created_at` DATETIME(3) NOT NULL,
	`updated_at` DATETIME(3) NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk.hmnet_configurator_field.product_id`
		FOREIGN KEY (`product_id`)
		REFERENCES `product` (`id`)
		ON UPDATE CASCADE
		ON DELETE CASCADE
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
SQL
		);
	}

	private function createConfiguratorFieldTranslationTable(Connection $connection): void
	{
		$connection->executeStatement(
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `hmnet_configurator_field_translation` (
	`hmnet_configurator_field_id` BINARY(16) NOT NULL,
	`language_id` BINARY(16) NOT NULL,
	`name` VARCHAR(255) NULL,
	`created_at` DATETIME(3) NOT NULL,
	`updated_at` DATETIME(3) NULL,
	PRIMARY KEY (`hmnet_configurator_field_id`, `language_id`),
	CONSTRAINT `fk.hmnet_configurator_field_translation.field_id`
		FOREIGN KEY (`hmnet_configurator_field_id`)
		REFERENCES `hmnet_configurator_field` (`id`)
		ON UPDATE CASCADE
		ON DELETE CASCADE,
	CONSTRAINT `fk.hmnet_configurator_field_translation.language_id`
		FOREIGN KEY (`language_id`)
		REFERENCES `language` (`id`)
		ON UPDATE CASCADE
		ON DELETE CASCADE
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
SQL
		);
	}

	private function createConfiguratorOptionTable(Connection $connection): void
	{
		$connection->executeStatement(
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `hmnet_configurator_option` (
	`id` BINARY(16) NOT NULL,
	`field_id` BINARY(16) NOT NULL,
	`position` INT NULL,
	`price_tiers` JSON NOT NULL DEFAULT (JSON_ARRAY()),
	`created_at` DATETIME(3) NOT NULL,
	`updated_at` DATETIME(3) NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk.hmnet_configurator_option.field_id`
		FOREIGN KEY (`field_id`)
		REFERENCES `hmnet_configurator_field` (`id`)
		ON UPDATE CASCADE
		ON DELETE CASCADE
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
SQL
		);
	}

	private function createConfiguratorOptionTranslationTable(Connection $connection): void
	{
		$connection->executeStatement(
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `hmnet_configurator_option_translation` (
	`hmnet_configurator_option_id` BINARY(16) NOT NULL,
	`language_id` BINARY(16) NOT NULL,
	`name` VARCHAR(255) NULL,
	`created_at` DATETIME(3) NOT NULL,
	`updated_at` DATETIME(3) NULL,
	PRIMARY KEY (`hmnet_configurator_option_id`, `language_id`),
	CONSTRAINT `fk.hmnet_configurator_option_translation.option_id`
		FOREIGN KEY (`hmnet_configurator_option_id`)
		REFERENCES `hmnet_configurator_option` (`id`)
		ON UPDATE CASCADE
		ON DELETE CASCADE,
	CONSTRAINT `fk.hmnet_configurator_option_translation.language_id`
		FOREIGN KEY (`language_id`)
		REFERENCES `language` (`id`)
		ON UPDATE CASCADE
		ON DELETE CASCADE
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
SQL
		);
	}

	private function createConfiguratorOptionPossibilityTable(Connection $connection): void
	{
		$connection->executeStatement(
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `hmnet_configurator_option_possibility` (
	`id` BINARY(16) NOT NULL,
	`option_id` BINARY(16) NOT NULL,
	`position` INT NOT NULL,
	`multiplicator` INT NOT NULL,
	`created_at` DATETIME(3) NOT NULL,
	`updated_at` DATETIME(3) NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk.hmnet_configurator_option_possibility.option_id`
		FOREIGN KEY (`option_id`)
		REFERENCES `hmnet_configurator_option` (`id`)
		ON UPDATE CASCADE
		ON DELETE CASCADE
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
SQL
		);
	}

	private function createConfiguratorOptionPossibilityTranslationTable(Connection $connection): void
	{
		$connection->executeStatement(
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `hmnet_configurator_option_possibility_translation` (
	`hmnet_configurator_option_possibility_id` BINARY(16) NOT NULL,
	`language_id` BINARY(16) NOT NULL,
	`name` VARCHAR(255) NULL,
	`created_at` DATETIME(3) NOT NULL,
	`updated_at` DATETIME(3) NULL,
	PRIMARY KEY (`hmnet_configurator_option_possibility_id`, `language_id`),
	CONSTRAINT `fk.hmnet_configurator_opt_posstrans.possibility_id`
		FOREIGN KEY (`hmnet_configurator_option_possibility_id`)
		REFERENCES `hmnet_configurator_option_possibility` (`id`)
		ON UPDATE CASCADE
		ON DELETE CASCADE,
	CONSTRAINT `fk.hmnet_configurator_opt_poss_translation.language_id`
		FOREIGN KEY (`language_id`)
		REFERENCES `language` (`id`)
		ON UPDATE CASCADE
		ON DELETE CASCADE
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
SQL
		);
	}
}
