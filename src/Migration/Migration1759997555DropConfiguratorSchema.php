<?php

declare(strict_types=1);

namespace HMnet\Configurator\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1759997555DropConfiguratorSchema extends MigrationStep
{
	public function getCreationTimestamp(): int
	{
		return 1759997555;
	}

	public function update(Connection $connection): void
	{
		// This migration is for dropping schema, not creating
	}

	public function updateDestructive(Connection $connection): void
	{
		// This migration is for dropping schema, not creating
	}

	public function drop(Connection $connection): void
	{
		$connection->executeStatement('DROP TABLE IF EXISTS hmnet_configurator_option_possibility');
		$connection->executeStatement('DROP TABLE IF EXISTS hmnet_configurator_option');
		$connection->executeStatement('DROP TABLE IF EXISTS hmnet_configurator_field');
		// Drop translation tables if they exist
		$connection->executeStatement('DROP TABLE IF EXISTS hmnet_configurator_option_possibility_translation');
		$connection->executeStatement('DROP TABLE IF EXISTS hmnet_configurator_option_translation');
		$connection->executeStatement('DROP TABLE IF EXISTS hmnet_configurator_field_translation');
	}
}
