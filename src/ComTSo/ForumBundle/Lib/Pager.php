<?php

namespace ComTSo\ForumBundle\Lib;

use Doctrine\ORM\QueryBuilder;

class Pager extends \ArrayObject {

	protected $page = 1;
	protected $sort;
	protected $direction = 'ASC';
	protected $limit = 10;
	protected $isInitialized = false;
	protected $totalCount;
	protected $pageQuery = 'page';
	protected $sortQuery = 'sort';
	protected $directionQuery = 'direction';
	protected $limitQuery = 'limit';
	protected $pageRange = 5;
	protected $route;

	/**
	 * @var QueryBuilder
	 */
	protected $queryBuilder;

	public function getPage() {
		return $this->page;
	}

	public function getSort() {
		return $this->sort;
	}

	public function getDirection() {
		return $this->direction;
	}

	public function getLimit() {
		return $this->limit;
	}

	/**
	 * @return QueryBuilder
	 */
	public function getQueryBuilder() {
		return $this->queryBuilder;
	}

	public function getIsInitialized() {
		return $this->isInitialized;
	}

	public function getTotalCount() {
		$this->initialize();
		return $this->totalCount;
	}

	public function getResults() {
		$this->initialize();
		return $this->results;
	}

	public function getPageCount() {
		$this->initialize();
		return ceil($this->getTotalCount() / $this->getLimit());
	}

	public function getPageRange() {
		return $this->pageRange;
	}

	public function pagesInRange() {
		$this->initialize();
		$delta = floor($this->getPageRange() / 2);
		$current = min(max($this->getPage() - $delta, 1), $this->getPageCount() - $this->getPageRange());
		$count = 0;
		$pages = [];
		while ($count < $this->getPageRange()) {
			$pages[] = $current;
			$current++;
			$count++;
		}
		return $pages;
	}

	public function getPageQuery() {
		return $this->pageQuery;
	}

	public function getSortQuery() {
		return $this->sortQuery;
	}

	public function getDirectionQuery() {
		return $this->directionQuery;
	}

	public function getLimitQuery() {
		return $this->limitQuery;
	}

	public function setPage($page) {
		$page = (int) $page;
		if ($page > 0) {
			$this->isInitialized = false;
			$this->page = $page;
		}
		return $this;
	}

	public function setSort($sort) {
		$this->isInitialized = false;
		$this->sort = $sort;
		return $this;
	}

	public function setDirection($direction) {
		if (in_array($direction, ['A', 'ASC', 'a', 'asc'])) {
			$this->isInitialized = false;
			$this->direction = 'ASC';
		}
		if (in_array($direction, ['D', 'DESC', 'd', 'desc'])) {
			$this->isInitialized = false;
			$this->direction = 'DESC';
		}
		return $this;
	}

	public function setLimit($limit) {
		$this->isInitialized = false;
		$this->limit = $limit;
		return $this;
	}

	public function setQueryBuilder(QueryBuilder $queryBuilder) {
		$this->isInitialized = false;
		$this->queryBuilder = $queryBuilder;
		return $this;
	}

	public function setPageQuery($pageQuery) {
		$this->pageQuery = $pageQuery;
		return $this;
	}

	public function setSortQuery($sortQuery) {
		$this->sortQuery = $sortQuery;
		return $this;
	}

	public function setDirectionQuery($directionQuery) {
		$this->directionQuery = $directionQuery;
		return $this;
	}

	public function setLimitQuery($limitQuery) {
		$this->limitQuery = $limitQuery;
		return $this;
	}

	public function setPageRange($pageRange) {
		$this->pageRange = (int) $pageRange;
		return $this;
	}

	public function getRoute() {
		return $this->route;
	}

	public function setRoute($route) {
		$this->route = $route;
		return $this;
	}

	public function initialize() {
		if ($this->isInitialized) {
			return $this;
		}
		$this->initializeCount();

		$this->queryBuilder
				->setMaxResults($this->getLimit())
				->setFirstResult(($this->getPage() - 1) * $this->getLimit());

		$this->initializeSort();

		$this->exchangeArray($this->queryBuilder->getQuery()->getResult());
		$this->isInitialized = true;
		return $this;
	}

	protected function initializeCount() {
		$countQb = clone $this->queryBuilder;
		$alias = $this->queryBuilder->getRootAliases()[0];
		$countQb->select("COUNT({$alias})");
		$this->totalCount = (int) $countQb->getQuery()
						->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_SINGLE_SCALAR);
	}

	protected function initializeSort() {
		if (!$this->getSort()) {
			return;
		}
		if (false === strpos($this->getSort(), '.')) {
			$alias = $this->queryBuilder->getRootAliases()[0];
			$entity = $this->queryBuilder->getRootEntities()[0];
			$field = $this->getSort();
		} else {
			list($alias, $field) = explode('.', $this->getSort());
			$key = array_search($alias, $this->queryBuilder->getRootAliases());
			if (false === $key) {
				$key = 0;
			}
			$entity = $this->queryBuilder->getRootEntities()[$key];
		}

		$metadata = $this->queryBuilder->getEntityManager()->getClassMetadata($entity);

		if ($metadata->hasField($field)) {
			$this->queryBuilder->addOrderBy("{$alias}.{$field}", $this->getDirection());
		}
	}

}
