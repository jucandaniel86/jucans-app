<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

class TrimStrings extends Middleware
{
    /**
     * The names of the attributes that should not be trimmed.
     *
     * @var array<int, string>
     */
    protected $except = [
        'current_password',
        'password',
        'password_confirmation',
        // Note Type templates are plain text; keep indentation and boundary line breaks.
        'template',
    ];

    protected function transform($key, $value)
    {
        $noteWrite = (request()->isMethod('POST') && preg_match('#^api/projects/[0-9]+/notes$#', request()->path()))
            || (request()->isMethod('PATCH') && preg_match('#^api/projects/[0-9]+/notes/[0-9]+$#', request()->path()));
        if ($noteWrite && preg_match('/^values\.\d+\.value$/', $key)) {
            return $value;
        }

        return parent::transform($key, $value);
    }
}
