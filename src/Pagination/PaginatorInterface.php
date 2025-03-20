<?php

namespace App\Pagination;

/**
 * Interface PaginatorInterface.
 *
 * @author Ashley Dawson <ashley@ashleydawson.co.uk>
 */
interface PaginatorInterface
{
    /**
     * Run paginate algorithm using the current page number.
     *
     * @param int $currentPageNumber Page number, usually passed from the current request
     *
     * @throws \InvalidArgumentException
     * @throws InvalidPageNumberException
     *
     * @return Pagination Collection of items returned by the slice callback with pagination meta information
     */
    public function paginate($currentPageNumber = 1);

    /**
     * Get sliceCallback.
     *
     * @return \Closure
     */
    public function getSliceCallback();

    /**
     * Set sliceCallback.
     *
     * @return $this
     */
    public function setSliceCallback(\Closure $sliceCallback);

    /**
     * Get itemTotalCallback.
     *
     * @return \Closure
     */
    public function getItemTotalCallback();

    /**
     * Set itemTotalCallback.
     *
     * @return $this
     */
    public function setItemTotalCallback(\Closure $itemTotalCallback);

    /**
     * @return \Closure
     */
    public function getBeforeQueryCallback();

    /**
     * @return $this
     */
    public function setBeforeQueryCallback(\Closure $beforeQueryCallback);

    /**
     * @return \Closure
     */
    public function getAfterQueryCallback();

    /**
     * @return $this
     */
    public function setAfterQueryCallback(\Closure $afterQueryCallback);

    /**
     * Get itemsPerPage.
     *
     * @return int
     */
    public function getItemsPerPage();

    /**
     * Set itemsPerPage.
     *
     * @param int $itemsPerPage
     *
     * @throws \InvalidArgumentException
     *
     * @return $this
     */
    public function setItemsPerPage($itemsPerPage);

    /**
     * Get pagesInRange.
     *
     * @return int
     */
    public function getPagesInRange();

    /**
     * Set pagesInRange.
     *
     * @param int $pagesInRange
     *
     * @throws \InvalidArgumentException
     *
     * @return $this
     */
    public function setPagesInRange($pagesInRange);
}
