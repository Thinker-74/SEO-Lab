<?php
/*
 * Semina P1.2 (wp eval-file, solo sandbox): due rapporti idonei dalle fixture 07 e 10 sul contenuto indicato da
 * P1_POST_ID, scritti con WordPressReportArchive reale nel sito corrente (--url). Stampa "P1WP {json}" con fixture e UUID,
 * senza contenuti del rapporto.
 */

function p1wp_check(string $name, bool $ok, string $detail = ''): void
{
    echo 'P1WP ' . json_encode(['check' => $name, 'ok' => $ok, 'detail' => $detail], JSON_UNESCAPED_SLASHES) . "\n";
}

$postId = (int) getenv('P1_POST_ID');
$archive = new SeoLab\Storage\WordPressReportArchive();
$schema = $archive->ensureSchema();
p1wp_check('schema_pronto', $schema && $postId > 0, 'post ' . $postId);

foreach (['07', '10'] as $prefix) {
    $files = glob('/seolab/tests/fixtures/' . $prefix . '-*.json');
    $fixture = json_decode((string) file_get_contents($files[0]), true);
    $data = $fixture['scans'][0]['report'];
    $data['post_id'] = $postId;
    $report = new SeoLab\Contracts\ScanReport($data);
    $archive->save($report);
    $found = $archive->find($report->scanId());
    echo 'P1WP ' . json_encode(['fixture' => $prefix, 'uuid' => $report->scanId()], JSON_UNESCAPED_SLASHES) . "\n";
    p1wp_check('rapporto_' . $prefix . '_archiviato', $found !== null && $found->postId() === $postId);
}
