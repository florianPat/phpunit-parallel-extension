<?php
declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Runner\Filter;

use PHPUnit\Framework\TestSuite;
use RecursiveFilterIterator;
use RecursiveIterator;
use function array_key_exists;
use function assert;

final class ParallelFilter extends RecursiveFilterIterator
{
    private static int $currentId = 0;
    private readonly int $threadId;
    private readonly int $nCores;

    public function __construct(
        RecursiveIterator $iterator,
        array $params,
        TestSuite $suite,
    ) {
        parent::__construct($iterator);

        assert(array_key_exists('THREAD_ID', $params));
        assert(array_key_exists('N_CORES', $params));

        $this->threadId = $GLOBALS['THREAD_ID'];
        $this->nCores   = $GLOBALS['N_CORES'];
    }

    public function accept(): bool
    {
        $test = $this->getInnerIterator()->current();

        if ($test instanceof TestSuite) {
            return true;
        }

        $result = (self::$currentId % $this->nCores) === $this->threadId;
        self::$currentId++;

        return $result;
    }
}
