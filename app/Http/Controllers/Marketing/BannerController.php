<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\BannerLinkType;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Coupon;
use App\Models\Store;
use App\Support\ImageStorage;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BannerController extends Controller
{
    use InteractsWithCurrentStore;

    public function __construct(private readonly ImageStorage $images) {}

    public function index(): Response
    {
        $store = $this->currentStore();
        $now = CarbonImmutable::now();

        return Inertia::render('marketing/Banners', [
            'banners' => $store->banners()->orderBy('sort_order')->orderBy('id')->get()->map(fn (Banner $banner) => [
                'id' => $banner->id,
                'title' => $banner->title,
                'imageUrl' => $banner->imageUrl(),
                'linkType' => $banner->link_type->value,
                'linkTarget' => $banner->link_target,
                'linkLabel' => $banner->link_type->label(),
                'startsAt' => $banner->starts_at?->setTimezone($store->timezone)->format('Y-m-d\TH:i') ?? '',
                'endsAt' => $banner->ends_at?->setTimezone($store->timezone)->format('Y-m-d\TH:i') ?? '',
                'isActive' => $banner->is_active,
                'isShowing' => $banner->is_active
                    && ($banner->starts_at === null || $banner->starts_at->lessThanOrEqualTo($now))
                    && ($banner->ends_at === null || $banner->ends_at->greaterThan($now)),
            ]),
            'linkTypes' => array_map(
                fn (BannerLinkType $type) => ['value' => $type->value, 'label' => $type->label()],
                BannerLinkType::cases(),
            ),
            'products' => $store->products()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'categories' => $store->categories()->orderBy('sort_order')->get(['id', 'name']),
            'coupons' => $store->coupons()->where('is_active', true)->orderBy('code')->pluck('code'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->currentStore();
        $data = $this->validated($request, $store, imageRequired: true);

        $store->banners()->create([
            ...$data,
            'image_path' => $this->images->store($request->file('image'), $store, 'banners'),
            'sort_order' => (int) $store->banners()->max('sort_order') + 1,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Banner criado.']);

        return to_route('marketing.banners.index');
    }

    public function update(Request $request, Banner $banner): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($banner);
        $store = $this->currentStore();
        $data = $this->validated($request, $store, imageRequired: false);

        if ($request->hasFile('image')) {
            $this->images->delete($banner->image_path);
            $data['image_path'] = $this->images->store($request->file('image'), $store, 'banners');
        }

        $banner->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Banner atualizado.']);

        return to_route('marketing.banners.index');
    }

    public function move(Request $request, Banner $banner): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($banner);

        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];
        $ordered = $this->currentStore()->banners()->orderBy('sort_order')->orderBy('id')->get()->values();
        $position = $ordered->search(fn (Banner $item) => $item->id === $banner->id);
        $target = $direction === 'up' ? $position - 1 : $position + 1;

        if ($position !== false && $target >= 0 && $target < $ordered->count()) {
            $ids = $ordered->pluck('id')->all();
            [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];

            DB::transaction(function () use ($ids): void {
                foreach ($ids as $order => $id) {
                    Banner::query()->whereKey($id)->update(['sort_order' => $order]);
                }
            });
        }

        return to_route('marketing.banners.index');
    }

    public function destroy(Banner $banner): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($banner);

        $this->images->delete($banner->image_path);
        $banner->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Banner excluído.']);

        return to_route('marketing.banners.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Store $store, bool $imageRequired): array
    {
        $type = BannerLinkType::tryFrom((string) $request->input('link_type')) ?? BannerLinkType::None;

        $request->merge([
            'starts_at' => $this->localDate($request->input('starts_at'), $store),
            'ends_at' => $this->localDate($request->input('ends_at'), $store),
            'link_target' => $type === BannerLinkType::Coupon
                ? Coupon::normalizeCode((string) $request->input('link_target'))
                : $request->input('link_target'),
        ]);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'image' => [$imageRequired ? 'required' : 'nullable', ...ImageStorage::rules()],
            'link_type' => ['required', Rule::enum(BannerLinkType::class)],
            'link_target' => match ($type) {
                BannerLinkType::None => ['nullable'],
                BannerLinkType::Product => ['required', Rule::exists('products', 'id')->where('store_id', $store->id)->whereNull('deleted_at')],
                BannerLinkType::Category => ['required', Rule::exists('categories', 'id')->where('store_id', $store->id)],
                BannerLinkType::Coupon => ['required', Rule::exists('coupons', 'code')->where('store_id', $store->id)],
                BannerLinkType::Url => ['required', 'url:http,https', 'max:255'],
            },
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['boolean'],
        ], [
            'image.required' => 'Escolha a imagem do banner.',
            'image.max' => 'A imagem pode ter no máximo 4 MB.',
            'link_target.required' => 'Escolha para onde o banner leva.',
            'link_target.exists' => 'Não encontrei isso na sua loja.',
            'link_target.url' => 'Informe um link completo, começando com https://',
        ]);

        unset($data['image']);

        $data['link_target'] = $type === BannerLinkType::None ? null : (string) $data['link_target'];

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function localDate(mixed $value, Store $store): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $store->timezone)->utc()->toDateTimeString();
        } catch (Throwable) {
            return $value;
        }
    }
}
