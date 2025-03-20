<?php

namespace App\Pagination;

use App\Pagination\Exception\CallbackNotFoundException;
use App\Pagination\Exception\InvalidPageNumberException;

/**
 * Class Paginator.
 *
 * @author Ashley Dawson <ashley@ashleydawson.co.uk>
 */
class Paginator implements PaginatorInterface
{
    /**
     * @var \Closure
     */
    private $itemTotalCallback;

    /**
     * @var \Closure
     */
    private $sliceCallback;

    /**
     * @var \Closure
     */
    private $beforeQueryCallback;

    /**
     * @var \Closure
     */
    private $afterQueryCallback;

    /**
     * @var int
     */
    private $itemsPerPage = 10;

    /**
     * @var int
     */
    private $pagesInRange = 5;

    /**
     * Constructor - passing optional configuration.
     *
     * <code>
     * $paginator = new Paginator(array(
     *     'itemTotalCallback' => function () {
     *         // ...
     *     },
     *     'sliceCallback' => function ($offset, $length) {
     *         // ...
     *     },
     *     'itemsPerPage' => 10,
     *     'pagesInRange' => 5
     * ));
     * </code>
     */
    public function __construct(?array $config = null)
    {
        if (\is_array($config)) {
            $this->setItemTotalCallback($config['itemTotalCallback']);
            $this->setSliceCallback($config['sliceCallback']);
            $this->setItemsPerPage($config['itemsPerPage']);
            $this->setPagesInRange($config['pagesInRange']);
        }
    }

    public function paginate($currentPageNumber = 1)
    {
        if (!($this->itemTotalCallback instanceof \Closure)) {
            throw new CallbackNotFoundException('Item total callback not found, set it using Paginator::setItemTotalCallback()');
        }

        if (!($this->sliceCallback instanceof \Closure)) {
            throw new CallbackNotFoundException('Slice callback not found, set it using Paginator::setSliceCallback()');
        }

        if (!\is_int($currentPageNumber)) {
            throw new \InvalidArgumentException(\sprintf('Current page number must be of type integer, %s given', \gettype($currentPageNumber)));
        }

        if ($currentPageNumber <= 0) {
            throw new InvalidPageNumberException(\sprintf('Current page number must have a value of 1 or more, %s given', $currentPageNumber));
        }

        $beforeQueryCallback = $this->beforeQueryCallback instanceof \Closure
            ? $this->beforeQueryCallback
            : function () {}
        ;

        $afterQueryCallback = $this->afterQueryCallback instanceof \Closure
            ? $this->afterQueryCallback
            : function () {}
        ;

        $pagination = new Pagination();

        $itemTotalCallback = $this->itemTotalCallback;

        $beforeQueryCallback($this, $pagination);
        $totalNumberOfItems = (int) $itemTotalCallback($pagination);
        $afterQueryCallback($this, $pagination);

        $numberOfPages = (int) ceil($totalNumberOfItems / $this->itemsPerPage);
        $pagesInRange = $this->pagesInRange;

        if ($pagesInRange > $numberOfPages) {
            $pagesInRange = $numberOfPages;
        }

        $change = (int) ceil($pagesInRange / 2);

        if (($currentPageNumber - $change) > ($numberOfPages - $pagesInRange)) {
            $pages = range(($numberOfPages - $pagesInRange) + 1, $numberOfPages);
        } else {
            if (($currentPageNumber - $change) < 0) {
                $change = $currentPageNumber;
            }
            $offset = $currentPageNumber - $change;
            $pages = range($offset + 1, $offset + $pagesInRange);
        }

        $offset = ($currentPageNumber - 1) * $this->itemsPerPage;

        $sliceCallback = $this->sliceCallback;

        $beforeQueryCallback($this, $pagination);
        if (-1 === $this->itemsPerPage) {
            $items = $sliceCallback(0, 999999999, $pagination);
        } else {
            $items = $sliceCallback($offset, $this->itemsPerPage, $pagination);
        }
        if ($items instanceof \Iterator) {
            $items = iterator_to_array($items);
        }
        $afterQueryCallback($this, $pagination);

        $previousPageNumber = null;
        if (($currentPageNumber - 1) > 0) {
            $previousPageNumber = $currentPageNumber - 1;
        }

        $nextPageNumber = null;
        if (($currentPageNumber + 1) <= $numberOfPages) {
            $nextPageNumber = $currentPageNumber + 1;
        }

        $pagination
            ->setItems($items)
            ->setPages($pages)
            ->setTotalNumberOfPages($numberOfPages)
            ->setCurrentPageNumber($currentPageNumber)
            ->setFirstPageNumber(1)
            ->setLastPageNumber($numberOfPages)
            ->setPreviousPageNumber($previousPageNumber)
            ->setNextPageNumber($nextPageNumber)
            ->setItemsPerPage($this->itemsPerPage)
            ->setTotalNumberOfItems($totalNumberOfItems)
            ->setFirstPageNumberInRange(min($pages))
            ->setLastPageNumberInRange(max($pages))
        ;

        return $pagination;
    }

    public function getSliceCallback()
    {
        return $this->sliceCallback;
    }

    public function setSliceCallback(\Closure $sliceCallback)
    {
        $this->sliceCallback = $sliceCallback;

        return $this;
    }

    public function getItemTotalCallback()
    {
        return $this->itemTotalCallback;
    }

    public function getBeforeQueryCallback()
    {
        return $this->beforeQueryCallback;
    }

    public function setBeforeQueryCallback($beforeQueryCallback)
    {
        $this->beforeQueryCallback = $beforeQueryCallback;

        return $this;
    }

    public function getAfterQueryCallback()
    {
        return $this->afterQueryCallback;
    }

    public function setAfterQueryCallback($afterQueryCallback)
    {
        $this->afterQueryCallback = $afterQueryCallback;

        return $this;
    }

    public function setItemTotalCallback(\Closure $itemTotalCallback)
    {
        $this->itemTotalCallback = $itemTotalCallback;

        return $this;
    }

    public function getItemsPerPage()
    {
        return $this->itemsPerPage;
    }

    public function setItemsPerPage($itemsPerPage)
    {
        if (!\is_int($itemsPerPage)) {
            throw new \InvalidArgumentException(\sprintf('Items per page must be of type integer, %s given', \gettype($itemsPerPage)));
        }

        $this->itemsPerPage = $itemsPerPage;

        return $this;
    }

    public function getPagesInRange()
    {
        return $this->pagesInRange;
    }

    public function setPagesInRange($pagesInRange)
    {
        if (!\is_int($pagesInRange)) {
            throw new \InvalidArgumentException(\sprintf('Pages in range must be of type integer, %s given', \gettype($pagesInRange)));
        }

        $this->pagesInRange = $pagesInRange;

        return $this;
    }
}
