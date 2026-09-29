<?php

return [
    'ids' => array_map(
        static fn (int $number): string => sprintf('avatar-%02d', $number),
        range(1, 8),
    ),
];
