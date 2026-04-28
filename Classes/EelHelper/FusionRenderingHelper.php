<?php

declare(strict_types=1);

namespace Flowpack\SearchPlugin\EelHelper;

/*
 * This file is part of the Flowpack.SearchPlugin package.
 *
 * (c) Contributors of the Flowpack Team - flowpack.org
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\SearchPlugin\Service\FusionRenderingService;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;

class FusionRenderingHelper implements ProtectedContextAwareInterface
{
    #[Flow\Inject]
    protected FusionRenderingService $fusionRenderingService;

    public function render(Node $node, string $fusionPath): string
    {
        try {
            return $this->fusionRenderingService->render($node, $fusionPath);
        } catch (\Exception $e) {
            // TODO: Should we log this error?
            return '';
        }
    }

    /**
     * @param string $methodName
     */
    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }

}
