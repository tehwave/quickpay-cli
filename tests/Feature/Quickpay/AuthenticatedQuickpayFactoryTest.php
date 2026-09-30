<?php

use App\Credentials\ApiKeyResolver;
use App\Credentials\EnvironmentVariables;
use App\Credentials\MerchantStore;
use App\Credentials\ProjectMerchantFile;
use App\Quickpay\AuthenticatedQuickpayFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('pins selected credential context to the authenticated client', function (): void {
    $directory = sys_get_temp_dir().'/quickpay-factory-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $original = getenv('QUICKPAY_API_KEY');
    putenv('QUICKPAY_API_KEY');
    try {
        $store = new MerchantStore($directory.'/config.json');
        $store->add('shop-a', 'fake-secret-a');
        $store->add('shop-b', 'fake-secret-b');
        $factory = new AuthenticatedQuickpayFactory(Http::getFacadeRoot(), new ApiKeyResolver(new EnvironmentVariables, $store, new ProjectMerchantFile($directory)));
        $authenticated = $factory->resolve('shop-b');
        $store->remove('shop-b');
        Http::fake(['*' => Http::response(['scope' => 'merchant'])]);
        $authenticated->client->get('/ping');
        expect($authenticated->apiKey->merchant())->toBe('shop-b')->and($authenticated->apiKey->selectionSource())->toBe('explicit');
        Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Basic '.base64_encode(':fake-secret-b')]);
    } finally {
        $original === false ? putenv('QUICKPAY_API_KEY') : putenv('QUICKPAY_API_KEY='.$original);
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
