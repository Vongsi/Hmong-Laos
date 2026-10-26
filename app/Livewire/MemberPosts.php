<?php

namespace App\Livewire;

use App\Appearance\Background;
use App\Livewire\Concerns\ManagesSubmissions;
use App\Shop\Shop;
use App\Support\PlainText;
use App\Support\RichText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Term;

/**
 * "Share" for signed-in members: news for the Feed, events and inspiring stories (Inspire), in simple forms
 * with a photo from their phone. Each is saved as a draft in the language the member is using; a moderator
 * checks it in the control panel and publishes it, or writes a "Message to the member"
 * (App\Listeners\TrackMemberSubmission). Members can change or withdraw it until it is published.
 */
class MemberPosts extends Component
{
    use ManagesSubmissions, WithFileUploads;

    /** What members can share: its collection, photo and summary fields, and photo folder. */
    const TYPES = [
        'post' => ['collection' => 'posts', 'photo' => 'image', 'summary' => 'excerpt', 'folder' => 'posts'],
        'event' => ['collection' => 'events', 'photo' => 'cover', 'summary' => 'summary', 'folder' => 'events'],
        'story' => ['collection' => 'interviews', 'photo' => 'portrait', 'summary' => 'intro', 'folder' => 'inspire'],
    ];

    // Things (new or changed) one member can send per hour.
    const PER_HOUR = 6;

    const MAX_QUESTIONS = 15;

    // Photos in the text of one post or event, and uploads while writing it.
    const MAX_TEXT_PHOTOS = 12;

    const MAX_TEXT_UPLOADS = 20;

    #[Locked]
    public string $site;

    public string $mode = 'list';

    #[Locked]
    public string $type = 'post';

    #[Locked]
    public ?string $editing = null;

    public string $title = '';

    public string $summary = '';

    /** News text or event details: HTML from the rich text box. */
    public string $body = '';

    /** The text as it was when changing started; unchanged, the team's version is kept as it is. */
    #[Locked]
    public string $bodyLoaded = '';

    public string $province = '';

    public string $category = '';

    public string $topic = '';

    /** A new photo chosen in this form. */
    public $photo = null;

    /** Keep the photo already saved, when changing. */
    public bool $keepPhoto = false;

    public string $starts = '';

    public string $ends = '';

    public string $venue = '';

    public string $mapUrl = '';

    public string $phone = '';

    public string $fee = '';

    public bool $rsvp = true;

    public bool $volunteers = false;

    public string $person = '';

    public string $role = '';

    public string $videoUrl = '';

    /** @var list<array{question: string, answer: string}> */
    public array $qa = [];

    /** A photo being added to the text (public/js/rich-text.js uploads it, then calls storeTextPhoto). */
    public $textPhoto = null;

    /**
     * Photos added to the text while this form is open (asset paths). Only these and the photos the
     * text already had may be in the saved text; the ones not used in the end are deleted.
     */
    #[Locked]
    public array $textUploads = [];

    public bool $sent = false;

    public function mount(): void
    {
        $this->site = Site::current()->handle();

        // Buttons on the Feed, Events and Inspire pages open the right form straight away.
        $type = request()->query('type');
        if (Auth::check() && is_string($type) && isset(self::TYPES[$type])) {
            $this->create($type);
        }
    }

    public function create(string $type): void
    {
        abort_unless(isset(self::TYPES[$type]) && Auth::check(), 404);
        $this->resetForm();
        $this->type = $type;
        $this->qa = $type === 'story' ? [['question' => '', 'answer' => '']] : [];
        $this->mode = 'form';
    }

