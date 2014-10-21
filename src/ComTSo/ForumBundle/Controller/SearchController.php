<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Lib\Utils;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

class SearchController extends BaseController
{
    /**
     * @Template()
     */
    public function searchAction()
    {
        $original = $this->getRequestParameter('q');
        $q = $this->parseQuery($original);
        $results = $this->getSearchRepositories();
        foreach ($results as &$repo) {
            if (isset($repo['repository'])) {
                $repo['entities'] = $repo['repository']->search($q);
            }
        }
        $this->viewParameters['results'] = $results;
        $this->viewParameters['original'] = $original;
        $this->viewParameters['query'] = $q;
        $this->viewParameters['title'] = 'Recherche';

        return $this->viewParameters;
    }

    protected function getSearchRepositories()
    {
        $repositories = $this->getConfigParameter('comtso.search.repositories', []);
        foreach ($repositories as &$meta) {
            $repo = $this->getRepository($meta['name']);
            if (!method_exists($repo, 'search')) {
                continue;
            }
            $meta['repository'] = $repo;
            if (!isset($meta['template'])) {
                $meta['template'] = $meta['name'] . ':search.html.twig';
            }
        }

        return $repositories;
    }

    protected function parseQuery($query)
    {
        $query = Utils::asciiFormat($query);
        $terms = [];
        foreach (explode('+', $query) as $term) {
            $term = trim(str_replace('*', '%', $term), '%_ ');
            if ($term) {
                $terms[] = $term;
            }
        }

        return $terms;
    }
}
