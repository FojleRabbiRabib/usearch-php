--TEST--
A custom metric callback measures, ranks, propagates throws, and refuses re-entrancy.
--FILE--
<?php

use Usearch\Index;
use Usearch\Metric;

/* L1 over packed f32 pairs — the trampoline hands the stored-format bytes. */
$l1 = function (string $a, string $b): float {
    $x = unpack('g2', $a);
    $y = unpack('g2', $b);
    return abs($x[1] - $y[1]) + abs($x[2] - $y[2]);
};

$idx = new Index(['dimensions' => 2]);
$idx->add(1, [1.0, 2.0]);
$idx->add(2, [4.0, 6.0]);
$idx->changeMetric($l1);

/* 1. distance() measures through the callback. */
var_dump($idx->distance([1.0, 2.0], [4.0, 6.0]));
var_dump($idx->distance([0.0, 0.0], [1.0, 1.0]));

/* 2. search() ranks by the callback. Nearest to [1.0, 2.0] under L1 is key 1. */
var_dump($idx->search([1.0, 2.0], 2)[0]['key']);
var_dump($idx->search([5.0, 5.0], 2)[0]['key']);

/* 3. A throw inside the metric propagates, from distance() and search(). */
$idx->changeMetric(function (string $a, string $b): float {
    throw new RuntimeException('METRIC_FAIL');
});
try {
    $idx->distance([1.0, 2.0], [4.0, 6.0]);
    echo "FAIL: throwing metric did not propagate from distance()\n";
} catch (RuntimeException $e) {
    echo "PASS: " . $e->getMessage() . "\n";
}
try {
    $idx->search([1.0, 2.0], 1);
    echo "FAIL: throwing metric did not propagate from search()\n";
} catch (RuntimeException $e) {
    echo "PASS: " . $e->getMessage() . "\n";
}

/* 4. The metric may not mutate the index, and may not re-enter it. */
$idx->changeMetric(function (string $a, string $b) use ($idx): float {
    $idx->add(99, [0.0, 0.0]);
    return 0.0;
});
try {
    $idx->distance([1.0, 2.0], [4.0, 6.0]);
    echo "FAIL: mutating metric did not raise\n";
} catch (Usearch\Exception $e) {
    echo "PASS: " . $e->getMessage() . "\n";
}
$idx->changeMetric(function (string $a, string $b) use ($idx): float {
    $idx->distance([0.0, 0.0], [1.0, 1.0]);
    return 0.0;
});
try {
    $idx->distance([1.0, 2.0], [4.0, 6.0]);
    echo "FAIL: re-entrant metric did not raise\n";
} catch (Usearch\Exception $e) {
    echo "PASS: " . $e->getMessage() . "\n";
}
var_dump($idx->contains(99));

/* 5. Switching back to a built-in space detaches the callable. */
$idx->changeMetric(Metric::L2sq);
var_dump($idx->distance([1.0, 2.0], [4.0, 6.0]));

/* 6. The index stays fully usable after all of that. */
var_dump($idx->size());
var_dump(count($idx->search([1.0, 2.0], 2)));
?>
--EXPECTF--
float(7)
float(2)
int(1)
int(2)
PASS: METRIC_FAIL
PASS: METRIC_FAIL
PASS: cannot mutate the index while its custom metric callback is running
PASS: cannot measure inside this index's own custom metric callback
bool(false)
float(25)
int(2)
int(2)
