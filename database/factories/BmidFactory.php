<?php

use Faker\Generator as Faker;
use App\Models\Bmid;

$factory->define(Bmid::class, function (Faker $faker) {
    return [
        'status'    => 'in_prep',
        'showers'   => false,
        'meals'     => null,
    ];
});
