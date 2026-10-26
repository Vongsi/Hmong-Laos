<div class="stack" style="gap:16px">
    @if (! $user)
        <p class="panel">{{ __('site.shop.sell_join') }} <a href="{{ $joinUrl }}">{{ __('site.nav.join') }}</a></p>
    @elseif ($mode === 'form')
        <form class="join-card" wire:submit="save">
            <h2 class="h-sm">{{ $editing ? __('site.sell.edit_title') : __('site.sell.new_title') }}</h2>
            <p class="muted small">{{ __('site.sell.review_help') }}</p>

            <div class="field">
                <span>{{ __('site.sell.photos', ['max' => $maxPhotos]) }}</span>
                {{-- The first photo is the main one (CSS labels it). --}}
                <ul class="sell-photos">
                    @foreach ($keptThumbs as $i => $thumb)
                        <li wire:key="kept-{{ $keptPhotos[$i] }}" data-main="{{ __('site.sell.main_photo') }}">
                            @if ($thumb)<img src="{{ $thumb }}" alt="">@endif
                            <button type="button" class="sell-photo-x" wire:click="removeKept({{ $i }})" aria-label="{{ __('site.sell.photo_remove') }}">×</button>
                        </li>
                    @endforeach
                    @foreach ($photos as $i => $photo)
                        <li wire:key="new-{{ $photo->getFilename() }}" data-main="{{ __('site.sell.main_photo') }}">
                            @if ($photo->isPreviewable())<img src="{{ $photo->temporaryUrl() }}" alt="">@endif
                            <button type="button" class="sell-photo-x" wire:click="removeNew({{ $i }})" aria-label="{{ __('site.sell.photo_remove') }}">×</button>
                        </li>
                    @endforeach
                    @if (count($keptPhotos) + count($photos) < $maxPhotos)
                        <li class="sell-add">
                            <label>
                                <span aria-hidden="true">+</span>
                                <span class="small">{{ __('site.sell.photo_add') }}</span>
                                <input class="sr" type="file" wire:model="upload" accept="image/jpeg,image/png,image/webp" multiple>
                            </label>
                        </li>
                    @endif
                </ul>
                <p class="muted small" wire:loading wire:target="upload">{{ __('site.sell.uploading') }}</p>
                <p class="muted small" style="font-weight:500">{{ __('site.sell.photos_help') }}</p>
                @if ($errors->first('photos') ?: $errors->first('photos.*') ?: $errors->first('upload.*'))
                    <p class="error small">{{ $errors->first('photos') ?: $errors->first('photos.*') ?: $errors->first('upload.*') }}</p>
                @endif
            </div>

            <label class="field">{{ __('site.sell.name') }}
                <input wire:model="title" maxlength="120">
            </label>
            @error('title') <p class="error small">{{ $message }}</p> @enderror
            <label class="field">{{ __('site.sell.summary') }}
                <input wire:model="summary" maxlength="300">
            </label>
            @error('summary') <p class="error small">{{ $message }}</p> @enderror
            <label class="field">{{ __('site.sell.description') }}
                <textarea wire:model="description" rows="5" maxlength="5000"></textarea>
            </label>
            @error('description') <p class="error small">{{ $message }}</p> @enderror

            <div class="sell-row">
                <label class="field">{{ __('site.sell.price') }}
                    <input wire:model="price" inputmode="numeric" maxlength="11">
                </label>
                <label class="field">{{ __('site.sell.stock') }}
                    <input wire:model="stock" inputmode="numeric" maxlength="6">
                </label>
            </div>
            @error('price') <p class="error small">{{ $message }}</p> @enderror
            @error('stock') <p class="error small">{{ $message }}</p> @enderror
            <p class="muted small">{{ __('site.sell.stock_help') }}</p>

            <label class="field">{{ __('site.sell.sizes') }}
                <input wire:model="sizes" maxlength="200" placeholder="S, M, L">
            </label>
            @error('sizes') <p class="error small">{{ $message }}</p> @enderror

            @if ($colourOptions->isNotEmpty())
                <fieldset class="sizes colours">
                    <legend class="small" style="font-weight:600">{{ __('site.sell.colours') }}</legend>
                    @foreach ($colourOptions as $c)
                        <label class="size"><input type="checkbox" wire:model="colours" value="{{ $c['slug'] }}"><span><i class="swatch" style="--swatch:{{ $c['swatch'] ?: 'transparent' }}" aria-hidden="true"></i>{{ $c['name'] }}</span></label>
                    @endforeach
                </fieldset>
                @error('colours.*') <p class="error small">{{ $message }}</p> @enderror
            @endif

            <div class="sell-row">
                <label class="field">{{ __('site.sell.category') }}
                    <select wire:model="category">
                        <option value="">—</option>
                        @foreach ($categoryOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">{{ __('site.sell.province') }}
                    <select wire:model="province">
                        <option value="">—</option>
                        @foreach ($provinceOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <label class="field">{{ __('site.sell.maker') }}
                <input wire:model="maker" maxlength="150">
            </label>
            @error('maker') <p class="error small">{{ $message }}</p> @enderror
            <label class="field">{{ __('site.sell.contact') }}
                <input type="tel" wire:model="contact" maxlength="60" autocomplete="tel">
            </label>
            @error('contact') <p class="error small">{{ $message }}</p> @enderror
            <p class="muted small">{{ __('site.sell.contact_help') }}</p>

            @error('form') <p class="error small" role="alert">{{ $message }}</p> @enderror
            <div class="sell-actions">
                <button class="btn red" type="submit" wire:loading.attr="disabled" wire:target="save,upload">{{ __('site.sell.send') }}</button>
                <button class="btn ghost" type="button" wire:click="cancel">{{ __('site.sell.cancel') }}</button>
            </div>
        </form>
    @else
        @if ($sent)<p class="flash" role="status">{{ __('site.sell.sent') }}</p>@endif
        <div class="sell-head">
            <h2 class="h-sm">{{ __('site.sell.mine') }}</h2>
            <button class="btn red" type="button" wire:click="create">+ {{ __('site.sell.add') }}</button>
        </div>
        @if ($mine->isEmpty())
            <p class="panel muted">{{ __('site.sell.none') }}</p>
        @else
            <ul class="cart-lines">
                @foreach ($mine as $p)
                    <li class="cart-line" wire:key="p-{{ $p->id }}">
                        @if ($p->thumb)<img src="{{ $p->thumb }}" alt="">@else<span class="cart-ph"></span>@endif
                        <div class="cart-info">
                            <b>@if ($p->url)<a href="{{ $p->url }}">{{ $p->title }}</a>@else{{ $p->title }}@endif</b>
                            <span class="small muted">{{ \App\Shop\Shop::kip($p->price) }}</span>
                            <span class="sell-status {{ $p->status }}">{{ __('site.sell.status_'.$p->status) }}</span>
                            @if ($p->status === 'changes')
                                <p class="sell-note small"><b>{{ __('site.sell.team_says') }}</b> {{ $p->note }}</p>
                            @elseif ($p->status === 'live')
                                <span class="small muted">{{ __('site.sell.live_help') }}</span>
                            @endif
                        </div>
                        @if ($p->status !== 'live')
                            <div class="cart-actions sell-line-actions">
                                <button class="link small" type="button" wire:click="edit('{{ $p->id }}')">{{ __('site.sell.edit') }}</button>
                                <button class="link small" type="button" wire:click="withdraw('{{ $p->id }}')" wire:confirm="{{ __('site.sell.withdraw_confirm') }}" style="color:var(--red)">{{ __('site.sell.withdraw') }}</button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
