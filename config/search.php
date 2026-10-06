<?php

declare(strict_types=1);

return [
    // DatabaseSearchDriver clamps SearchCriteria::$perPage to between 1 and this value.
    'max_per_page' => 100,
];
