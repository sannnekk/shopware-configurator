<?php

declare(strict_types=1);

namespace HMnet\Configurator\Utils;

use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class PriceUtils
{
	public static function mergeTaxes(CalculatedTaxCollection $target, CalculatedTaxCollection $source): void
	{
		foreach ($source as $tax) {
			$taxClone = new CalculatedTax($tax->getTax(), $tax->getTaxRate(), $tax->getPrice(), $tax->getLabel());
			$existing = $target->get((string) $tax->getTaxRate());

			if ($existing instanceof CalculatedTax) {
				$existing->increment($taxClone);

				continue;
			}

			$target->add($taxClone);
		}
	}

	public static function cloneTaxes(CalculatedTaxCollection $taxes): CalculatedTaxCollection
	{
		$cloned = new CalculatedTaxCollection([]);

		foreach ($taxes as $tax) {
			$cloned->add(new CalculatedTax($tax->getTax(), $tax->getTaxRate(), $tax->getPrice(), $tax->getLabel()));
		}

		return $cloned;
	}
}
