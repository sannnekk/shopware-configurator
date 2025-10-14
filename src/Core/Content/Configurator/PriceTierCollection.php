<?php

declare(strict_types=1);

namespace HMnet\Configurator\Core\Content\Configurator;

class PriceTierCollection
{
	/**
	 * @var array<int, array{toQuantity: ?int, price: float}>
	 */
	private array $tiers = [];

	/**
	 * @param array<int, array{toQuantity: ?int, price: float}> $tiers
	 */
	public function __construct(array $tiers = [])
	{
		$this->tiers = $tiers;
	}

	/**
	 * @return array<int, array{toQuantity: ?int, price: float}>
	 */
	public function getTiers(): array
	{
		return $this->tiers;
	}
}
