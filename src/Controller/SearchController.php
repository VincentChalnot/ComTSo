<?php

namespace App\Controller;

use App\Search\SiteSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    /**
     * `?q=` the term, `?tab=` one of {@see SiteSearch::TABS} (unknown = the first tab with hits), `?page=` inside that tab
     * (clamped to the existing pages). Every tab and page is a plain link: nothing here needs JavaScript.
     */
    #[Route('/recherche', name: 'search')]
    public function __invoke(Request $request, SiteSearch $search): Response
    {
        $term = trim($request->query->getString('q'));

        if (!SiteSearch::isSearchable($term)) {
            return $this->render('search/index.html.twig', ['term' => $term, 'searched' => false]);
        }

        $counts = $search->counts($term);
        $tab = SiteSearch::chooseTab($this->scalar($request, 'tab'), $counts);
        // Anything that is not a whole number ("abc", "", 1e99, page[]=1) is the first page, like a number below 1.
        $pager = $search->pagerFor($tab, $counts, (int) filter_var($this->scalar($request, 'page'), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE));

        return $this->render('search/index.html.twig', [
            'term' => $term,
            'searched' => true,
            'tabs' => SiteSearch::TABS,
            'counts' => $counts,
            'total' => array_sum($counts),
            'tab' => $tab,
            'pager' => $pager,
            'items' => $search->page($tab, $term, $pager),
        ]);
    }

    /** A query parameter as a string, or null when it is absent or not a scalar (never an error: the page falls back). */
    private function scalar(Request $request, string $name): ?string
    {
        $value = $request->query->all()[$name] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
