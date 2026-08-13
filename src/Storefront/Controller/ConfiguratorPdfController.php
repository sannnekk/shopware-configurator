<?php

declare(strict_types=1);

namespace HMnet\Configurator\Storefront\Controller;

use Doctrine\DBAL\Connection;
use HMnet\Configurator\Service\ConfiguratorPriceService;
use Shopware\Core\Checkout\Document\Renderer\RenderedDocument;
use Shopware\Core\Checkout\Document\Service\PdfRenderer;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Theme\ThemeConfigValueAccessor;
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
		private readonly PdfRenderer $pdfRenderer,
		private readonly ConfiguratorPriceService $priceService,
		private readonly Connection $connection,
		private readonly ThemeConfigValueAccessor $themeConfigValueAccessor,
		private readonly string $projectDir = ''
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

		// Use the shared price service for calculations
		$priceResult = $this->priceService->calculate($productId, $quantity, $selection, $salesChannelContext);

		if (!$priceResult['success']) {
			return new JsonResponse(['message' => 'Preisberechnung fehlgeschlagen.'], Response::HTTP_INTERNAL_SERVER_ERROR);
		}

		$priceData = $this->buildPriceDataFromResult($priceResult);
		$shop = $this->buildShopData($salesChannelContext);

		$productImageUrl = null;
		$coverUrl = $product->getCover()?->getMedia()?->getUrl();
		if ($coverUrl !== null) {
			$productImageUrl = $this->mediaToDataUri($coverUrl);
		}

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
			'productImageUrl' => $productImageUrl,
			'quantity' => $priceResult['adjustedQuantity'] ?? $quantity,
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
			->addAssociation('tax')
			->addAssociation('cover.media');

		return $this->productRepository->search($criteria, $context->getContext())->first();
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

	/**
	 * Convert the price service result to the format expected by the PDF template
	 */
	private function buildPriceDataFromResult(array $result): array
	{
		$optionLines = [];
		foreach ($result['options'] as $option) {
			$optionLines[] = [
				'label' => $option['label'],
				'unitNet' => $option['unitNet'],
				'quantity' => $option['quantity'],
				'totalNet' => $option['totalNet'],
			];
		}

		$setupSurcharges = [];
		$filmSurcharges = [];
		foreach ($result['surcharges'] as $surcharge) {
			$line = [
				'label' => $surcharge['label'],
				'unitAmount' => $surcharge['unitAmount'] ?? $surcharge['amount'],
				'quantity' => $surcharge['quantity'] ?? 1,
				'amount' => $surcharge['amount'],
			];

			if ($surcharge['type'] === 'setup') {
				$setupSurcharges[] = $line;
			} elseif ($surcharge['type'] === 'film') {
				$filmSurcharges[] = $line;
			}
		}

		return [
			'productUnit' => $result['product']['unitNet'],
			'productTotal' => $result['product']['totalNet'],
			'optionLines' => $optionLines,
			'setupSurcharges' => $setupSurcharges,
			'filmSurcharges' => $filmSurcharges,
			'optionTotal' => array_sum(array_column($optionLines, 'totalNet')),
			'setupTotal' => array_sum(array_column($setupSurcharges, 'amount')),
			'filmTotal' => array_sum(array_column($filmSurcharges, 'amount')),
			'netTotal' => $result['totals']['netTotal'],
			'taxAmount' => $result['totals']['taxAmount'],
			'grossTotal' => $result['totals']['grossTotal'],
			'taxRate' => $result['totals']['taxRate'],
		];
	}

	private function mediaToDataUri(string $url): string
	{
		// Strategy 1: read from local filesystem (reliable, no network needed)
		if ($this->projectDir !== '') {
			$path = parse_url($url, PHP_URL_PATH);
			if (\is_string($path) && $path !== '') {
				$localPath = rtrim($this->projectDir, '/') . '/public' . $path;
				if (is_file($localPath) && is_readable($localPath)) {
					$mime = mime_content_type($localPath) ?: 'image/octet-stream';
					$data = file_get_contents($localPath);
					if ($data !== false) {
						return 'data:' . $mime . ';base64,' . base64_encode($data);
					}
				}
			}
		}

		// Strategy 2: HTTP fetch (handles CDN URLs or mismatched APP_URL)
		$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
		$data = @file_get_contents($url, false, $ctx);
		if ($data !== false && \strlen($data) > 64) {
			$finfo = new \finfo(\FILEINFO_MIME_TYPE);
			$mime = $finfo->buffer($data) ?: 'image/octet-stream';
			return 'data:' . $mime . ';base64,' . base64_encode($data);
		}

		return $url;
	}

	private function findFallbackLogoDataUri(): ?string
	{
		if ($this->projectDir === '') {
			return null;
		}

		$mediaDir = rtrim($this->projectDir, '/') . '/public/media';
		$best = null;
		$bestScore = -1;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($mediaDir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ($iterator as $file) {
			/** @var \SplFileInfo $file */
			if (!$file->isFile()) {
				continue;
			}

			$name = strtolower($file->getFilename());

			// Must be an image with "logo" in the name
			if (!str_contains($name, 'logo')) {
				continue;
			}
			if (!preg_match('/\.(svg|png|jpg|jpeg|gif|webp)$/i', $name)) {
				continue;
			}

			// Skip obvious product logo files (contain dimension strings or product keywords)
			if (preg_match('/\d{3,4}x\d{3,4}|kugelschreiber|stift|usb|pen_|_pen|_ds|_gum|_mr_|_tf_/i', $name)) {
				continue;
			}

			$size = $file->getSize();
			// Skip very large files — shop logos are compact
			if ($size > 150000) {
				continue;
			}

			// Score: prefer SVG, prefer smaller files
			$score = str_ends_with($name, '.svg') ? 20 : 5;
			$score -= (int) ($size / 5000);

			if ($score > $bestScore) {
				$bestScore = $score;
				$best = $file->getPathname();
			}
		}

		if ($best !== null) {
			$mime = mime_content_type($best) ?: 'image/png';
			$data = file_get_contents($best);
			if ($data !== false) {
				return 'data:' . $mime . ';base64,' . base64_encode($data);
			}
		}

		return null;
	}

	/**
	 * core.basicInformation.address is a single free-text HTML field (e.g.
	 * "<div>Schillerstraße 5/1<br>76356 Weingarten</div>"), not separate
	 * street/zip/city fields. Strip the markup and split it into lines.
	 *
	 * @return list<string>
	 */
	private function parseAddressLines(string $html): array
	{
		if ($html === '') {
			return [];
		}

		$normalized = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
		$text = trim(strip_tags($normalized));

		if ($text === '') {
			return [];
		}

		$lines = array_map('trim', explode("\n", $text));

		return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
	}

	private function resolveThemeId(string $salesChannelId): ?string
	{
		$themeId = $this->connection->fetchOne(
			'SELECT LOWER(HEX(theme_id)) FROM theme_sales_channel WHERE sales_channel_id = :salesChannelId',
			['salesChannelId' => Uuid::fromHexToBytes($salesChannelId)]
		);

		return \is_string($themeId) && $themeId !== '' ? $themeId : null;
	}

	private function buildShopData(SalesChannelContext $context): array
	{
		$salesChannelId = $context->getSalesChannelId();

		$addressLines = $this->parseAddressLines(
			(string) $this->systemConfigService->get('core.basicInformation.address', $salesChannelId)
		);

		$address = [
			'name' => (string) ($this->systemConfigService->get('core.basicInformation.shopName', $salesChannelId) ?: 'pen4you'),
			'street' => $addressLines[0] ?? '',
			// The second line already contains "<zip> <city>" combined.
			'cityLine' => $addressLines[1] ?? '',
		];

		$logoUrl = null;

		// Strategy 1: the logo actually configured for the active storefront theme
		// (Storefront > Theme > "Logo Desktop"). This is what's shown on the live shop.
		$themeId = $this->resolveThemeId($salesChannelId);
		if ($themeId !== null) {
			$themeLogo = $this->themeConfigValueAccessor->get('sw-logo-desktop', $context, $themeId);
			if (\is_string($themeLogo) && $themeLogo !== '') {
				$logoUrl = $this->mediaToDataUri($themeLogo);
			}
		}

		// Strategy 2: dedicated e-mail/document logo from the basic shop settings, if set.
		if ($logoUrl === null) {
			$logoId = $this->systemConfigService->get('core.basicInformation.emailLogo', $salesChannelId);
			if (\is_string($logoId) && $logoId !== '') {
				$logo = $this->mediaRepository->search(new Criteria([$logoId]), $context->getContext())->first();
				if ($logo !== null) {
					$logoUrl = $this->mediaToDataUri($logo->getUrl());
				}
			}
		}

		// Strategy 3: scan media directory for any file with "logo" in the name.
		if ($logoUrl === null) {
			$logoUrl = $this->findFallbackLogoDataUri();
		}

		return [
			'address' => $address,
			'logoUrl' => $logoUrl,
			'url' => $context->getSalesChannel()->getDomains()->first()?->getUrl(),
		];
	}
}
