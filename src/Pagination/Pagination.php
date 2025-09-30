<?php

namespace App\Pagination;

/**
 * Class Pagination.
 *
 * @implements \IteratorAggregate<int, mixed>
 *
 * @author Ashley Dawson <ashley@ashleydawson.co.uk>
 */
class Pagination implements \IteratorAggregate, \Countable
{
    private array $items = [];
    private array $pages = [];
    private int $totalNumberOfPages = 0;
    private int $currentPageNumber = 0;
    private int $firstPageNumber = 0;
    private int $lastPageNumber = 0;
    private int $previousPageNumber = 0;
    private int $nextPageNumber = 0;
    private int $itemsPerPage = 0;
    private int $totalNumberOfItems = 0;
    private int $firstPageNumberInRange = 0;
    private int $lastPageNumberInRange = 0;

    /**
     * Get items.
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Set items.
     *
     * @return $this
     */
    public function setItems(array $items)
    {
        $this->items = $items;

        return $this;
    }

    /**
     * Get currentPageNumber.
     *
     * @return int
     */
    public function getCurrentPageNumber()
    {
        return $this->currentPageNumber;
    }

    /**
     * Set currentPageNumber.
     *
     * @param int $currentPageNumber
     *
     * @return $this
     */
    public function setCurrentPageNumber($currentPageNumber)
    {
        $this->currentPageNumber = $currentPageNumber;

        return $this;
    }

    /**
     * Get firstPageNumber.
     *
     * @return int
     */
    public function getFirstPageNumber()
    {
        return $this->firstPageNumber;
    }

    /**
     * Set firstPageNumber.
     *
     * @param int $firstPageNumber
     *
     * @return $this
     */
    public function setFirstPageNumber($firstPageNumber)
    {
        $this->firstPageNumber = $firstPageNumber;

        return $this;
    }

    /**
     * Get firstPageNumberInRange.
     *
     * @return int
     */
    public function getFirstPageNumberInRange()
    {
        return $this->firstPageNumberInRange;
    }

    /**
     * Set firstPageNumberInRange.
     *
     * @param int $firstPageNumberInRange
     *
     * @return $this
     */
    public function setFirstPageNumberInRange($firstPageNumberInRange)
    {
        $this->firstPageNumberInRange = $firstPageNumberInRange;

        return $this;
    }

    /**
     * Get itemsPerPage.
     *
     * @return int
     */
    public function getItemsPerPage()
    {
        return $this->itemsPerPage;
    }

    /**
     * Set itemsPerPage.
     *
     * @param int $itemsPerPage
     *
     * @return $this
     */
    public function setItemsPerPage($itemsPerPage)
    {
        $this->itemsPerPage = $itemsPerPage;

        return $this;
    }

    /**
     * Get lastPageNumber.
     *
     * @return int
     */
    public function getLastPageNumber()
    {
        return $this->lastPageNumber;
    }

    /**
     * Set lastPageNumber.
     *
     * @param int $lastPageNumber
     *
     * @return $this
     */
    public function setLastPageNumber($lastPageNumber)
    {
        $this->lastPageNumber = $lastPageNumber;

        return $this;
    }

    /**
     * Get lastPageNumberInRange.
     *
     * @return int
     */
    public function getLastPageNumberInRange()
    {
        return $this->lastPageNumberInRange;
    }

    /**
     * Set lastPageNumberInRange.
     *
     * @param int $lastPageNumberInRange
     *
     * @return $this
     */
    public function setLastPageNumberInRange($lastPageNumberInRange)
    {
        $this->lastPageNumberInRange = $lastPageNumberInRange;

        return $this;
    }

    /**
     * Get nextPageNumber.
     *
     * @return int
     */
    public function getNextPageNumber()
    {
        return $this->nextPageNumber;
    }

    /**
     * Set nextPageNumber.
     *
     * @param int $nextPageNumber
     *
     * @return $this
     */
    public function setNextPageNumber($nextPageNumber)
    {
        $this->nextPageNumber = $nextPageNumber;

        return $this;
    }

    /**
     * Get pages.
     *
     * @return array
     */
    public function getPages()
    {
        return $this->pages;
    }

    /**
     * Set pages.
     *
     * @return $this
     */
    public function setPages(array $pages)
    {
        $this->pages = $pages;

        return $this;
    }

    /**
     * Get previousPageNumber.
     *
     * @return int
     */
    public function getPreviousPageNumber()
    {
        return $this->previousPageNumber;
    }

    /**
     * Set previousPageNumber.
     *
     * @param int $previousPageNumber
     *
     * @return $this
     */
    public function setPreviousPageNumber($previousPageNumber)
    {
        $this->previousPageNumber = $previousPageNumber;

        return $this;
    }

    /**
     * Get totalNumberOfItems.
     *
     * @return int
     */
    public function getTotalNumberOfItems()
    {
        return $this->totalNumberOfItems;
    }

    /**
     * Set totalNumberOfItems.
     *
     * @param int $totalNumberOfItems
     *
     * @return $this
     */
    public function setTotalNumberOfItems($totalNumberOfItems)
    {
        $this->totalNumberOfItems = $totalNumberOfItems;

        return $this;
    }

    /**
     * Get totalNumberOfPages.
     *
     * @return int
     */
    public function getTotalNumberOfPages()
    {
        return $this->totalNumberOfPages;
    }

    /**
     * Set totalNumberOfPages.
     *
     * @param int $totalNumberOfPages
     *
     * @return $this
     */
    public function setTotalNumberOfPages($totalNumberOfPages)
    {
        $this->totalNumberOfPages = $totalNumberOfPages;

        return $this;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }
}
