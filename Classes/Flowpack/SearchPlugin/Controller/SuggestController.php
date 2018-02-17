<?php
namespace Flowpack\SearchPlugin\Controller;

/*
 * This file is part of the Flowpack.SearchPlugin package.
 *
 * (c) Contributors of the Flowpack Team - flowpack.org
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Eel\ElasticSearchQueryBuilder;
use Flowpack\ElasticSearch\ContentRepositoryAdaptor\ElasticSearchClient;
use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Exception\QueryBuildingException;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cache\Frontend\VariableFrontend;
use TYPO3\Flow\Mvc\Controller\ActionController;
use TYPO3\Neos\Controller\CreateContentContextTrait;

/**
 * Class SuggestController
 */
class SuggestController extends ActionController
{
    use CreateContentContextTrait;

    /**
     * @var ElasticSearchClient
     */
    protected $elasticSearchClient;

    /**
     * @Flow\Inject
     * @var ElasticSearchQueryBuilder
     */
    protected $elasticSearchQueryBuilder;

    /**
     * @Flow\Inject
     * @var VariableFrontend
     */
    protected $elasticSearchQueryTemplateCache;

    /**
     * @var array
     */
    protected $viewFormatToObjectNameMap = [
        'json' => 'TYPO3\Flow\Mvc\View\JsonView'
    ];

    public function initializeObject()
    {
        if ($this->objectManager->isRegistered(ElasticSearchClient::class)) {
            $this->elasticSearchClient = $this->objectManager->get(ElasticSearchClient::class);
            $this->elasticSearchQueryBuilder = $this->objectManager->get(ElasticSearchQueryBuilder::class);
        }
    }

    /**
     * @param string $contextNodeIdentifier
     * @param string $dimensionCombination
     * @param string $term
     * @throws QueryBuildingException
     */
    public function indexAction($contextNodeIdentifier, $dimensionCombination, $term)
    {
        if ($this->elasticSearchClient === null) {
            throw new \RuntimeException('The SuggestController needs an ElasticSearchClient, it seems you run without the flowpack/elasticsearch-contentrepositoryadaptor package, though.', 1487189823);
        }

        $result = [
            'completions' => [],
            'suggestions' => []
        ];

        if (!is_string($term)) {
            $result['errors'] = ['term has to be a string'];
            $this->view->assign('value', $result);
            return;
        }

        $requestJson = $this->buildRequestForTerm($contextNodeIdentifier, $dimensionCombination, $term);

        try {
            $response = $this->elasticSearchClient->getIndex()->request('POST', '/_search', [], $requestJson)->getTreatedContent();
            $result['completions'] = $this->extractCompletions($response);
            $result['suggestions'] = $this->extractSuggestions($response);
        } catch (\Exception $e) {
            $result['errors'] = ['Could not execute query'];
        }

        $options = $result['completions'];
        if (empty($options)) {
            $options = $result['suggestions'];
        }

        $options = array_map(function ($option) {
            return (is_array($option) && array_key_exists('text', $option)) ? $option['text'] : $option;
        }, $options);

        $this->view->assign('value', array_filter($options));
    }

    /**
     * @param string $term
     * @param string $contextNodeIdentifier
     * @param string $dimensionCombination
     * @return ElasticSearchQueryBuilder
     * @throws QueryBuildingException
     */
    protected function buildRequestForTerm($contextNodeIdentifier, $dimensionCombination, $term)
    {
        $cacheKey = $contextNodeIdentifier . '-' . md5($dimensionCombination);
        $termPlaceholder = '---term-soh2gufuNi---';
        $term = strtolower($term);
        // The suggest function only works well with one word
        // and the term is trimmed to alnum characters to avoid errors
        $suggestTerm = preg_replace('/[[:^alnum:]]/', '', explode(' ', $term)[0]);
        if (!$this->elasticSearchQueryTemplateCache->has($cacheKey)) {
            $contentContext = $this->createContentContext('live', json_decode($dimensionCombination, true));
            $contextNode = $contentContext->getNodeByIdentifier($contextNodeIdentifier);
            /** @var ElasticSearchQueryBuilder $query */
            $query = $this->elasticSearchQueryBuilder->query($contextNode);
            $query
                ->queryFilter('prefix', [
                    '__completion' => $termPlaceholder
                ])
                ->limit(1)
                ->aggregation('autocomplete', [
                    'terms' => [
                        'field' => '__completion',
                        'order' => [
                            '_count' => 'desc'
                        ],
                        'include' => [
                            'pattern' => $termPlaceholder . '.*'
                        ]
                    ]
                ])
                ->suggestions('suggestions', [
                    'text' => $termPlaceholder,
                    'completion' => [
                        'field' => '__suggestions',
                        'fuzzy' => true,
                        'size' => 10,
                        'context' => [
                            'parentPath' => $contextNode->getPath(),
                            'workspace' => 'live',
                            'dimensionCombinationHash' => md5(json_encode($contextNode->getContext()->getDimensions())),
                        ]
                    ]
                ]);
            $requestTemplate = json_encode($query->getRequest());
            $this->elasticSearchQueryTemplateCache->set($contextNodeIdentifier, $requestTemplate);
        } else {
            $requestTemplate = $this->elasticSearchQueryTemplateCache->get($cacheKey);
        }
        return str_replace($termPlaceholder, $suggestTerm, $requestTemplate);
    }

    /**
     * Extract autocomplete options
     *
     * @param $response
     * @return array
     */
    protected function extractCompletions($response)
    {
        $aggregations = isset($response['aggregations']) ? $response['aggregations'] : [];
        return array_map(function ($option) {
            return $option['key'];
        }, $aggregations['autocomplete']['buckets']);
    }

    /**
     * Extract suggestion options
     *
     * @param $response
     * @return array
     */
    protected function extractSuggestions($response)
    {
        $suggestionOptions = isset($response['suggest']) ? $response['suggest'] : [];
        if (count($suggestionOptions['suggestions'][0]['options']) > 0) {
            return $suggestionOptions['suggestions'][0]['options'];
        }
        return [];
    }
}
