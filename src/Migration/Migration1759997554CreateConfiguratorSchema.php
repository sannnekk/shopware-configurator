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
		// Tables are created automatically by Doctrine for entities
		// Add any custom schema changes here if needed
	}

	public function updateDestructive(Connection $connection): void
	{
		// No destructive changes for creation
	}
}
