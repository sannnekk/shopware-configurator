<?php

declare(strict_types=1);

namespace HMnet\Configurator\Storefront\Controller;

use HMnet\Configurator\Utils\FieldUtils;
use Shopware\Core\Checkout\Document\Renderer\RenderedDocument;
use Shopware\Core\Checkout\Document\Service\PdfRenderer;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class ConfiguratorPdfController extends StorefrontController
{
	public function __construct(
		private readonly EntityRepository $productRepository,
		private readonly EntityRepository $configuratorFieldRepository,
		private readonly EntityRepository $mediaRepository,
		private readonly EntityRepository $languageRepository,
		private readonly SystemConfigService $systemConfigService,
		private readonly PdfRenderer $pdfRenderer
	) {}

	#[Route(path: '/hmnet/configurator/pdf', name: 'frontend.hmnet.configurator.pdf', methods: ['POST'], defaults: ['_csrf_protected' => true])]
	public function generate(Request $request, SalesChannelContext $salesChannelContext): Response
	{
		try {
			$payload = $this->parseRequestPayload($request);
		} catch (\Throwable $exception) {
			return new JsonResponse(['message' => 'Ungültige Anfrage: ' . $exception->getMessage()], Response::HTTP_BAD_REQUEST);
		}

		$productId = (string) ($payload['productId'] ?? '');
		$quantity = max(1, (int) ($payload['quantity'] ?? 1));
		$selection = (array) ($payload['payload'] ?? []);

		$product = $this->fetchProduct($productId, $salesChannelContext);
		if (!$product instanceof ProductEntity) {
			return new JsonResponse(['message' => 'Produkt nicht gefunden.'], Response::HTTP_NOT_FOUND);
		}

		$fields = $this->fetchConfiguratorFields($productId, $salesChannelContext);

		$priceData = $this->buildPriceBreakdown($product, $fields, $selection, $quantity, $salesChannelContext);
		$shop = $this->buildShopData($salesChannelContext);

		$document = new RenderedDocument(number: 'ANG-' . date('Ymd-His'));
		$document->setTemplate('@HMnetConfigurator/documents/configurator-quote.html.twig');
		$document->setContext($salesChannelContext->getContext());
		$languageId = $salesChannelContext->getLanguageId();
		$criteria = (new Criteria([$languageId]))->addAssociation('locale');
		$language = $this->languageRepository->search($criteria, $salesChannelContext->getContext())->first();

		$document->setOrder($this->buildOrderStub(
			$salesChannelContext->getSalesChannelId(),
			$languageId,
			$language
		));
		$document->setName('angebot.pdf');
		$document->setParameters([
			'shop' => $shop,
			'product' => $product,
			'quantity' => $quantity,
			'priceData' => $priceData,
			'currencySymbol' => $salesChannelContext->getCurrency()->getSymbol(),
			'generatedAt' => new \DateTimeImmutable(),
			'context' => $salesChannelContext,
		]);

		$content = $this->pdfRenderer->render($document);

		$response = new Response($content, Response::HTTP_OK, [
			'Content-Type' => 'application/pdf',
			'Content-Disposition' => 'attachment; filename="angebot.pdf"',
		]);

		return $response;
	}

	private function parseRequestPayload(Request $request): array
	{
		$content = $request->getContent();

		if ($content === '' || $content === null) {
			throw new \InvalidArgumentException('Leerer Request-Inhalt');
		}

		$decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

		if (!\is_array($decoded)) {
			throw new \InvalidArgumentException('Payload muss ein Objekt sein');
		}

		return $decoded;
	}

	private function fetchProduct(string $productId, SalesChannelContext $context): ?ProductEntity
	{
		if ($productId === '' || !Uuid::isValid($productId)) {
			return null;
		}

		$criteria = (new Criteria([$productId]))
			->addAssociation('prices')
			->addAssociation('price')
			->addAssociation('tax');

		return $this->productRepository->search($criteria, $context->getContext())->first();
	}

	private function fetchConfiguratorFields(string $productId, SalesChannelContext $context): array
	{
		$criteria = (new Criteria())
			->addFilter(new EqualsFilter('productId', $productId))
			->addAssociation('options.possibilities')
			->addSorting(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting('position'))
			->addSorting(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting('options.position'))
			->addSorting(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting('options.possibilities.position'));

		return $this->configuratorFieldRepository->search($criteria, $context->getContext())->getElements();
	}

	private function buildOrderStub(string $salesChannelId, ?string $languageId, ?LanguageEntity $language): OrderEntity
	{
		$order = new OrderEntity();
		$order->setId(Uuid::randomHex());
		$order->setSalesChannelId($salesChannelId);
		$order->setLanguageId($languageId ?? Defaults::LANGUAGE_SYSTEM);
		$order->setLanguage($language);
		$order->setVersionId(Uuid::randomHex());

		return $order;
	}

	private function buildPriceBreakdown(
		ProductEntity $product,
		array $fields,
		array $selection,
		int $quantity,
		SalesChannelContext $context
	): array {
		$currencyId = $context->getCurrencyId();
		$taxRate = (float) ($product->getTax()?->getTaxRate() ?? 0.0);

		$productUnit = $this->getProductUnitPrice($product, $currencyId, $quantity);
		$productTotal = $productUnit * $quantity;

		$optionLines = [];
		$setupSurcharges = [];
		$filmSurcharges = [];

		foreach ($fields as $field) {
			$fieldId = $field->id;
			$possibilityId = $selection[$fieldId] ?? null;

			if (!$possibilityId) {
				continue;
			}

			[$option, $possibility] = FieldUtils::getOptionAndPossibility($field, $possibilityId);

			if (!$option || !$possibility) {
				continue;
			}

			$multiplicator = (float) ($possibility->multiplicator ?? 1.0);
			$optionUnit = FieldUtils::getPriceFromTiers($option->priceTiers?->getTiers() ?? [], $quantity) * $multiplicator;
			$optionTotal = $optionUnit * $quantity;

			$optionLines[] = [
				'label' => sprintf('%s: %s %s', $field->name, $option->name, $possibility->name),
				'unitNet' => $optionUnit,
				'quantity' => $quantity,
				'totalNet' => $optionTotal,
			];

			$setupPrice = ((float) ($option->setupPrice ?? 0.0)) * $multiplicator;
			$filmPrice = ((float) ($option->filmPrice ?? 0.0)) * $multiplicator;

			if ($setupPrice > 0) {
				$setupSurcharges[] = [
					'label' => sprintf('Einrichtung: %s %s', $option->name, $possibility->name),
					'amount' => $setupPrice,
				];
			}

			if ($filmPrice > 0) {
				$filmSurcharges[] = [
					'label' => sprintf('Film: %s %s', $option->name, $possibility->name),
					'amount' => $filmPrice,
				];
			}
		}

		$optionTotalNet = array_sum(array_column($optionLines, 'totalNet'));
		$setupTotalNet = array_sum(array_column($setupSurcharges, 'amount'));
		$filmTotalNet = array_sum(array_column($filmSurcharges, 'amount'));

		$netTotal = $productTotal + $optionTotalNet + $setupTotalNet + $filmTotalNet;
		$taxAmount = $netTotal * ($taxRate / 100);
		$grossTotal = $netTotal + $taxAmount;

		return [
			'productUnit' => $productUnit,
			'productTotal' => $productTotal,
			'optionLines' => $optionLines,
			'setupSurcharges' => $setupSurcharges,
			'filmSurcharges' => $filmSurcharges,
			'optionTotal' => $optionTotalNet,
			'setupTotal' => $setupTotalNet,
			'filmTotal' => $filmTotalNet,
			'netTotal' => $netTotal,
			'taxAmount' => $taxAmount,
			'grossTotal' => $grossTotal,
			'taxRate' => $taxRate,
		];
	}

	private function getProductUnitPrice(ProductEntity $product, string $currencyId, int $quantity): float
	{
		$prices = $product->getPrices();

		if ($prices) {
			foreach ($prices as $priceRule) {
				$start = $priceRule->getQuantityStart();
				$end = $priceRule->getQuantityEnd();
				$matchesQuantity = ($start === null || $quantity >= $start) && ($end === null || $quantity <= $end);

				if (!$matchesQuantity) {
					continue;
				}

				$price = $priceRule->getPrice()->getCurrencyPrice($currencyId, false) ?? $priceRule->getPrice()->first();

				return (float) ($price?->getNet() ?? 0.0);
			}
		}

		$basePrice = $product->getPrice();
		$price = $basePrice?->getCurrencyPrice($currencyId, true) ?? $basePrice?->first();

		return (float) ($price?->getNet() ?? 0.0);
	}

	private function buildShopData(SalesChannelContext $context): array
	{
		$salesChannelId = $context->getSalesChannelId();
		$address = [
			'name' => (string) $this->systemConfigService->get('core.basicInformation.shopName', $salesChannelId),
			'street' => (string) $this->systemConfigService->get('core.basicInformation.addressStreet', $salesChannelId),
			'zip' => (string) $this->systemConfigService->get('core.basicInformation.addressZipcode', $salesChannelId),
			'city' => (string) $this->systemConfigService->get('core.basicInformation.addressCity', $salesChannelId),
			'phone' => (string) $this->systemConfigService->get('core.basicInformation.phoneNumber', $salesChannelId),
			'email' => (string) $this->systemConfigService->get('core.basicInformation.email', $salesChannelId),
		];

		$logoId = $this->systemConfigService->get('core.basicInformation.emailLogo', $salesChannelId);
		$logoUrl = null;

		if (\is_string($logoId) && $logoId !== '') {
			$logo = $this->mediaRepository->search(new Criteria([$logoId]), $context->getContext())->first();
			$logoUrl = $logo?->getUrl();
		}

		return [
			'address' => $address,
			'logoUrl' => $logoUrl,
			'url' => $context->getSalesChannel()->getDomains()->first()?->getUrl(),
		];
	}
}
