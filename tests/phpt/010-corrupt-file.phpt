--TEST--
Corrupt and truncated index files are rejected with Usearch\Exception.
--FILE--
<?php

use Usearch\Index;

$good = tempnam(sys_get_temp_dir(), 'usearch-good-');
$idx = new Index(['dimensions' => 3]);
for ($i = 0; $i < 20; $i++) {
    $idx->add($i, [(float) $i, 0.5, -0.5]);
}
$idx->save($good);
$fullSize = filesize($good);

/* 1. A file that is not an index at all. */
$junk = tempnam(sys_get_temp_dir(), 'usearch-junk-');
file_put_contents($junk, "this is not a usearch index\n");

/* 2. A zero-length file. */
$empty = tempnam(sys_get_temp_dir(), 'usearch-empty-');

/* 3. Real headers, truncated body. */
$cut = tempnam(sys_get_temp_dir(), 'usearch-cut-');
copy($good, $cut);
$fh = fopen($cut, 'r+');
ftruncate($fh, (int) round($fullSize / 4));
fclose($fh);

foreach (['load', 'view'] as $method) {
    foreach (['junk' => $junk, 'empty' => $empty, 'truncated' => $cut] as $label => $path) {
        $target = new Index(['dimensions' => 3]);
        try {
            $target->$method($path);
            echo "FAIL: {$method}({$label}) did not throw\n";
        } catch (Usearch\Exception $e) {
            echo "PASS: {$method}({$label}) rejected\n";
        }
    }
}

/* A missing path is rejected too. */
try {
    (new Index(['dimensions' => 3]))->load($good . '.does-not-exist');
    echo "FAIL: load(missing) did not throw\n";
} catch (Usearch\Exception $e) {
    echo "PASS: load(missing) rejected\n";
}

/* The intact file still loads and searches after all that. */
$ok = new Index(['dimensions' => 3]);
$ok->load($good);
var_dump($ok->size());
var_dump(count($ok->search([1.0, 0.5, -0.5], 3)));

unlink($good);
unlink($junk);
unlink($empty);
unlink($cut);
?>
--EXPECTF--
PASS: load(junk) rejected
PASS: load(empty) rejected
PASS: load(truncated) rejected
PASS: view(junk) rejected
PASS: view(empty) rejected
PASS: view(truncated) rejected
PASS: load(missing) rejected
int(20)
int(3)
