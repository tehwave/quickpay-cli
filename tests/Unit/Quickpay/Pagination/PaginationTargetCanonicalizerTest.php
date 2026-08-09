<?php

use App\Quickpay\Pagination\PaginationTargetCanonicalizer;

it('canonicalizes equivalent query parameter order', function () {
    expect(PaginationTargetCanonicalizer::canonical('/payments?state=processed&page_size=20'))
        ->toBe(PaginationTargetCanonicalizer::canonical('/payments?page_size=20&state=processed'));
});

it('preserves repeated and bracket-list values without collapsing their order', function () {
    $first = PaginationTargetCanonicalizer::canonical(
        '/payments?filter%5B%5D=one&filter%5B%5D=two&tag=a&tag=b&page_size=20',
    );
    $reorderedKeys = PaginationTargetCanonicalizer::canonical(
        '/payments?page_size=20&tag=a&tag=b&filter%5B%5D=one&filter%5B%5D=two',
    );
    $reorderedValues = PaginationTargetCanonicalizer::canonical(
        '/payments?page_size=20&tag=b&tag=a&filter%5B%5D=two&filter%5B%5D=one',
    );

    expect($first)->toBe($reorderedKeys)
        ->not->toBe($reorderedValues);
});
