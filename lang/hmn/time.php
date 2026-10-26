<?php

// Hmong words for relative times ("5 feeb dhau los"), used by Carbon's diffForHumans().
// Carbon ships Lao and English but not Hmong; AppServiceProvider loads this file.
// Draft: please have a native speaker review.
return [
    'year' => ':count xyoos',
    'a_year' => ':count xyoos',
    'month' => ':count hlis',
    'a_month' => ':count hlis',
    'week' => ':count lub lim tiam',
    'a_week' => ':count lub lim tiam',
    'day' => ':count hnub',
    'a_day' => ':count hnub',
    'hour' => ':count teev',
    'a_hour' => ':count teev',
    'minute' => ':count feeb',
    'a_minute' => ':count feeb',
    'second' => ':count vib nas this',
    'a_second' => 'ob peb vib nas this',
    'ago' => ':time dhau los',
    'from_now' => ':time tom ntej',
    'after' => ':time tom qab',
    'before' => ':time ua ntej',
    'diff_now' => 'tam sim no',
    'diff_yesterday' => 'nag hmo',
    'diff_tomorrow' => 'tag kis',
];
