<?php

declare(strict_types=1);

namespace Flowpack\SearchPlugin\Service;

/*
 * This file is part of the Flowpack.SearchPlugin package.
 *
 * (c) Contributors of the Flowpack Team - flowpack.org
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use GuzzleHttp\Psr7\ServerRequest;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\I18n\Exception\InvalidLocaleIdentifierException;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Service as I18nService;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Fusion\Core\FusionGlobals;
use Neos\Fusion\Core\Runtime as FusionRuntime;
use Neos\Fusion\Core\RuntimeFactory;
use Neos\Fusion\Exception as FusionException;
use Neos\Neos\Domain\Exception;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\RenderingMode;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\FusionService;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Psr\Log\LoggerInterface;

class FusionRenderingService
{

    #[Flow\Inject]
    protected I18nService $i18nService;

    /**
     * @var array<string, FusionRuntime>
     */
    protected array $fusionRuntimesBySite = [];

    #[Flow\Inject]
    protected FusionService $fusionService;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\InjectConfiguration('http.baseUri', 'Neos.Flow')]
    protected string|null $baseUri;

    /**
     * @var array
     */
    protected $options = ['enableContentCache' => true];

    /**
     * @var LoggerInterface
     */
    #[Flow\Inject]
    protected $logger;

    #[Flow\Inject]
    protected Bootstrap $bootstrap;

    /**
     * @var ThrowableStorageInterface
     */
    #[Flow\Inject]
    protected $throwableStorage;

    #[Flow\Inject]
    protected RuntimeFactory $runtimeFactory;

    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    /**
     * @throws FusionException|Exception|AccessDenied
     */
    public function render(Node $node, string $fusionPath, array $contextData = []): string
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $subgraph = $contentRepository->getContentSubgraph($node->workspaceName, $node->dimensionSpacePoint);

        $currentSiteNode = $subgraph->findClosestNode(
            $node->aggregateId,
            FindClosestNodeFilter::create(
                NodeTypeNameFactory::forSite()->value
            )
        );

        if (!$currentSiteNode instanceof Node) {
            $this->logger->error(
                sprintf(
                    'Could not get the current site node for node "%s". Rendering skipped.',
                    $node->aggregateId
                ),
                LogEnvironment::fromMethodName(__METHOD__)
            );
            return '';
        }

        $site = $this->siteRepository->findSiteBySiteNode($currentSiteNode);
        $fusionRuntime = $this->getFusionRuntime($site, $contentRepository);

        $dimensionSpacePoint = $node->dimensionSpacePoint;
        $languageDimensionId = new ContentDimensionId('language');
        if ($dimensionSpacePoint->hasCoordinate($languageDimensionId)) {
            try {
                $languageValue = $dimensionSpacePoint->getCoordinate($languageDimensionId);
                $currentLocale = new Locale($languageValue);
                $this->i18nService->getConfiguration()->setCurrentLocale($currentLocale);
                // FIXME: Adjust this code to work based on specialisations
                //$this->i18nService->getConfiguration()->setFallbackRule([
                //    'strict' => false,
                //    'order' => $languageValue
                //]);
            } catch (InvalidLocaleIdentifierException $exception) {
                $logMessage = $this->throwableStorage->logThrowable($exception);
                $this->logger->error($logMessage, LogEnvironment::fromMethodName(__METHOD__));
            }
        }

        $fusionRuntime->pushContextArray(array_merge([
            'node' => $node,
            'documentNode' => $subgraph->findClosestNode(
                $node->aggregateId,
                FindClosestNodeFilter::create(NodeTypeNameFactory::forDocument()->value)
            ),
            'site' => $currentSiteNode,
            'editPreviewMode' => null,
        ], $contextData));

        try {
            $output = $fusionRuntime->render($fusionPath);
            $fusionRuntime->popContext();
            return $output ?? '';
        } catch (\Exception $exception) {
            $logMessage = $this->throwableStorage->logThrowable($exception);
            $this->logger->error($logMessage, LogEnvironment::fromMethodName(__METHOD__));
        }

        return '';
    }

    /**
     * @throws FusionException
     * @throws Exception
     */
    protected function getFusionRuntime(Site $site, ContentRepository $contentRepository): FusionRuntime
    {
        $siteKey = $site->getNodeName()->value;

        if (!array_key_exists($siteKey, $this->fusionRuntimesBySite)) {
            $fusionConfiguration = $this->fusionService->createFusionConfigurationFromSite(
                $site,
            );
            $fusionRuntime = $this->runtimeFactory->createFromConfiguration(
                $fusionConfiguration,
                FusionGlobals::fromArray([
                    'renderingMode' => RenderingMode::createFrontend(),
                    'request' => $this->getActionRequest($site, $contentRepository->id),
                ]),
            );

            if (isset($this->options['enableContentCache']) && $this->options['enableContentCache'] !== null) {
                $fusionRuntime->setEnableContentCache($this->options['enableContentCache']);
            }
            $this->fusionRuntimesBySite[$siteKey] = $fusionRuntime;
        }
        return $this->fusionRuntimesBySite[$siteKey];
    }

    /**
     * Generate a valid request for the UriBuilder to work during rendering.
     * If the site cannot provide a valid domain, the configured baseUri is used and if that is missing localhost.
     */
    protected function getActionRequest(
        Site $site,
        ContentRepositoryId $contentRepositoryId
    ): ActionRequest {
        // Generate a custom request when the current request was triggered from CLI
        $domain = $site->getPrimaryDomain();
        $fallbackUri = $this->baseUri ?: 'http://localhost';
        $baseUri = $domain instanceof Domain ? (string)$domain : $fallbackUri;

        $httpRequest = new ServerRequest('GET', $baseUri);
        $httpRequest = SiteDetectionResult::create($site->getNodeName(), $contentRepositoryId)
            ->storeInRequest($httpRequest);
        return ActionRequest::fromHttpRequest($httpRequest);
    }
}
