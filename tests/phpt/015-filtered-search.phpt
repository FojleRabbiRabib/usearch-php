--TEST--
filteredSearch honours the predicate, propagates throws, and forbids mutation from the callback.
--FILE--
<?php

use Usearch\Index;
use Usearch\Metric;

$idx = new Index(['dimensions' => 2, 'metric' => Metric::L2sq]);
for ($i = 1; $i <= 6; $i++) {
    $idx->add($i, [(float) $i, 0.0]);
}

/* 1. Only even keys pass; nearest-first order among the survivors. */
$rows = $idx->filteredSearch([0.0, 0.0], fn (int $key): bool => $key % 2 === 0, 10);
var_dump(array_column($rows, 'key'));

/* 2. A predicate matching nothing returns an empty set, not an error. */
var_dump($idx->filteredSearch([0.0, 0.0], fn (int $key): bool => false, 10));

/* 3. An always-true predicate returns exactly what search() returns. */
var_dump(
    array_column($idx->filteredSearch([0.0, 0.0], fn (int $key): bool => true, 3), 'key')
        === array_column($idx->search([0.0, 0.0], 3), 'key')
);

/* 4. A throw inside the predicate propagates, and the index survives. */
try {
    $idx->filteredSearch([0.0, 0.0], function (int $key): bool {
        throw new RuntimeException('FILTER_FAIL at ' . $key);
    }, 10);
    echo "FAIL: throwing predicate did not propagate\n";
} catch (RuntimeException $e) {
    echo "PASS: " . $e->getMessage() . "\n";
}
var_dump($idx->size());
var_dump(count($idx->search([0.0, 0.0], 10)));

/* 5. The callback may not mutate the index being searched. */
try {
    $idx->filteredSearch([0.0, 0.0], function (int $key) use ($idx): bool {
        $idx->add(99, [1.0, 0.0]);
        return true;
    }, 10);
    echo "FAIL: mutating callback did not raise\n";
} catch (Usearch\Exception $e) {
    echo "PASS: " . $e->getMessage() . "\n";
}
var_dump($idx->size());
var_dump($idx->contains(99));

/* 6. Count larger than the index clamps, with and without a filter. */
var_dump(count($idx->filteredSearch([0.0, 0.0], fn (int $key): bool => true, 100)));
var_dump(count($idx->filteredSearch([0.0, 0.0], fn (int $key): bool => $key <= 2, 100)));

/* 7. Non-positive counts are rejected like search(). */
try {
    $idx->filteredSearch([0.0, 0.0], fn (int $key): bool => true, 0);
    echo "FAIL: k=0 did not throw\n";
} catch (ValueError $e) {
    echo "PASS: " . $e->getMessage() . "\n";
}
?>
--EXPECTF--
array(3) {
  [0]=>
  int(2)
  [1]=>
  int(4)
  [2]=>
  int(6)
}
array(0) {
}
bool(true)
PASS: FILTER_FAIL at 1
int(6)
int(6)
PASS: cannot mutate the index while a filteredSearch() callback is running
int(6)
bool(false)
int(6)
int(2)
PASS: count must be a positive integer
