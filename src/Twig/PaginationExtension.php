<?php

namespace App\Twig;

use App\Pagination\Pagination;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Class SimplePaginationExtension.
 *
 * @author Ashley Dawson <ashley@ashleydawson.co.uk>
 */
class PaginationExtension extends AbstractExtension
{
    public function getFunctions()
    {
        return [
            new TwigFunction(
                'pagination_render',
                $this->render(...),
                [
                    'is_safe' => ['html'],
                    'needs_environment' => true,
                ]
            ),
        ];
    }

    /**
     * Render the pagination.
     */
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
