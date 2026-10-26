<?php

namespace App\Bard;

/**
 * Size and position for photos in Bard text: small or medium (none = full width), left or right
 * (none = centred). Members choose them in the Share editor (public/js/rich-text.js); the control panel
 * keeps them when the team saves (public/vendor/app/js/cp.js). On the site they become classes,
 * styled in public/css/site.css (.prose .photo-…).
 */
trait PhotoLayout
{
    public function addAttributes()
    {
        return parent::addAttributes() + [
            'size' => self::layoutAttribute('size', ['small', 'medium']),
            'align' => self::layoutAttribute('align', ['left', 'right']),
        ];
    }

    private static function layoutAttribute(string $name, array $values): array
    {
        return [
            'parseHTML' => function ($DOMNode) use ($name, $values) {
                $value = $DOMNode->getAttribute('data-'.$name);

                return in_array($value, $values, true) ? $value : null;
            },
            'renderHTML' => function ($attributes) use ($name, $values) {
                $value = $attributes->{$name} ?? null;

                return in_array($value, $values, true) ? ['data-'.$name => $value, 'class' => 'photo-'.$value] : null;
            },
        ];
    }
}
