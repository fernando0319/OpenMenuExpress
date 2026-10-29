<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Store;
use App\Support\CurrentStore;
use Illuminate\Database\Eloquent\Model;

trait InteractsWithCurrentStore
{
    protected function currentStore(): Store
    {
        return app(CurrentStore::class)->require();
    }

    /**
     * Records from another store answer 404, as if they did not exist.
     */
    protected function ensureBelongsToCurrentStore(Model $model): void
    {
        abort_unless((int) $model->getAttribute('store_id') === $this->currentStore()->id, 404);
    }
}
