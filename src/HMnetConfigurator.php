<?php

declare(strict_types=1);

namespace HMnet\Configurator;

use Doctrine\DBAL\Connection;
use HMnet\Configurator\Migration\Migration1759997555DropConfiguratorSchema;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

class HMnetConfigurator extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $this->dropSchema($uninstallContext);
    }

    public function activate(ActivateContext $activateContext): void
    {
        // Activate entities, such as a new payment method
        // Or create new entities here, because now your plugin is installed and active for sure
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        // Deactivate entities, such as a new payment method
        // Or remove previously created entities
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
    }

    public function postInstall(InstallContext $installContext): void {}

    public function postUpdate(UpdateContext $updateContext): void {}

    private function dropSchema(UninstallContext $uninstallContext): void
    {
        if ($uninstallContext->keepUserData() || $this->container === null) {
            return;
        }

        if (!$this->container->has(Connection::class)) {
            return;
        }

        $connection = $this->container->get(Connection::class);

        $migration = new Migration1759997555DropConfiguratorSchema();
        $migration->drop($connection);
    }
}
