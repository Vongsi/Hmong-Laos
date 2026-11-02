<div class="stack" style="gap:16px">
    @if (! $user)
        <p class="panel">{{ __('site.contribute.join') }} <a href="{{ $joinUrl }}">{{ __('site.nav.join') }}</a></p>
    @elseif ($mode === 'form')
        <form class="join-card" wire:submit="save">
            <h2 class="h-sm">{{ __('site.contribute.'.($editing ? 'edit_' : 'new_').$type) }}</h2>
            <p class="muted small">{{ __('site.contribute.review_help') }} {{ __('site.contribute.language_note', ['language' => $languageName]) }}</p>

            <div class="field">
                <span>{{ __($type === 'story' ? 'site.contribute.portrait' : 'site.contribute.photo') }}</span>
                <div class="share-photo">
                    @if ($photoThumb)
                        <div class="share-photo-preview">
                            <img src="{{ $photoThumb }}" alt="">
                            <button type="button" class="sell-photo-x" wire:click="removePhoto" aria-label="{{ __('site.sell.photo_remove') }}">×</button>
                        </div>
                    @endif
                    <label class="share-photo-pick">
                        <span aria-hidden="true">+</span>
                        <span class="small">{{ __($photoThumb ? 'site.contribute.photo_change' : 'site.contribute.photo_add') }}</span>
                        <input class="sr" type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp">
                    </label>
                </div>
                <p class="muted small" wire:loading wire:target="photo">{{ __('site.sell.uploading') }}</p>
                @error('photo') <p class="error small">{{ $message }}</p> @enderror
            </div>

            @if ($type === 'story')
                <label class="field">{{ __('site.contribute.person') }}
                    <input wire:model="person" maxlength="120" autocomplete="off" placeholder="{{ __('site.ph.person') }}">
                </label>
                @error('person') <p class="error small">{{ $message }}</p> @enderror
                <label class="field">{{ __('site.contribute.role') }}
                    <input wire:model="role" maxlength="150" placeholder="{{ __('site.contribute.role_placeholder') }}">
                </label>
                @error('role') <p class="error small">{{ $message }}</p> @enderror
            @endif

            <label class="field">{{ __('site.contribute.title_'.$type) }}
                <input wire:model="title" maxlength="150" placeholder="{{ __('site.ph.title_'.$type) }}">
            </label>
            @error('title') <p class="error small">{{ $message }}</p> @enderror

            <label class="field">{{ __('site.contribute.summary_'.$type) }}
                <textarea wire:model="summary" rows="2" maxlength="500" placeholder="{{ __('site.ph.summary_'.$type) }}"></textarea>
            </label>
            @error('summary') <p class="error small">{{ $message }}</p> @enderror

            @if ($type === 'event')
                <div class="sell-row">
                    <label class="field">{{ __('site.contribute.starts') }}
                        <input type="datetime-local" wire:model="starts">
                    </label>
                    <label class="field">{{ __('site.contribute.ends') }}
                        <input type="datetime-local" wire:model="ends">
                    </label>
                </div>
                @error('starts') <p class="error small">{{ $message }}</p> @enderror
                @error('ends') <p class="error small">{{ $message }}</p> @enderror
                <label class="field">{{ __('site.contribute.venue') }}
                    <input wire:model="venue" maxlength="200" placeholder="{{ __('site.ph.venue') }}">
                </label>
                @error('venue') <p class="error small">{{ $message }}</p> @enderror
                <label class="field">{{ __('site.contribute.map') }}
                    <input type="url" wire:model="mapUrl" maxlength="500" placeholder="https://maps.app.goo.gl/…" inputmode="url">
                </label>
                <p class="muted small">{{ __('site.contribute.map_help') }}</p>
                @error('mapUrl') <p class="error small">{{ $message }}</p> @enderror
            @endif

            @if ($type !== 'story')
                {{-- Rich text (public/js/rich-text.js); wire:ignore keeps the editor when Livewire updates the form. --}}
                <div class="field">
                    <span>{{ __('site.contribute.body_'.$type) }}</span>
                    <div class="rte {{ $type === 'post' ? 'tall' : '' }}" wire:ignore wire:key="rte-{{ $type }}-{{ $editing ?? 'new' }}"
                         x-data="richText($wire, 'body', 'textPhoto', 'storeTextPhoto')"
                         data-label="{{ __('site.contribute.body_'.$type) }}"
                         data-link-prompt="{{ __('site.contribute.link_prompt') }}"
                         data-photo-prefix="{{ $photoPrefix }}">
                        <div class="rte-toolbar" role="toolbar" aria-label="{{ __('site.contribute.formatting') }}">
                            @foreach ([
                                'bold' => '<b>B</b>',
                                'italic' => '<i style="font-family:Georgia,serif">I</i>',
                                'heading' => '<b>H</b>',
                                'subheading' => '<b style="font-size:12px">H</b>',
                                'bulletList' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="7" r="1.6"/><circle cx="5" cy="12" r="1.6"/><circle cx="5" cy="17" r="1.6"/><path d="M9.5 7h10M9.5 12h10M9.5 17h10" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>',
                                'orderedList' => '<svg viewBox="0 0 24 24" aria-hidden="true"><text x="2.5" y="9" font-size="7" font-weight="700">1</text><text x="2.5" y="19" font-size="7" font-weight="700">2</text><path d="M9.5 7h10M9.5 17h10" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>',
                                'blockquote' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 17c0-4 1-7 5-9l1 1.5c-2 1.2-2.7 2.6-2.8 4.5H11v5H5Zm8 0c0-4 1-7 5-9l1 1.5c-2 1.2-2.7 2.6-2.8 4.5H19v5h-6Z"/></svg>',
                            ] as $command => $icon)
                                <button type="button" class="rte-btn" @mousedown.prevent @click="toggle('{{ $command }}')" :aria-pressed="active.{{ $command }} ? 'true' : 'false'" :disabled="!ready"
                                        title="{{ __('site.contribute.format_'.$command) }}" aria-label="{{ __('site.contribute.format_'.$command) }}">{!! $icon !!}</button>
                            @endforeach
                            <button type="button" class="rte-btn" @mousedown.prevent @click="link()" :aria-pressed="active.link ? 'true' : 'false'" :disabled="!ready"
                                    title="{{ __('site.contribute.format_link') }}" aria-label="{{ __('site.contribute.format_link') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1.2 1.2M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1.2-1.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg></button>
                            <button type="button" class="rte-btn rte-photo-btn" @mousedown.prevent @click="pickPhotos()" :disabled="!ready"
                                    title="{{ __('site.contribute.format_image') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h1.7l1.2-1.6h5.2L15.8 5h1.7A2.5 2.5 0 0 1 20 7.5v9a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 16.5v-9Z" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linejoin="round"/><circle cx="12" cy="12" r="3.3" stroke="currentColor" stroke-width="1.8" fill="none"/></svg><span>{{ __('site.contribute.format_image') }}</span></button>
                            <input class="sr" type="file" x-ref="photoInput" accept="image/jpeg,image/png,image/webp" multiple tabindex="-1" aria-hidden="true" @change="addPhotos([...$event.target.files])">
                        </div>
                        <div class="rte-body">
                            {{-- Example text while the editor is empty (the editor has no placeholder of its own). --}}
                            <p class="rte-placeholder" x-show="empty" aria-hidden="true">{{ __('site.ph.body_'.$type) }}</p>
                            <div x-ref="area"></div>
                            {{-- Shown next to a photo when it is tapped (TipTap bubble menu). --}}
                            <div x-ref="photoMenu" class="rte-photomenu" role="toolbar" aria-label="{{ __('site.contribute.photo_menu') }}" style="visibility:hidden">
                                <div class="rte-group">
                                    @foreach (['small' => "'small'", 'medium' => "'medium'", 'full' => 'null'] as $size => $value)
                                        <button type="button" class="rte-chip" @mousedown.prevent @click="setPhoto({ size: {{ $value }} })" :aria-pressed="photo.size === {{ $value }} ? 'true' : 'false'">{{ __('site.contribute.photo_'.$size) }}</button>
                                    @endforeach
                                </div>
                                <div class="rte-group">
                                    @foreach ([
                                        'left' => ["'left'", '<path d="M4 5h7v7H4zM14 6h6M14 10h6M4 15h16M4 19h16"/>'],
                                        'center' => ['null', '<path d="M8 4h8v8H8zM4 16h16M4 20h16"/>'],
                                        'right' => ["'right'", '<path d="M13 5h7v7h-7zM4 6h6M4 10h6M4 15h16M4 19h16"/>'],
                                    ] as $align => [$value, $path])
                                        <button type="button" class="rte-btn" @mousedown.prevent @click="setPhoto({ align: {{ $value }} })" :aria-pressed="photo.align === {{ $value }} ? 'true' : 'false'"
                                                title="{{ __('site.contribute.photo_'.$align) }}" aria-label="{{ __('site.contribute.photo_'.$align) }}"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round">{!! $path !!}</svg></button>
                                    @endforeach
                                </div>
                                <div class="rte-group">
                                    <button type="button" class="rte-btn" @mousedown.prevent @click="movePhoto(-1)" title="{{ __('site.contribute.photo_up') }}" aria-label="{{ __('site.contribute.photo_up') }}"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M6 11l6-6 6 6"/></svg></button>
                                    <button type="button" class="rte-btn" @mousedown.prevent @click="movePhoto(1)" title="{{ __('site.contribute.photo_down') }}" aria-label="{{ __('site.contribute.photo_down') }}"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M6 13l6 6 6-6"/></svg></button>
                                    <button type="button" class="rte-btn danger" @mousedown.prevent @click="removePhoto()" title="{{ __('site.contribute.photo_remove') }}" aria-label="{{ __('site.contribute.photo_remove') }}"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg></button>
                                </div>
                            </div>
                        </div>
                        <p class="rte-status" x-show="uploading > 0" x-cloak role="status">{{ __('site.contribute.photo_uploading') }}</p>
                        <p class="rte-status error" x-show="failed && uploading === 0" x-cloak role="alert">{{ __('site.contribute.photo_failed') }}</p>
                    </div>
                    <p class="muted small" style="font-weight:500">{{ __('site.contribute.format_help') }}</p>
                </div>
                @error('body') <p class="error small">{{ $message }}</p> @enderror
                @error('textPhoto') <p class="error small">{{ $message }}</p> @enderror
            @endif

            @if ($type === 'event')
                <div class="sell-row">
                    <label class="field">{{ __('site.contribute.phone') }}
                        <input type="tel" wire:model="phone" maxlength="40" placeholder="{{ __('site.ph.phone') }}">
                    </label>
                    <label class="field">{{ __('site.contribute.fee') }}
                        <input wire:model="fee" maxlength="60" placeholder="{{ __('site.events.free') }}">
                    </label>
                </div>
                <p class="muted small">{{ __('site.contribute.phone_help') }}</p>
                <label class="check"><input type="checkbox" wire:model="rsvp"><span>{{ __('site.contribute.rsvp') }}</span></label>
                <label class="check"><input type="checkbox" wire:model="volunteers"><span>{{ __('site.contribute.volunteers') }}</span></label>
            @endif

            @if ($type === 'story')
                <fieldset class="share-qa">
                    <legend class="small" style="font-weight:600">{{ __('site.contribute.questions') }}</legend>
                    <p class="muted small">{{ __('site.contribute.qa_help') }}</p>
                    @foreach ($qa as $i => $pair)
                        <div class="share-qa-pair" wire:key="qa-{{ $i }}">
                            <label class="field">{{ __('site.contribute.question', ['n' => $i + 1]) }}
                                <input wire:model="qa.{{ $i }}.question" maxlength="300" placeholder="{{ __('site.ph.question') }}">
                            </label>
                            <label class="field">{{ __('site.contribute.answer') }}
                                <textarea wire:model="qa.{{ $i }}.answer" rows="4" maxlength="5000" placeholder="{{ __('site.ph.answer') }}"></textarea>
                            </label>
                            @error('qa.'.$i) <p class="error small">{{ $message }}</p> @enderror
                            @if (count($qa) > 1)
                                <button class="link small" type="button" wire:click="removeQuestion({{ $i }})" style="align-self:flex-end;color:var(--red)">{{ __('site.contribute.remove_question') }}</button>
                            @endif
                        </div>
                    @endforeach
                    @if (count($qa) < $maxQuestions)
                        <button class="btn ghost" type="button" wire:click="addQuestion" style="align-self:flex-start">+ {{ __('site.contribute.add_question') }}</button>
                    @endif
                    @error('qa') <p class="error small">{{ $message }}</p> @enderror
                </fieldset>
                <label class="field">{{ __('site.contribute.video') }}
                    <input type="url" wire:model="videoUrl" maxlength="500" placeholder="https://" inputmode="url">
                </label>
                <p class="muted small">{{ __('site.contribute.video_help') }}</p>
                @error('videoUrl') <p class="error small">{{ $message }}</p> @enderror
            @endif

            <div class="sell-row">
                @if ($type === 'post')
                    <label class="field">{{ __('site.contribute.category') }}
                        <select wire:model="category">
                            <option value="">—</option>
                            @foreach ($categoryOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                @elseif ($type === 'story')
                    <label class="field">{{ __('site.contribute.topic') }}
                        <select wire:model="topic">
                            <option value="">—</option>
                            @foreach ($topicOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <label class="field">{{ __('site.contribute.province') }}
                    <select wire:model="province">
                        <option value="">—</option>
                        @foreach ($provinceOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <p class="muted small">{{ __('site.contribute.rules') }} <a href="{{ $rulesUrl }}">{{ __('site.nav.rules') }}</a></p>
            @error('form') <p class="error small" role="alert">{{ $message }}</p> @enderror
            <div class="sell-actions">
                <button class="btn green" type="submit" wire:loading.attr="disabled" wire:target="save,photo,textPhoto,storeTextPhoto">{{ __('site.contribute.send') }}</button>
                <button class="btn red" type="button" wire:click="cancel">{{ __('site.contribute.cancel') }}</button>
            </div>
        </form>
    @else
        @if ($sent)<p class="flash" role="status">{{ __('site.contribute.sent') }}</p>@endif
        <h2 class="h-sm">{{ __('site.contribute.new_heading') }}</h2>
        <div class="share-types">
            @foreach (['post', 'event', 'story'] as $t)
                <button type="button" class="share-type" wire:click="create('{{ $t }}')">
                    <b>+ {{ __('site.contribute.type_'.$t) }}</b>
                    <span class="muted small">{{ __('site.contribute.type_'.$t.'_help') }}</span>
                </button>
            @endforeach
        </div>

        <h2 class="h-sm">{{ __('site.contribute.mine') }}</h2>
        @if ($mine->isEmpty())
            <p class="panel muted">{{ __('site.contribute.none') }}</p>
        @else
            <ul class="cart-lines">
                @foreach ($mine as $p)
                    <li class="cart-line" wire:key="p-{{ $p->id }}">
                        @if ($p->thumb)<img src="{{ $p->thumb }}" alt="">@else<span class="cart-ph"></span>@endif
                        <div class="cart-info">
                            <span class="small muted">{{ __('site.contribute.type_'.$p->type) }} · {{ $p->language }}@if ($p->when) · {{ $p->when }}@endif</span>
                            <b>@if ($p->url)<a href="{{ $p->url }}">{{ $p->title }}</a>@else{{ $p->title }}@endif</b>
                            <span class="sell-status {{ $p->status }}">{{ __('site.contribute.status_'.$p->status) }}</span>
                            @if ($p->status === 'changes')
                                <p class="sell-note small"><b>{{ __('site.contribute.team_says') }}</b> {{ $p->note }}</p>
                            @elseif ($p->status === 'live')
                                <span class="small muted">{{ __('site.contribute.live_help') }}</span>
                            @endif
                        </div>
                        @if ($p->status !== 'live')
                            <div class="cart-actions sell-line-actions">
                                <button class="link small" type="button" wire:click="edit('{{ $p->id }}')">{{ __('site.contribute.edit') }}</button>
                                <button class="link small" type="button" wire:click="withdraw('{{ $p->id }}')" wire:confirm="{{ __('site.contribute.withdraw_confirm') }}" style="color:var(--red)">{{ __('site.contribute.withdraw') }}</button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
