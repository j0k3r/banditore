<?php

namespace App\Twig;

use App\Pagination\Pagination;
use Twig\Attribute\AsTwigFunction;
use Twig\Environment;

/**
 * Class SimplePaginationExtension.
 *
 * @author Ashley Dawson <ashley@ashleydawson.co.uk>
 */
class PaginationExtension
{
    /**
     * Render the pagination.
     */
    #[AsTwigFunction(name: 'pagination_render', isSafe: ['html'], needsEnvironment: true)]
    public function render(Environment $environment, Pagination $pagination, string $routeName, string $pageParameterName = 'page', array $queryParameters = []): string
    {
        return $environment->render('default/_pagination.html.twig', [
            'pagination' => $pagination,
            'routeName' => $routeName,
            'pageParameterName' => $pageParameterName,
            'queryParameters' => $queryParameters,
        ]);
    }

    public function getName(): string
    {
        return 'pagination_extension';
    }
}
