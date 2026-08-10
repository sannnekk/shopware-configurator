<?php

declare(strict_types=1);

namespace HMnet\Configurator\Storefront\Controller;

use HMnet\Configurator\Service\ConfiguratorPriceService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class ConfiguratorCalculateController extends StorefrontController
{
	public function __construct(
		private readonly ConfiguratorPriceService $priceService
	) {}

	#[Route(
		path: '/hmnet/configurator/calculate',
		name: 'frontend.hmnet.configurator.calculate',
		methods: ['POST'],
		defaults: ['XmlHttpRequest' => true, '_csrf_protected' => true]
	)]
	public function calculate(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
	{
		try {
			$payload = $this->parseRequestPayload($request);
		} catch (\Throwable $exception) {
			return new JsonResponse([
				'success' => false,
				'error' => 'Invalid request: ' . $exception->getMessage(),
			], Response::HTTP_BAD_REQUEST);
		}

		$productId = (string) ($payload['productId'] ?? '');
		$quantity = max(1, (int) ($payload['quantity'] ?? 1));
		$selection = (array) ($payload['selection'] ?? []);

		if ($productId === '') {
			return new JsonResponse([
				'success' => false,
				'error' => 'Product ID is required',
			], Response::HTTP_BAD_REQUEST);
		}

		$result = $this->priceService->calculate(
			$productId,
			$quantity,
			$selection,
			$salesChannelContext
		);

		return new JsonResponse($result);
	}

	private function parseRequestPayload(Request $request): array
	{
		$content = $request->getContent();

		if ($content === '' || $content === null) {
			throw new \InvalidArgumentException('Empty request content');
		}

		$decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

		if (!\is_array($decoded)) {
			throw new \InvalidArgumentException('Payload must be an object');
		}

		return $decoded;
	}
}
