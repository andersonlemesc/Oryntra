<?php

declare(strict_types=1);

use App\Support\Net\SsrfGuard;

it('blocks link-local, metadata, multicast and reserved addresses', function (string $ip) {
    expect((new SsrfGuard)->ipIsBlocked($ip))->toBeTrue();
})->with([
    '169.254.169.254',          // cloud metadata
    '169.254.0.1',              // link-local
    '0.0.0.0',                  // unspecified
    '224.0.0.1',                // multicast
    '240.0.0.1',                // reserved
    'fe80::1',                  // IPv6 link-local
    '::',                       // IPv6 unspecified
    '::ffff:169.254.169.254',   // IPv4-mapped metadata
]);

it('allows private, loopback and public addresses', function (string $ip) {
    expect((new SsrfGuard)->ipIsBlocked($ip))->toBeFalse();
})->with([
    '10.0.0.5',     // private
    '172.16.3.4',   // private
    '192.168.1.20', // private
    '127.0.0.1',    // loopback
    '::1',          // IPv6 loopback
    '8.8.8.8',      // public
]);

it('blocks a literal metadata host and allows an unresolvable host', function () {
    $guard = new SsrfGuard;

    expect($guard->hostIsBlocked('169.254.169.254'))->toBeTrue()
        ->and($guard->hostIsBlocked('api.example.test'))->toBeFalse();
});
