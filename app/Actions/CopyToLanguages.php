<?php

namespace App\Actions;

use Illuminate\Support\Collection;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * "Copy to other languages" in the control panel (an entry's ⋯ menu, and for several entries at once in the
 * list). Makes the Hmong, Lao and English versions an entry doesn't have yet, the same way Statamic's
 * "Working in" panel does one at a time: each copy starts with the text and photos of the entry it was made
 * from and keeps following it until someone changes a field there (to translate it).
 *
 * Products are left out: they get every language automatically when they are created.
 */
class CopyToLanguages extends Action
{
    const COLLECTIONS = ['posts', 'events', 'interviews', 'pages'];

    protected $icon = 'globe-world-wide-web';

    public static function title()
    {
        return 'Copy to other languages';
    }

    public function visibleTo($item)
    {
        return $this->copyable($item) && $this->missingSites(collect([$item]))->isNotEmpty();
    }

    public function visibleToBulk($items)
    {
        return $items->every(fn ($item) => $this->copyable($item)) && $this->missingSites($items)->isNotEmpty();
    }

    public function authorize($user, $entry)
    {
        return $user->can('edit', $entry);
    }

    public function buttonText()
    {
        /** @translation */
        return 'Make the copies';
    }

    public function confirmationText()
    {
        /** @translation */
        return 'Each copy starts with the same text and photos, and follows this entry until you change a field in that language. Translate a copy by opening it under "Working in".';
    }

    protected function fieldItems()
    {
        $missing = $this->missingSites($this->items ?? collect());

        return [
            'sites' => [
                'type' => 'checkboxes',
                'display' => 'Languages',
                'options' => $missing->all(),
                'default' => $missing->keys()->all(),
                'validate' => ['required'],
            ],
            'publish' => [
                'type' => 'toggle',
                'display' => 'Publish the copies now',
                'instructions' => 'Leave off to translate them first. When on, they show on the site straight away (in the original language until translated). Copies of a draft stay drafts.',
                'default' => false,
            ],
        ];
    }

    public function run($entries, $values)
    {
        $user = User::current();
        $made = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            foreach ((array) ($values['sites'] ?? []) as $site) {
                if (! in_array($site, $entry->collection()->sites()->all(), true) || $entry->in($site)) {
                    continue;
                }
                if (! $user->can('create', [Entry::class, $entry->collection(), Site::get($site)])) {
                    $skipped++;

                    continue;
                }

                // From the entry chosen (which may itself be a translation), like "Working in" does. (Its
                // store() always saves a draft, so the copy is saved directly with the chosen state.)
                $entry->makeLocalization($site)
                    ->published(($values['publish'] ?? false) && $entry->published())
                    ->updateLastModified($user)
                    ->save();
                $made++;
            }
        }

        $message = $made === 1 ? '1 copy made.' : "{$made} copies made.";

        return $skipped ? "{$message} {$skipped} skipped: you can't add entries in that language." : $message.' Open them under "Working in" to translate.';
    }

    private function copyable($item): bool
    {
        return $item instanceof Entry && in_array($item->collectionHandle(), self::COLLECTIONS, true) && $item->collection()->sites()->count() > 1;
    }

    /**
     * Languages (handle => name) that at least one of these entries doesn't have yet.
     *
     * @return Collection<string, string>
     */
    private function missingSites(Collection $items): Collection
    {
        return Site::all()
            ->filter(fn ($site) => $items->contains(fn ($entry) => $entry instanceof Entry
                && in_array($site->handle(), $entry->collection()->sites()->all(), true)
                && ! $entry->in($site->handle())))
            ->mapWithKeys(fn ($site) => [$site->handle() => $site->name()]);
    }
}
