<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    protected const DEFAULT_PER_PAGE = 15;

    /**
     * Must clear a full week of timetable slots, which the grid renders in one
     * request.
     */
    protected const MAX_PER_PAGE = 600;

    protected function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', (string) static::DEFAULT_PER_PAGE);

        if ($perPage < 1) {
            return static::DEFAULT_PER_PAGE;
        }

        return min($perPage, static::MAX_PER_PAGE);
    }
}
