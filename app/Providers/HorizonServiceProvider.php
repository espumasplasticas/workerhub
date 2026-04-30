<?php

namespace App\Providers;

use App\Services\Auth\WorkerHubOperatorSessionManager;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(function ($request) {
            return app(WorkerHubOperatorSessionManager::class)->isAuthorized($request);
        });
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return app(WorkerHubOperatorSessionManager::class)->isAuthorized(request());
        });
    }
}
