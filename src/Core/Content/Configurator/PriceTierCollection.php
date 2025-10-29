<?php

declare(strict_types=1);

namespace HMnet\Configurator\Core\Content\Configurator;

class PriceTierCollection implements \JsonSerializable
{
	/**
	 * @var array<int, array{quantityStart: ?int, quantityEnd: ?int, price: float}>
	 */
	private array $tiers = [];

	/**
	 * @param array<int, array{quantityStart: ?int, quantityEnd: ?int, price: float}> $tiers
	 */
	public function __construct(array $tiers = [])
	{
		$this->tiers = $tiers;
	}

	/**
	 * @return array<int, array{quantityStart: ?int, quantityEnd: ?int, price: float}>
	 */
	public function getTiers(): array
	{
		return $this->tiers;
	}

	/**
	 * @return array<int, array{quantityStart: ?int, quantityEnd: ?int, price: float}>
	 */
	public function jsonSerialize(): array
	{
		return $this->tiers;
	}

	/**
	 * Get price for given quantity based on tiers
	 */
	public function getPrice(int $quantity): float
	{
		foreach ($this->tiers as $tier) {
			$start = $tier['quantityStart'] ?? null;
			$end = $tier['quantityEnd'] ?? null;

			$startOk = $start === null || $quantity >= $start;
			$endOk = $end === null || $quantity <= $end;

			if ($startOk && $endOk) {
				return $tier['price'];
			}
		}

		return 0.0;
	}
}
