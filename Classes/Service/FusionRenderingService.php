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

use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Exception\InvalidLocaleIdentifierException;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Service as I18nService;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Fusion\Core\FusionGlobals;
use Neos\Fusion\Core\Runtime as FusionRuntime;
use Neos\Fusion\Core\RuntimeFactory;
use Neos\Fusion\Exception as FusionException;
use Neos\Neos\Domain\Exception;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\FusionService;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Psr\Log\LoggerInterface;

class FusionRenderingService
{

    #[Flow\Inject]
    protected I18nService $i18nService;

    protected ?FusionRuntime $fusionRuntime = null;

    #[Flow\Inject]
    protected FusionService $fusionService;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * @var array
     */
    protected $options = ['enableContentCache' => true];

    /**
     * @var LoggerInterface
     */
    #[Flow\Inject]
    protected $logger;

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
        $fusionRuntime = $this->getFusionRuntime($site);

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
            return $output;
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
    protected function getFusionRuntime(Site $site): FusionRuntime
    {
        if ($this->fusionRuntime === null) {
            $fusionConfiguration = $this->fusionService->createFusionConfigurationFromSite(
                $site,
            );
            $this->fusionRuntime = $this->runtimeFactory->createFromConfiguration(
                $fusionConfiguration,
                FusionGlobals::createEmpty(),
            );

            if (isset($this->options['enableContentCache']) && $this->options['enableContentCache'] !== null) {
                $this->fusionRuntime->setEnableContentCache($this->options['enableContentCache']);
            }
        }

        return $this->fusionRuntime;
    }
}