    public function edit(string $id): void
    {
        $entry = $this->ownDraft($id, array_column(self::TYPES, 'collection'));
        $type = self::typeOf($entry);
        $config = self::TYPES[$type];

        $this->resetForm();
        $this->type = $type;
        $this->editing = $entry->id();
        $this->title = (string) $entry->get('title');
        $this->summary = (string) $entry->get($config['summary']);
        $this->province = (string) self::first($entry->get('provinces'));
        $this->keepPhoto = (bool) self::first($entry->get($config['photo']));

        if ($type === 'post') {
            $this->body = $this->bodyLoaded = RichText::toHtml($entry->get('content'));
            $this->category = (string) self::first($entry->get('categories'));
        } elseif ($type === 'event') {
            $this->body = $this->bodyLoaded = RichText::toHtml($entry->get('content'));
            $this->starts = self::localInput($entry->get('start_date'));
            $this->ends = self::localInput($entry->get('end_date'));
            $this->venue = (string) $entry->get('venue');
            $this->mapUrl = (string) $entry->get('map_url');
            $this->phone = (string) $entry->get('contact_phone');
            $this->fee = (string) $entry->get('entry_fee');
            $this->rsvp = (bool) ($entry->get('rsvp_enabled') ?? true);
            $this->volunteers = (bool) $entry->get('volunteers_needed');
        } else {
            $this->person = (string) $entry->get('person_name');
            $this->role = (string) $entry->get('person_role');
            $this->videoUrl = (string) $entry->get('video_url');
            $this->topic = (string) self::first($entry->get('topics'));
            $this->qa = collect((array) $entry->get('qa'))->where('type', 'question_answer')
                ->map(fn ($row) => ['question' => (string) ($row['question'] ?? ''), 'answer' => (string) ($row['answer'] ?? '')])
                ->values()->all() ?: [['question' => '', 'answer' => '']];
        }

        $this->mode = 'form';
    }

    public function cancel(): void
    {
        $this->discardTextUploads();
        $this->resetForm();
        $this->mode = 'list';
    }

    /** Saves a photo for the text and returns its address for the editor. */
    public function storeTextPhoto(): ?array
    {
        abort_unless(Auth::check() && $this->mode === 'form' && $this->type !== 'story', 403);

        $this->validate(['textPhoto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192']], $this->messages());
        if (count($this->textUploads) >= self::MAX_TEXT_UPLOADS || ! $this->allowSubmission('share-photo', 60)) {
            $this->textPhoto = null;
            $this->addError('body', __('site.contribute.too_many_photos', ['max' => self::MAX_TEXT_PHOTOS]));

            return null;
        }

        $path = self::storePhotos([$this->textPhoto], self::photoFolder(self::TYPES[$this->type]['folder'], Auth::id()))[0] ?? null;
        $this->textPhoto = null;
        $url = $path ? Asset::find(RichText::CONTAINER.'::'.$path)?->url() : null;
        if (! $url) {
            $this->addError('body', __('site.contribute.photo_failed'));

            return null;
        }
        $this->textUploads[] = $path;

