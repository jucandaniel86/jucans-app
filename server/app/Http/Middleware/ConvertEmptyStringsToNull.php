<?php

namespace App\Http\Middleware;

class ConvertEmptyStringsToNull extends \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull
{
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
