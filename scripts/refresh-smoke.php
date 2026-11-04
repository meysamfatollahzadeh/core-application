<?php

use App\Modules\Login\Models\PersonalAccessToken;
use App\Modules\Login\Services\GrantVerifier;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$token = PersonalAccessToken::where('provider_sid', $argv[1])->whereHas('member', fn ($query) => $query->whereHas('userSource', fn ($sources) => $sources->where('slug', 'primary')))->firstOrFail();
$grant = $token->oidc_data;
$grant['access_expires_at'] = time() - 1;
$token->oidc_data = $grant;
$token->save();
$counter = 'login:acceptance-refresh:'.bin2hex(random_bytes(16));
Cache::put($counter, 0, 120);
Http::globalRequestMiddleware(function (RequestInterface $request) use ($counter): RequestInterface {
    parse_str((string) $request->getBody(), $body);
    if (($body['grant_type'] ?? null) === 'refresh_token') {
        Cache::increment($counter);
    }
    return $request;
});
DB::disconnect();
Cache::store()->getStore()->connection()->disconnect();
$children = [];
for ($index = 0; $index < 3; $index++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Cannot fork refresh acceptance workers.');
    }
    if ($pid === 0) {
        try {
            app(GrantVerifier::class)->verify($token);
            exit(0);
        } catch (Throwable) {
            exit(1);
        }
    }
    $children[] = $pid;
}
$successful = true;
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
    $successful = $successful && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
}
$requests = Cache::get($counter);
Cache::forget($counter);
if (! $successful || (int) $requests !== 1) {
    throw new RuntimeException('Concurrent provider refresh acceptance failed.');
}
echo "PASS: three independent PHP workers performed exactly one upstream refresh\n";
