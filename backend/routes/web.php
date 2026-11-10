<?php

use Illuminate\Support\Facades\Route;

/*
| L'API ne sert aucune page HTML : la racine répond en JSON, sans révéler le framework.
*/

Route::get('/', fn () => response()->json(['name' => 'Loopartner API']));