        return ['src' => $url];
    }

    public function updatedPhoto(): void
    {
        $this->validate(['photo' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192']], $this->messages());
    }

    public function removePhoto(): void
    {
        $this->photo = null;
        $this->keepPhoto = false;
    }

    public function addQuestion(): void
    {
        if (count($this->qa) < self::MAX_QUESTIONS) {
            $this->qa[] = ['question' => '', 'answer' => ''];
        }
    }

    public function removeQuestion(int $index): void
    {
        unset($this->qa[$index]);
        $this->qa = array_values($this->qa) ?: [['question' => '', 'answer' => '']];
    }

    public function save(): void
    {
        $user = Auth::user();
        abort_unless($user, 403);
        $entry = $this->editing ? $this->ownDraft($this->editing, [self::TYPES[$this->type]['collection']]) : null;
        $config = self::TYPES[$this->type];

        $this->validate($this->rules(), $this->messages());
        if (! $this->allowSubmission('share', self::PER_HOUR)) {
            $this->addError('form', __('site.contribute.too_many'));

            return;
        }

        $folder = self::photoFolder($config['folder'], $user->getAuthIdentifier());
        $oldPhoto = $entry ? self::first($entry->get($config['photo'])) : null;
        $oldTextPhotos = $entry ? RichText::imagePaths(self::bardNodes($entry->get('content'))) : [];
        $photo = self::storePhotos([$this->photo], $folder)[0] ?? ($this->keepPhoto ? $oldPhoto : null);

        $data = [
            'title' => PlainText::line($this->title),
            $config['summary'] => PlainText::line($this->summary) ?: null,
            $config['photo'] => $photo,
            'provinces' => $this->province ? [$this->province] : [],
            'submitted_by' => [(string) $user->getAuthIdentifier()],
            // Moderators can't look up member accounts, so the name is kept with what they sent.
            'submitted_by_name' => PlainText::line($user->name),
            'submitted_at' => $entry?->get('submitted_at') ?? now()->toIso8601String(),
            // Sending it again answers the moderator's message.
            'review_note' => null,
            'review_note_notified' => null,
        ] + $this->typeData($entry, $user->getAuthIdentifier());

        if ($entry) {
            $entry->merge($data)->save();
        } else {
            $entry = Entry::make()->collection($config['collection'])->locale($this->site)
                ->slug((Str::slug($data['title']) ?: $this->type).'-'.Str::lower(Str::random(5)))
                ->published(false)->data($data);
            if (Collection::findByHandle($config['collection'])->dated()) {
                $entry->date(now());
            }
            $entry->save();
        }

        // Photos no longer used: the old main photo, photos taken out of the text, uploads not used in it.
        $used = [$photo, ...RichText::imagePaths(self::bardNodes($data['content'] ?? null))];
        self::deleteOwnPhotos(array_diff(array_filter([$oldPhoto, ...$oldTextPhotos, ...$this->textUploads]), $used), $folder);
        $this->textUploads = [];

        $kind = ['post' => 'News post', 'event' => 'Event', 'story' => 'Inspiring story'][$this->type];
        self::tellTeam($entry, $this->editing ? "{$kind} changed, ready to review again" : 'New '.Str::lower($kind).' to review', array_filter([
            'Sent by: '.$data['submitted_by_name'],
            'Language: '.Site::get($entry->locale())->name(),
            $this->type === 'event' ? 'Starts: '.$this->starts.' (Laos time)' : null,
        ]));

        $this->resetForm();
        $this->mode = 'list';
        $this->sent = true;
    }

    public function withdraw(string $id): void
    {
        $entry = $this->ownDraft($id, array_column(self::TYPES, 'collection'));
        $config = self::TYPES[self::typeOf($entry)];
        $photos = [self::first($entry->get($config['photo'])), ...RichText::imagePaths(self::bardNodes($entry->get('content')))];
        self::deleteOwnPhotos($photos, self::photoFolder($config['folder'], Auth::id()));
        self::deleteSubmission($entry);
    }

    /** All checks at once, so members see every problem in one go. */
    private function rules(): array
    {
        // Required texts must still say something once HTML is taken out.
        $filled = fn ($attribute, $value, $fail) => PlainText::line((string) $value) === '' ? $fail(__('site.shop.required')) : null;

        $rules = [
            'title' => ['required', 'string', 'max:150', $filled],
            'summary' => ['nullable', 'string', 'max:500'],
            'province' => ['nullable', Rule::in($this->options('provinces')->keys()->all())],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];

        return $rules + match ($this->type) {
            'post' => [
                'body' => ['required', 'string', 'max:200000', $this->richCheck(10000, true)],
                'category' => ['nullable', Rule::in($this->options('categories')->keys()->all())],
            ],
            'event' => [
                'body' => ['nullable', 'string', 'max:200000', $this->richCheck(5000, false)],
                'starts' => ['required', 'date_format:Y-m-d\TH:i', function ($attribute, $value, $fail) {
                    // Members change drafts of events that are about to start, so allow the last hour.
                    if (self::parseLocal((string) $value)?->lt(now()->subHour())) {
                        $fail(__('site.contribute.starts_past'));
                    }
                }],
                'ends' => ['nullable', 'date_format:Y-m-d\TH:i', function ($attribute, $value, $fail) {
                    $starts = self::parseLocal($this->starts);
                    if ($starts && self::parseLocal((string) $value)?->lte($starts)) {
                        $fail(__('site.contribute.ends_before'));
                    }
                }],
                'venue' => ['required', 'string', 'max:200', $filled],
                'mapUrl' => ['nullable', 'url:http,https', 'max:500'],
                'phone' => ['nullable', 'string', 'max:40'],
                'fee' => ['nullable', 'string', 'max:60'],
            ],
            'story' => [
                'person' => ['required', 'string', 'max:120', $filled],
                'role' => ['nullable', 'string', 'max:150'],
                'videoUrl' => ['nullable', 'url:http,https', 'max:500'],
                'topic' => ['nullable', Rule::in($this->options('topics')->keys()->all())],
                'qa' => ['array', 'max:'.self::MAX_QUESTIONS, function ($attribute, $value, $fail) {
                    if (! collect($value)->contains(fn ($pair) => ! self::halfAnswered($pair) && PlainText::line($pair['question'] ?? '') !== '')) {
                        $fail(__('site.contribute.qa_required'));
                    }
                }],
                'qa.*' => ['array', fn ($attribute, $value, $fail) => self::halfAnswered($value) ? $fail(__('site.contribute.qa_both')) : null],
                'qa.*.question' => ['nullable', 'string', 'max:300'],
                'qa.*.answer' => ['nullable', 'string', 'max:5000'],
            ],
        };
    }

    /** Checks the rich text box: something written (if required), not too long, not too many photos. */
    private function richCheck(int $max, bool $required): \Closure
    {
        return function ($attribute, $value, $fail) use ($max, $required) {
            $nodes = RichText::toBard((string) $value, $this->allowedTextPhotos());
            $length = RichText::textLength($nodes);
            if ($required && $length === 0) {
                $fail(__('site.shop.required'));
            } elseif ($length > $max) {
                $fail(__('site.contribute.too_long', ['max' => number_format($max)]));
            } elseif (count(RichText::imagePaths($nodes)) > self::MAX_TEXT_PHOTOS) {
                $fail(__('site.contribute.too_many_photos', ['max' => self::MAX_TEXT_PHOTOS]));
            }
        };
    }

    /** The rich text as Bard content; when changing and the text wasn't touched, the saved version as it is. */
    private function richContent(?EntryContract $entry): mixed
    {
        if ($entry && $this->body === $this->bodyLoaded) {
            return $entry->get('content');
        }

        return RichText::toBard($this->body, $this->allowedTextPhotos());
    }

    /** Photos the text may show: uploaded in this form, or already in the text being changed. */
    private function allowedTextPhotos(): \Closure
    {
        $saved = $this->editing ? RichText::imagePaths(self::bardNodes(Entry::find($this->editing)?->get('content'))) : [];
        $allowed = [...$this->textUploads, ...$saved];

        return fn (string $path) => in_array($path, $allowed, true);
    }

    /** Bard content as nodes (older content may be saved as HTML). */
    private static function bardNodes(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /** Deletes photos uploaded for the text in this form that the saved text doesn't use. */
    private function discardTextUploads(): void
    {
        if (! $this->textUploads || ! Auth::id()) {
            return;
        }
        $saved = $this->editing ? RichText::imagePaths(self::bardNodes(Entry::find($this->editing)?->get('content'))) : [];
        self::deleteOwnPhotos(array_diff($this->textUploads, $saved), self::photoFolder(self::TYPES[$this->type]['folder'], Auth::id()));
        $this->textUploads = [];
    }

    /** A question without its answer, or an answer without its question. */
    private static function halfAnswered(mixed $pair): bool
    {
        return (PlainText::line($pair['question'] ?? '') === '') !== (PlainText::lines($pair['answer'] ?? '') === '');
    }

    /** The fields only this type has. */
    private function typeData(?EntryContract $entry, $userId): array
    {
        return match ($this->type) {
            'post' => [
                'content' => $this->richContent($entry),
                'categories' => $this->category ? [$this->category] : [],
                'author' => (string) $userId,
            ],
            'event' => [
                'content' => $this->richContent($entry),
                'start_date' => self::parseLocal($this->starts)?->utc()->format('Y-m-d H:i'),
                'end_date' => self::parseLocal($this->ends)?->utc()->format('Y-m-d H:i'),
                'venue' => PlainText::line($this->venue),
                'map_url' => PlainText::link($this->mapUrl),
                'contact_phone' => PlainText::line($this->phone) ?: null,
                'entry_fee' => PlainText::line($this->fee) ?: null,
                'rsvp_enabled' => $this->rsvp,
                'volunteers_needed' => $this->volunteers,
            ],
            'story' => [
                'person_name' => PlainText::line($this->person),
                'person_role' => PlainText::line($this->role) ?: null,
                'video_url' => PlainText::link($this->videoUrl),
                'topics' => $this->topic ? [$this->topic] : [],
                'qa' => $this->interviewRows($entry),
            ],
        };
    }

    /**
     * The member's questions and answers as interview blocks. When changing, blocks the team added
     * (quotes, photos) stay where they were and the member's answers replace the old ones in order.
     */
    private function interviewRows(?EntryContract $entry): array
    {
        $pairs = collect($this->qa)
            ->map(fn ($pair) => ['question' => PlainText::line($pair['question'] ?? ''), 'answer' => PlainText::lines($pair['answer'] ?? '')])
            ->filter(fn ($pair) => $pair['question'] !== '' && $pair['answer'] !== '')
            ->values()->all();

        $rows = [];
        $next = 0;
        foreach ((array) $entry?->get('qa') as $row) {
            if (($row['type'] ?? null) !== 'question_answer') {
                $rows[] = $row;
            } elseif (isset($pairs[$next])) {
                $rows[] = array_merge($row, $pairs[$next++]);
            } else {
                $next++;
            }
        }
        foreach (array_slice($pairs, $next) as $pair) {
            $rows[] = ['id' => Str::random(8), 'type' => 'question_answer', 'enabled' => true] + $pair;
        }

        return $rows;
    }

    private static function typeOf(EntryContract $entry): string
    {
        return collect(self::TYPES)->search(fn ($config) => $config['collection'] === $entry->collectionHandle());
    }

    /** A field holding one value may store it as "x" or ["x"]. */
    private static function first(mixed $value): ?string
    {
        $value = is_array($value) ? ($value[0] ?? null) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function timezone(): string
    {
        return config('statamic.system.display_timezone') ?: Background::TIMEZONE;
    }

    /** A date picker value ("2026-11-14T09:00", Laos time). */
    private static function parseLocal(string $value): ?Carbon
    {
        return $value === '' ? null : rescue(fn () => Carbon::createFromFormat('Y-m-d\TH:i', $value, self::timezone()), null, false);
    }

    /** A saved date (UTC) for the date picker, in Laos time. */
    private static function localInput(?string $value): string
    {
        return $value ? Carbon::parse($value, 'UTC')->setTimezone(self::timezone())->format('Y-m-d\TH:i') : '';
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'title', 'summary', 'body', 'bodyLoaded', 'textPhoto', 'textUploads', 'province', 'category', 'topic', 'photo', 'keepPhoto', 'starts', 'ends', 'venue', 'mapUrl', 'phone', 'fee', 'rsvp', 'volunteers', 'person', 'role', 'videoUrl', 'qa', 'sent');
        $this->resetErrorBag();
    }

    protected function messages(): array
    {
        return [
            '*.required' => __('site.shop.required'),
            '*.max' => __('site.shop.invalid'),
            '*.in' => __('site.shop.invalid'),
            '*.date_format' => __('site.shop.invalid'),
            '*.url' => __('site.contribute.link_invalid'),
            'photo.image' => __('site.sell.photo_type'),
            'photo.mimes' => __('site.sell.photo_type'),
            'photo.max' => __('site.sell.photo_size'),
            'textPhoto.image' => __('site.sell.photo_type'),
            'textPhoto.mimes' => __('site.sell.photo_type'),
            'textPhoto.max' => __('site.sell.photo_size'),
        ];
    }

    /** slug => title in the visitor's language, for a taxonomy. */
    private function options(string $taxonomy)
    {
        return Term::query()->where('taxonomy', $taxonomy)->get()
            ->mapWithKeys(fn ($term) => [$term->slug() => ($term->in($this->site) ?? $term)->title()])
            ->sort();
    }

    public function render()
    {
        $mine = $this->mySubmissions(array_column(self::TYPES, 'collection'))->map(function ($entry) {
            $type = self::typeOf($entry);
            $start = $type === 'event' && $entry->get('start_date') ? Carbon::parse($entry->get('start_date'), 'UTC')->setTimezone(self::timezone()) : null;

            return (object) [
                'id' => $entry->id(),
                'type' => $type,
                'title' => $entry->value('title'),
                'thumb' => Shop::photoUrl(self::first($entry->get(self::TYPES[$type]['photo'])), 160, 160),
                'when' => $start?->translatedFormat('j M Y, H:i'),
                'language' => Site::get($entry->locale())->name(),
                'status' => self::reviewStatus($entry),
                'note' => $entry->get('review_note'),
                'url' => $entry->published() ? $entry->url() : null,
            ];
        });

        $savedPhoto = $this->editing && $this->keepPhoto
            ? self::first(Entry::find($this->editing)?->get(self::TYPES[$this->type]['photo']))
            : null;

        return view('livewire.member-posts', [
            'user' => Auth::user(),
            'mine' => $mine,
            'categoryOptions' => $this->options('categories'),
            'topicOptions' => $this->options('topics'),
            'provinceOptions' => $this->options('provinces'),
            'photoThumb' => $this->photo && method_exists($this->photo, 'isPreviewable') && $this->photo->isPreviewable()
                ? $this->photo->temporaryUrl()
                : ($savedPhoto ? Shop::photoUrl($savedPhoto, 320, 320) : null),
            'languageName' => Site::get($this->site)->name(),
            // Where uploaded photos are served from; the editor keeps only images from there.
            'photoPrefix' => rtrim((string) parse_url((string) AssetContainer::find(RichText::CONTAINER)?->url(), PHP_URL_PATH), '/').'/',
            'joinUrl' => Shop::url($this->site, 'join'),
            'rulesUrl' => Shop::url($this->site, 'rules'),
            'maxQuestions' => self::MAX_QUESTIONS,
        ]);
    }
}
